<?php

namespace App\Services\Sources;

use App\Models\FetchLog;
use App\Models\Run;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client wrapper that records every request/response to fetch_logs.
 */
class SourceHttp
{
    private const BROWSER_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    public function __construct(private ?Run $run, private string $source)
    {
    }

    public function get(string $url, array $headers = []): ?Response
    {
        return $this->send('GET', $url, $headers);
    }

    /** Write a progress line into the run log, prefixed with the source key. */
    public function log(string $message): void
    {
        $this->run?->appendLog("[{$this->source}] {$message}");
    }

    public function post(string $url, array $headers = [], mixed $body = null): ?Response
    {
        return $this->send('POST', $url, $headers, $body);
    }

    private function send(string $method, string $url, array $headers, mixed $body = null): ?Response
    {
        $headers = array_merge(['User-Agent' => self::BROWSER_UA], $headers);
        $start = microtime(true);
        $log = [
            'run_id' => $this->run?->id,
            'source' => $this->source,
            'request' => [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ],
        ];

        try {
            $pending = Http::withHeaders($headers)->timeout(30)->connectTimeout(10);
            $response = $method === 'POST'
                ? $pending->withBody(is_string($body) ? $body : json_encode($body), 'application/json')->post($url)
                : $pending->get($url);

            $log['response_status'] = $response->status();
            $log['response_body'] = mb_substr($response->body(), 0, 4 * 1024 * 1024);
            if (! $response->successful()) {
                $log['error'] = 'HTTP ' . $response->status();
            }

            return $response;
        } catch (\Throwable $e) {
            $log['error'] = $e->getMessage();

            return null;
        } finally {
            $log['duration_ms'] = (int) ((microtime(true) - $start) * 1000);
            FetchLog::query()->create($log);
        }
    }
}
