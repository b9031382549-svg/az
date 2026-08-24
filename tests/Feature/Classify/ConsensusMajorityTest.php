<?php

namespace Tests\Feature\Classify;

use App\Services\Classify\Consensus;
use Tests\TestCase;

// The auto-resolve rule of Consensus::resolve(): the two GENERATIVE mechanisms (broker,
// direct) must commit the SAME answer (4-digit heading, or both services), AND the vector
// must CORROBORATE it by carrying that heading in its top-K retrieval candidates. Anything
// short of that is a conflict. Pure reconciliation — stand-in objects with the columns
// resolve() reads (mechanism, matched_code, kind, candidates).
class ConsensusMajorityTest extends TestCase
{
    private function broker(?string $code, string $kind = 'good'): object
    {
        return (object) ['mechanism' => 'broker', 'matched_code' => $code, 'kind' => $kind, 'candidates' => [], 'status' => 'auto_confirmed', 'catalog_id' => null];
    }

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

    public function test_agreed_when_broker_and_direct_match_and_vector_top_k_carries_it(): void
    {
        // broker == direct at heading 9018; vector's shortlist carries a 9018 candidate.
        $r = $this->resolve([
            $this->broker('9018390000'),
            $this->direct('9018901000'),
            $this->vector(['6215200000', '9018110000', '3004900000']),
        ]);

        $this->assertSame('agreed', $r['resolution']);
        $this->assertSame('9018', $r['final_code']); // the 4-digit heading, not the full code
        $this->assertNull($r['final_catalog_id']);
    }

    public function test_broker_and_direct_agree_but_vector_top_k_does_not_carry_it_is_conflict(): void
    {
        // broker == direct at 9018, but no 9018 candidate in the vector shortlist.
        $r = $this->resolve([
            $this->broker('9018390000'),
            $this->direct('9018110000'),
            $this->vector(['6215200000', '3004900000', '8471300000']),
        ]);

        $this->assertSame('conflict', $r['resolution']);
        $this->assertNull($r['final_code']);
    }

    public function test_broker_and_direct_disagree_is_conflict_even_if_both_in_vector(): void
    {
        $r = $this->resolve([
            $this->broker('9018390000'),
            $this->direct('6215200000'),
            $this->vector(['9018110000', '6215200000']),
        ]);

        $this->assertSame('conflict', $r['resolution']);
        $this->assertNull($r['final_code']);
    }

    public function test_membership_respects_k(): void
    {
        // The 9018 candidate sits at position 6 — outside the default top-5 → not corroborated.
        config()->set('classify.vector.membership_k', 5);
        $r = $this->resolve([
            $this->broker('9018390000'),
            $this->direct('9018110000'),
            $this->vector(['1111111111', '2222222222', '3333333333', '4444444444', '5555555555', '9018000000']),
        ]);

        $this->assertSame('conflict', $r['resolution']);
    }

    public function test_broker_abstention_is_conflict(): void
    {
        $r = $this->resolve([
            $this->broker(null),
            $this->direct('9018110000'),
            $this->vector(['9018110000']),
        ]);

        $this->assertSame('conflict', $r['resolution']);
    }

    public function test_services_agree_via_the_flag(): void
    {
        // broker + direct both services ("99"); vector carries a chapter-99 candidate.
        $r = $this->resolve([
            $this->broker('99', 'service'),
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
            $this->broker(null),
            $this->direct(null),
            $this->vector([]),
        ]);

        $this->assertSame('no_match', $r['resolution']);
    }

    public function test_only_vector_carries_a_code_is_conflict_not_agreed(): void
    {
        // A lone vector shortlist (no generative agreement) must never auto-resolve.
        $r = $this->resolve([
            $this->broker(null),
            $this->direct(null),
            $this->vector(['9018390000']),
        ]);

        $this->assertSame('conflict', $r['resolution']);
    }
}
