<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class ClaudeCli
{
    /**
     * Run `claude -p` with the prompt on stdin and return the result text.
     *
     * @throws \RuntimeException on CLI failure
     */
    public function run(string $prompt, ?int $timeout = null, array $allowedTools = [], ?string $workDir = null): string
    {
        $workDir ??= storage_path('app/claude-work');
        File::ensureDirectoryExists($workDir);

        $command = [
            config('jobchecker.claude_bin'),
            '-p',
            '--output-format', 'json',
            '--model', config('jobchecker.claude_model'),
        ];
        if ($allowedTools !== []) {
            $command[] = '--allowedTools';
            $command[] = implode(',', $allowedTools);
        }

        $process = new Process(
            $command,
            $workDir,
            $this->subprocessEnv(),
            $prompt,
            $timeout ?? config('jobchecker.claude_timeout'),
        );
        $process->run();

        $payload = json_decode($process->getOutput(), true);
        $result = is_array($payload) && isset($payload['result']) ? (string) $payload['result'] : null;

        if ($result !== null && str_contains($result, 'Not logged in')) {
            throw new \RuntimeException(
                'claude CLI не авторизован. Выполните в терминале `claude /login`, '
                . 'либо создайте токен через `claude setup-token` и добавьте его в .env как CLAUDE_CODE_OAUTH_TOKEN.',
            );
        }
        if (! $process->isSuccessful() || $result === null || ! empty($payload['is_error'])) {
            throw new \RuntimeException('claude CLI failed: ' . mb_substr($process->getErrorOutput() ?: $process->getOutput(), 0, 2000));
        }

        return $result;
    }

    /**
     * The CLI reads its OAuth key from the macOS Keychain, which requires
     * HOME/USER to be present — web-server contexts often lack them.
     *
     * @return array<string, string>
     */
    private function subprocessEnv(): array
    {
        $pw = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $home = getenv('HOME') ?: ($pw['dir'] ?? '');
        $user = getenv('USER') ?: ($pw['name'] ?? '');

        $env = array_filter([
            'HOME' => $home,
            'USER' => $user,
            'LOGNAME' => $user,
            'TMPDIR' => sys_get_temp_dir(),
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ]);

        if ($token = config('jobchecker.claude_oauth_token')) {
            $env['CLAUDE_CODE_OAUTH_TOKEN'] = $token;
        }

        return $env;
    }

    /**
     * Run a prompt that must answer with JSON and decode it, tolerating
     * preambles and markdown fences around the JSON.
     */
    public function json(string $prompt, ?int $timeout = null, array $allowedTools = [], ?string $workDir = null): mixed
    {
        return $this->decode($this->run($prompt, $timeout, $allowedTools, $workDir));
    }

    /** @throws \RuntimeException when nothing decodable can be recovered from the answer */
    public function decode(string $text): mixed
    {
        foreach ($this->candidates($text) as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $decoded = json_decode($candidate, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        throw new \RuntimeException('claude did not return valid JSON: ' . mb_substr($text, 0, 500));
    }

    /**
     * Progressively looser readings of the answer, cheapest first.
     *
     * @return iterable<string>
     */
    private function candidates(string $text): iterable
    {
        yield $text;

        // A cut-off answer never emits its closing fence, so it stays optional here.
        if (preg_match('/```(?:json)?\s*(.*?)(?:```|$)/s', $text, $m)) {
            $fenced = trim($m[1]);
            yield $fenced;
            yield $this->closeTruncatedList($fenced);
        }

        $start = min(array_filter([strpos($text, '['), strpos($text, '{')], fn ($p) => $p !== false) ?: [false]);
        $end = max(strrpos($text, ']'), strrpos($text, '}'));
        if ($start !== false && $end !== false && $end > $start) {
            yield substr($text, $start, $end - $start + 1);
        }

        yield $this->closeTruncatedList($text);
    }

    /**
     * Salvage a JSON array that was cut off mid-answer: keep the elements that
     * arrived complete and close the array. Without this a truncated batch reply
     * loses every item in the batch, not just the unfinished one.
     */
    private function closeTruncatedList(string $text): string
    {
        $start = strpos($text, '[');
        if ($start === false) {
            return '';
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $lastComplete = null;

        for ($i = $start, $len = strlen($text); $i < $len; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ']' || $char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
                if ($depth === 1) {
                    $lastComplete = $i;
                }
            }
        }

        return $lastComplete === null ? '' : substr($text, $start, $lastComplete - $start + 1) . ']';
    }
}
