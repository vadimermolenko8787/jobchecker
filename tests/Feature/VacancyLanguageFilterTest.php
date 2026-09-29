<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\Run;
use App\Models\Setting;
use App\Models\Vacancy;
use App\Services\Pipeline;
use App\Services\VacancyScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VacancyLanguageFilterTest extends TestCase
{
    use RefreshDatabase;

    /** A verdict well above the default threshold of 70, so only the language can reject it. */
    private function verdict(?string $language, int $criterion = 10): array
    {
        $criteria = [];
        foreach (VacancyScorer::CRITERIA as $key) {
            $criteria[$key] = ['matched' => [], 'missing' => [], 'score' => $criterion];
        }

        return [
            'criteria' => $criteria,
            'reason' => 'Strong fit.',
            'summary' => null,
            'language' => $language === null ? null : [
                'vacancy_language' => $language, 'required_languages' => [], 'language_fit' => 'ok', 'note' => null,
            ],
        ];
    }

    /**
     * Scores one vacancy with the given verdicts: the batch verdict first, then any
     * re-check runs. Returns the vacancy as stored and the run.
     *
     * @return array{0: Vacancy, 1: Run}
     */
    private function score(array $batchVerdict, array $recheckVerdicts = [], array $settings = []): array
    {
        $vacancy = Vacancy::create([
            'source' => 'jooble', 'external_id' => 'ext-1', 'title' => 'PHP-Entwickler (m/w/d)',
            'url' => 'https://example.test/1', 'status' => 'new',
        ]);
        $scorer = $this->createPartialMock(VacancyScorer::class, ['scoreBatch', 'scoreSingle']);
        $scorer->method('scoreBatch')->willReturn([$vacancy->id => $batchVerdict]);
        $scorer->method('scoreSingle')->willReturnOnConsecutiveCalls(...($recheckVerdicts ?: [null]));
        $this->instance(VacancyScorer::class, $scorer);

        $run = Run::create(['trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);
        $resume = Resume::create(['original_name' => 'cv.pdf', 'path' => 'cv.pdf', 'text' => 'PHP']);
        $stats = [];
        $method = new \ReflectionMethod(Pipeline::class, 'scoreNew');
        $method->invokeArgs(app(Pipeline::class), [$run, $resume, $settings + Setting::all_settings(), &$stats]);

        return [$vacancy->refresh(), $run->refresh()];
    }

    public function test_a_posting_in_an_unknown_language_is_rejected_despite_a_high_score(): void
    {
        [$vacancy, $run] = $this->score($this->verdict('German'));

        $this->assertSame('rejected', $vacancy->status);
        $this->assertSame(100, $vacancy->score);
        $this->assertSame('German', $vacancy->score_breakdown['foreign_language']);
        $this->assertStringContainsString('Отклонено по языку вакансии: 1', (string) $run->log);
    }

    public function test_a_posting_in_a_known_language_is_matched(): void
    {
        [$vacancy] = $this->score($this->verdict('English'));

        $this->assertSame('matched', $vacancy->status);
        $this->assertArrayNotHasKey('foreign_language', $vacancy->score_breakdown);
    }

    public function test_the_comparison_ignores_case_and_spacing_of_the_setting(): void
    {
        [$vacancy] = $this->score($this->verdict('english'), settings: ['known_languages' => [' English ']]);

        $this->assertSame('matched', $vacancy->status);
    }

    public function test_a_bilingual_posting_passes_when_one_language_is_known(): void
    {
        [$vacancy] = $this->score($this->verdict('German, English'));

        $this->assertSame('matched', $vacancy->status);
    }

    public function test_a_free_text_answer_naming_a_known_language_passes(): void
    {
        [$vacancy] = $this->score($this->verdict('German company, posting written in English'));

        $this->assertSame('matched', $vacancy->status);
    }

    public function test_a_known_language_must_be_a_whole_word(): void
    {
        // A known language that is only a prefix of the answer is no match.
        [$vacancy] = $this->score($this->verdict('Dutch'), settings: ['known_languages' => ['Dutc']]);

        $this->assertSame('rejected', $vacancy->status);
    }

    public function test_an_unreported_language_does_not_reject(): void
    {
        [$vacancy] = $this->score($this->verdict(null));

        $this->assertSame('matched', $vacancy->status);
    }

    public function test_the_list_from_the_settings_decides(): void
    {
        [$vacancy] = $this->score($this->verdict('Polish'), settings: ['known_languages' => ['English', 'Polish']]);

        $this->assertSame('matched', $vacancy->status);
    }

    public function test_a_foreign_posting_near_the_threshold_is_not_rechecked(): void
    {
        // 7/10 on every criterion is exactly the threshold of 70, inside the re-check margin.
        [$vacancy] = $this->score($this->verdict('German', 7));

        $this->assertSame('rejected', $vacancy->status);
        $this->assertArrayNotHasKey('rechecked', $vacancy->score_breakdown);
    }

    public function test_a_recheck_that_settles_on_a_foreign_verdict_rejects(): void
    {
        // Runs of 70, 80, 80: the median is 80, so a German verdict is the one stored.
        [$vacancy] = $this->score(
            $this->verdict('English', 7),
            [$this->verdict('German', 8), $this->verdict('German', 8)],
        );

        $this->assertTrue($vacancy->score_breakdown['rechecked']);
        $this->assertSame('rejected', $vacancy->status);
    }
}
