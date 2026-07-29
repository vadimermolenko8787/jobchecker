<?php

namespace Tests\Unit;

use App\Services\ClaudeCli;
use App\Services\VacancyScorer;
use PHPUnit\Framework\TestCase;

class VacancyScorerTest extends TestCase
{
    private VacancyScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new VacancyScorer(new ClaudeCli);
    }

    private function criteria(int $skills, int $stack, int $seniority, int $location): array
    {
        return [
            'skills' => ['matched' => [], 'missing' => [], 'score' => $skills],
            'stack' => ['matched' => [], 'missing' => [], 'score' => $stack],
            'seniority' => ['matched' => [], 'missing' => [], 'score' => $seniority],
            'location' => ['matched' => [], 'missing' => [], 'score' => $location],
        ];
    }

    public function test_weighted_score_uses_criterion_shares(): void
    {
        $weights = ['skills' => 40, 'stack' => 25, 'seniority' => 20, 'location' => 15];

        $this->assertSame(100, $this->scorer->computeScore($this->criteria(10, 10, 10, 10), $weights));
        $this->assertSame(0, $this->scorer->computeScore($this->criteria(0, 0, 0, 0), $weights));
        $this->assertSame(65, $this->scorer->computeScore($this->criteria(10, 10, 0, 0), $weights));
        // 9*.4 + 7*.25 + 8*.2 + 8*.15 = 8.15 -> 82
        $this->assertSame(82, $this->scorer->computeScore($this->criteria(9, 7, 8, 8), $weights));
    }

    public function test_scores_are_clamped_to_the_zero_ten_range(): void
    {
        $weights = ['skills' => 1, 'stack' => 0, 'seniority' => 0, 'location' => 0];

        $this->assertSame(100, $this->scorer->computeScore($this->criteria(42, 0, 0, 0), $weights));
        $this->assertSame(0, $this->scorer->computeScore($this->criteria(-5, 0, 0, 0), $weights));
    }

    public function test_zero_weights_fall_back_to_defaults(): void
    {
        $shares = $this->scorer->normalizeWeights(['skills' => 0, 'stack' => 0, 'seniority' => 0, 'location' => 0]);

        $this->assertEqualsWithDelta(0.40, $shares['skills'], 0.001);
        $this->assertEqualsWithDelta(0.15, $shares['location'], 0.001);
    }

    public function test_weights_are_normalized_when_they_do_not_sum_to_hundred(): void
    {
        $shares = $this->scorer->normalizeWeights(['skills' => 1, 'stack' => 1, 'seniority' => 1, 'location' => 1]);

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 0.001);
        $this->assertEqualsWithDelta(0.25, $shares['stack'], 0.001);
    }

    public function test_language_penalty_caps_critical_and_shaves_warning(): void
    {
        $this->assertSame(40, $this->scorer->applyLanguagePenalty(90, 'critical'));
        $this->assertSame(30, $this->scorer->applyLanguagePenalty(30, 'critical'));
        $this->assertSame(75, $this->scorer->applyLanguagePenalty(90, 'warning'));
        $this->assertSame(90, $this->scorer->applyLanguagePenalty(90, 'ok'));
        $this->assertSame(90, $this->scorer->applyLanguagePenalty(90, null));
    }

    public function test_median_of_recheck_runs(): void
    {
        $this->assertSame(70, $this->scorer->median([70, 64, 72]));
        $this->assertSame(64, $this->scorer->median([64]));
        $this->assertSame(68, $this->scorer->median([64, 72]));
        $this->assertSame(0, $this->scorer->median([]));
    }

    public function test_incomplete_criteria_are_rejected(): void
    {
        $this->assertNull($this->scorer->parseCriteria(null));
        $this->assertNull($this->scorer->parseCriteria(['skills' => ['score' => 7]]));
        $this->assertNull($this->scorer->parseCriteria([
            'skills' => ['score' => 7],
            'stack' => ['score' => 8],
            'seniority' => ['score' => 9],
            'location' => ['score' => 'high'],
        ]));
    }

    public function test_criteria_parsing_clamps_and_trims_evidence(): void
    {
        $parsed = $this->scorer->parseCriteria([
            'skills' => ['matched' => ['  PostgreSQL  ', 'a', 'b', 'c', 'd', 'e', 'f'], 'missing' => [null, 'Doctrine'], 'score' => 12.6],
            'stack' => ['score' => 8],
            'seniority' => ['score' => -3],
            'location' => ['score' => 6],
        ]);

        $this->assertSame(10, $parsed['skills']['score']);
        $this->assertSame(0, $parsed['seniority']['score']);
        $this->assertSame('PostgreSQL', $parsed['skills']['matched'][0]);
        $this->assertCount(5, $parsed['skills']['matched']);
        $this->assertSame(['Doctrine'], $parsed['skills']['missing']);
        $this->assertSame([], $parsed['stack']['matched']);
    }

    public function test_answer_without_reason_falls_back_to_the_rubric_line(): void
    {
        $parsed = $this->scorer->parseAnswer([
            'id' => 1,
            'criteria' => $this->criteria(9, 7, 8, 8),
            'language' => ['vacancy_language' => 'Ukrainian', 'language_fit' => 'ok', 'required_languages' => ['English']],
        ]);

        $this->assertSame('skills 9/10, stack 7/10, seniority 8/10, location 8/10', $parsed['reason']);
        $this->assertNull($parsed['summary']);
        $this->assertSame('ok', $parsed['language']['language_fit']);
    }

    public function test_answer_with_broken_rubric_is_null(): void
    {
        $this->assertNull($this->scorer->parseAnswer(['id' => 1, 'reason' => 'looks fine']));
        $this->assertNull($this->scorer->parseAnswer('not an array'));
    }

    public function test_weights_helper_fills_missing_keys_and_drops_extras(): void
    {
        $weights = VacancyScorer::weights(['score_weights' => ['skills' => 50, 'bogus' => 99]]);

        $this->assertSame(['skills' => 50, 'stack' => 25, 'seniority' => 20, 'location' => 15], $weights);
        $this->assertSame(['skills' => 40, 'stack' => 25, 'seniority' => 20, 'location' => 15], VacancyScorer::weights([]));
    }
}
