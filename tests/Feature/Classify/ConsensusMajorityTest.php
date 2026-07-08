<?php

namespace Tests\Feature\Classify;

use App\Services\Classify\Consensus;
use Tests\TestCase;

// The 2-of-3 voting policy of Consensus::resolve(), measured on the 4-digit HEADING
// (first 4 chars of the code). Pure reconciliation — no DB needed; results are
// stand-in objects with the columns resolve() reads.
class ConsensusMajorityTest extends TestCase
{
    private function res(?string $code): object
    {
        return (object) ['matched_code' => $code, 'status' => 'auto_confirmed', 'catalog_id' => null, 'kind' => 'good'];
    }

    /** @param array<int, object> $results */
    private function resolve(array $results): array
    {
        return app(Consensus::class)->resolve(collect($results));
    }

    public function test_two_of_three_sharing_a_heading_resolves_to_that_heading(): void
    {
        $r = $this->resolve([$this->res('9018390000'), $this->res('9018390000'), $this->res('6215200000')]);

        $this->assertSame('agreed', $r['resolution']);
        $this->assertSame('9018', $r['final_code']);   // the 4-digit heading, not the full code
        $this->assertNull($r['final_catalog_id']);
    }

    public function test_agreement_is_on_the_heading_even_when_full_codes_differ(): void
    {
        // Same 4-digit heading (9018), different deeper digits — still a heading agreement.
        $r = $this->resolve([$this->res('9018390000'), $this->res('9018901000'), $this->res('6215200000')]);

        $this->assertSame('agreed', $r['resolution']);
        $this->assertSame('9018', $r['final_code']);
    }

    public function test_majority_survives_one_abstention(): void
    {
        $r = $this->resolve([$this->res('9018390000'), $this->res('9018110000'), $this->res(null)]);

        $this->assertSame('agreed', $r['resolution']);
        $this->assertSame('9018', $r['final_code']);
    }

    public function test_three_different_headings_is_conflict(): void
    {
        $r = $this->resolve([$this->res('1111111111'), $this->res('2222222222'), $this->res('3333333333')]);

        $this->assertSame('conflict', $r['resolution']);
        $this->assertNull($r['final_code']);
    }

    public function test_lone_code_among_abstentions_is_conflict(): void
    {
        $r = $this->resolve([$this->res('9018390000'), $this->res(null), $this->res(null)]);

        $this->assertSame('conflict', $r['resolution']);
    }

    // Two-mechanism behaviour: a shared heading is enough (2-of-2).
    public function test_two_mechanisms_sharing_a_heading_resolve(): void
    {
        $this->assertSame('agreed', $this->resolve([$this->res('1005900000'), $this->res('1005100000')])['resolution']);
    }

    public function test_two_mechanisms_on_different_headings_is_conflict(): void
    {
        $this->assertSame('conflict', $this->resolve([$this->res('1005900000'), $this->res('1006100000')])['resolution']);
    }

    public function test_no_codes_is_no_match(): void
    {
        $this->assertSame('no_match', $this->resolve([$this->res(null), $this->res(null)])['resolution']);
    }
}
