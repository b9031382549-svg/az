<?php

namespace Tests\Unit;

use App\Support\AzFold;
use PHPUnit\Framework\TestCase;

class AzFoldTest extends TestCase
{
    public function test_folds_azerbaijani_letters_to_plain_latin(): void
    {
        $this->assertSame('kisi koyneyi', AzFold::fold('kişi köynəyi'));
        $this->assertSame('pambiq', AzFold::fold('Pambıq'));
        $this->assertSame('gunebaxan', AzFold::fold('Günəbaxan'));
        $this->assertSame('isti suse', AzFold::fold('İSTİ ŞÜŞƏ'));
    }

    public function test_a_stripped_invoice_term_folds_to_the_same_as_the_catalog_spelling(): void
    {
        // The whole point: an invoice term typed without special letters folds to
        // the same string as the correct catalog spelling.
        $this->assertSame(AzFold::fold('kisi koynek'), AzFold::fold('kişi köynək'));
    }

    public function test_ascii_is_unchanged_and_lowercased(): void
    {
        $this->assertSame('led lampa e27', AzFold::fold('LED lampa E27'));
    }

    public function test_is_idempotent(): void
    {
        $folded = AzFold::fold('İsidici qrelka — Şpris');
        $this->assertSame($folded, AzFold::fold($folded));
    }
}
