<?php

namespace Tests\Feature\Classify;

use App\Services\Classify\Consensus;
use Tests\TestCase;

// The majority (2-of-3) voting policy of Consensus::resolve(). Pure reconciliation —
// no DB needed; results are stand-in objects with the columns resolve() reads.
class ConsensusMajorityTest extends TestCase
{
    private function res(?string $code, string $status = 'auto_confirmed'): object
    {
        return (object) ['matched_code' => $code, 'status' => $status, 'catalog_id' => null, 'kind' => 'good'];
    }

    /** @param array<int, object> $results */
    private function resolve(array $results): array
    {
        return app(Consensus::class)->resolve(collect($results));
    }

    public function test_two_of_three_majority_auto_resolves(): void
    {
        $r = $this->resolve([$this->res('9018390000'), $this->res('9018390000'), $this->res('6215200000')]);

        $this->assertSame('agreed', $r['resolution']);
        $this->assertSame('9018390000', $r['final_code']);   // the dissenter is outvoted
    }

    public function test_majority_survives_one_abstention(): void
    {
        $r = $this->resolve([$this->res('9018390000'), $this->res('9018390000'), $this->res(null, 'no_match')]);

        $this->assertSame('agreed', $r['resolution']);
    }

    public function test_all_three_diverge_is_conflict(): void
    {
        $r = $this->resolve([$this->res('1111111111'), $this->res('2222222222'), $this->res('3333333333')]);

        $this->assertSame('conflict', $r['resolution']);
    }

    public function test_lone_code_among_abstentions_is_conflict(): void
    {
        $r = $this->resolve([$this->res('9018390000'), $this->res(null, 'no_match'), $this->res(null, 'error')]);

        $this->assertSame('conflict', $r['resolution']);
    }

    public function test_majority_with_a_non_confident_voter_is_review(): void
    {
        $r = $this->resolve([$this->res('X', 'needs_review'), $this->res('X', 'auto_confirmed'), $this->res('Y')]);

        $this->assertSame('review', $r['resolution']);
    }

    // Two-mechanism behaviour is preserved (majority of 2 = unanimity).
    public function test_two_mechanisms_agreeing_auto_resolve(): void
    {
        $this->assertSame('agreed', $this->resolve([$this->res('X'), $this->res('X')])['resolution']);
    }

    public function test_two_mechanisms_differing_is_conflict(): void
    {
        $this->assertSame('conflict', $this->resolve([$this->res('X'), $this->res('Y')])['resolution']);
    }

    public function test_no_codes_is_no_match(): void
    {
        $this->assertSame('no_match', $this->resolve([$this->res(null, 'no_match'), $this->res(null, 'error')])['resolution']);
    }
}
