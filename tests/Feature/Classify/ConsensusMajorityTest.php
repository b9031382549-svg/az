<?php

namespace Tests\Feature\Classify;

use App\Services\Classify\Consensus;
use Tests\TestCase;

// The auto-resolve rule of Consensus::resolve(): DIRECT commits an answer AND the VECTOR
// corroborates it by carrying that heading in its top-K retrieval shortlist (membership).
// The broker mechanism is DISABLED and is NOT consulted. Pure reconciliation — stand-in
// objects with the columns resolve() reads (mechanism, matched_code, kind, candidates).
class ConsensusMajorityTest extends TestCase
{
    private function direct(?string $code, string $kind = 'good'): object
    {
        return (object) ['mechanism' => 'direct', 'matched_code' => $code, 'kind' => $kind, 'candidates' => [], 'status' => 'auto_confirmed', 'catalog_id' => null];
    }

    /** @param array<int, string> $candidateCodes ordered by embedding proximity */
    private function vector(array $candidateCodes, string $kind = 'good'): object
    {
        return (object) [
            'mechanism' => 'vector',
            'matched_code' => $candidateCodes[0] ?? null,
            'kind' => $kind,
            'candidates' => array_map(fn ($c) => ['code' => $c, 'kind' => $kind], $candidateCodes),
            'status' => 'auto_confirmed',
            'catalog_id' => null,
        ];
    }

    /** @param array<int, object> $results */
    private function resolve(array $results): array
    {
        return app(Consensus::class)->resolve(collect($results));
    }

    public function test_agreed_when_direct_answer_is_in_the_vector_top_k(): void
    {
        // direct says 9018; vector's shortlist carries a 9018 candidate (at position 2).
        $r = $this->resolve([
            $this->direct('9018390000'),
            $this->vector(['6215200000', '9018110000', '3004900000']),
        ]);

        $this->assertSame('agreed', $r['resolution']);
        $this->assertSame('9018', $r['final_code']); // direct's 4-digit heading
        $this->assertNull($r['final_catalog_id']);
    }

    public function test_conflict_when_direct_answer_not_in_vector_top_k(): void
    {
        $r = $this->resolve([
            $this->direct('9018390000'),
            $this->vector(['6215200000', '3004900000', '8471300000']),
        ]);

        $this->assertSame('conflict', $r['resolution']);
        $this->assertNull($r['final_code']);
    }

    public function test_membership_respects_k(): void
    {
        // 9018 sits at position 4 — outside the default top-3 → not corroborated.
        config()->set('classify.vector.membership_k', 3);
        $r = $this->resolve([
            $this->direct('9018390000'),
            $this->vector(['1111111111', '2222222222', '3333333333', '9018000000']),
        ]);

        $this->assertSame('conflict', $r['resolution']);
    }

    public function test_direct_abstention_is_conflict(): void
    {
        $r = $this->resolve([
            $this->direct(null),
            $this->vector(['9018110000']),
        ]);

        $this->assertSame('conflict', $r['resolution']);
    }

    public function test_broker_is_ignored_direct_plus_vector_still_decides(): void
    {
        // A disagreeing broker row is present but NOT consulted: direct + vector still agree.
        $broker = (object) ['mechanism' => 'broker', 'matched_code' => '6215200000', 'kind' => 'good', 'candidates' => [], 'status' => 'auto_confirmed', 'catalog_id' => null];
        $r = $this->resolve([
            $broker,
            $this->direct('9018390000'),
            $this->vector(['9018110000']),
        ]);

        $this->assertSame('agreed', $r['resolution']);
        $this->assertSame('9018', $r['final_code']);
    }

    public function test_services_agree_via_the_flag(): void
    {
        // direct says service ("99"); vector carries a chapter-99 candidate.
        $r = $this->resolve([
            $this->direct('99', 'service'),
            $this->vector(['9949201400', '9981291900'], 'service'),
        ]);

        $this->assertSame('agreed', $r['resolution']);
        $this->assertSame('99', $r['final_code']);
        $this->assertSame('service', $r['kind']);
    }

    public function test_no_codes_anywhere_is_no_match(): void
    {
        $r = $this->resolve([
            $this->direct(null),
            $this->vector([]),
        ]);

        $this->assertSame('no_match', $r['resolution']);
    }

    public function test_only_vector_carries_a_code_is_conflict_not_agreed(): void
    {
        // A lone vector shortlist (direct abstained) must never auto-resolve.
        $r = $this->resolve([
            $this->direct(null),
            $this->vector(['9018390000']),
        ]);

        $this->assertSame('conflict', $r['resolution']);
    }
}
