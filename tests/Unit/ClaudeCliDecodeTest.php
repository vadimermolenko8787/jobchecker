<?php

namespace Tests\Unit;

use App\Services\ClaudeCli;
use PHPUnit\Framework\TestCase;

class ClaudeCliDecodeTest extends TestCase
{
    private ClaudeCli $cli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cli = new ClaudeCli;
    }

    public function test_plain_json_is_decoded(): void
    {
        $this->assertSame([['id' => 1]], $this->cli->decode('[{"id": 1}]'));
    }

    public function test_fenced_json_is_decoded(): void
    {
        $this->assertSame([['id' => 1]], $this->cli->decode("Here you go:\n```json\n[{\"id\": 1}]\n```\n"));
    }

    public function test_json_after_a_preamble_is_decoded(): void
    {
        $this->assertSame(['ok' => true], $this->cli->decode('Result: {"ok": true} — done'));
    }

    public function test_unterminated_fence_is_decoded(): void
    {
        $this->assertSame([['id' => 1]], $this->cli->decode("```json\n[{\"id\": 1}]"));
    }

    public function test_truncated_array_keeps_the_complete_elements(): void
    {
        $text = '```json' . "\n" . '[{"id": 1, "score": 7}, {"id": 2, "score": 8}, {"id": 3, "criteria": {"skills": ';

        $this->assertSame(
            [['id' => 1, 'score' => 7], ['id' => 2, 'score' => 8]],
            $this->cli->decode($text),
        );
    }

    public function test_truncation_inside_a_string_containing_braces_is_handled(): void
    {
        $text = '[{"id": 1, "note": "uses {curly} and [square] brackets"}, {"id": 2, "note": "cut off here';

        $this->assertSame([['id' => 1, 'note' => 'uses {curly} and [square] brackets']], $this->cli->decode($text));
    }

    public function test_truncation_inside_an_escaped_quote_is_handled(): void
    {
        $text = '[{"id": 1, "note": "say \\"hi\\" now"}, {"id": 2, "note": "sti';

        $this->assertSame([['id' => 1, 'note' => 'say "hi" now']], $this->cli->decode($text));
    }

    public function test_answer_without_any_json_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cli->decode('I could not score these vacancies.');
    }

    public function test_answer_truncated_before_the_first_element_closes_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cli->decode('[{"id": 1, "criteria": {"skills": {"matched": ["a"');
    }
}
