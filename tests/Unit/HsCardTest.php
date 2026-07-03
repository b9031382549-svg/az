<?php

namespace Tests\Unit;

use App\Models\HsCard;
use Tests\TestCase;

class HsCardTest extends TestCase
{
    public function test_prompt_block_renders_only_present_sections(): void
    {
        $card = new HsCard([
            'scope' => 'Pharmaceutical products',
            'excludes' => [['product_class' => 'syringes, needles', 'reroute_code' => '9018']],
            'closed_list' => ['exhaustive' => true, 'members' => ['catgut', 'dental cements']],
        ]);

        $block = $card->promptBlock('');

        $this->assertStringContainsString('COVERS: Pharmaceutical products', $block);
        $this->assertStringContainsString('EXCLUDES: syringes, needles → see 9018', $block);
        $this->assertStringContainsString('CLOSED LIST', $block);
        $this->assertStringContainsString('catgut; dental cements', $block);
        $this->assertStringNotContainsString('INCLUDES:', $block); // no includes given
    }

    public function test_prompt_block_renders_includes_with_multilingual_synonyms(): void
    {
        $card = new HsCard([
            'includes' => [['product' => 'Syringe', 'syn' => ['şpris', 'шприц', 'syringe']]],
        ]);

        $this->assertStringContainsString('INCLUDES: Syringe (şpris/шприц/syringe)', $card->promptBlock(''));
    }

    public function test_prompt_block_is_empty_when_no_content(): void
    {
        $this->assertSame('', (new HsCard([]))->promptBlock());
    }
}
