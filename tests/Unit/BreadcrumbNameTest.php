<?php

namespace Tests\Unit;

use App\Support\BreadcrumbName;
use PHPUnit\Framework\TestCase;

class BreadcrumbNameTest extends TestCase
{
    public function test_short_names_are_returned_in_full(): void
    {
        $name = 'Tibbdə istifadə edilən cihazlar:– şprislər';
        $this->assertSame($name, BreadcrumbName::fit($name, 900));
    }

    public function test_long_names_keep_the_distinguishing_tail(): void
    {
        // Two siblings that share a long head and differ only in the tail — the
        // real failure mode: a head-truncation would make them identical.
        $head = str_repeat('Tibbi cihazlar və aparatlar, ', 20); // ~580 chars, shared
        $a = $head.':– – plastik şprislər';
        $b = $head.':– – metal iynələr';

        $fa = BreadcrumbName::fit($a, 200);
        $fb = BreadcrumbName::fit($b, 200);

        // Bounded, elided, and — crucially — still tell the two apart.
        $this->assertLessThanOrEqual(200, mb_strlen($fa));
        $this->assertStringContainsString('…', $fa);
        $this->assertStringEndsWith('plastik şprislər', $fa);
        $this->assertStringEndsWith('metal iynələr', $fb);
        $this->assertNotSame($fa, $fb);
    }

    public function test_keeps_both_head_and_tail(): void
    {
        $name = str_repeat('A', 100).'MIDDLE'.str_repeat('B', 100).'TAILMARK';
        $fit = BreadcrumbName::fit($name, 60);

        $this->assertStringStartsWith('A', $fit);
        $this->assertStringEndsWith('TAILMARK', $fit);
        $this->assertStringContainsString('…', $fit);
    }
}
