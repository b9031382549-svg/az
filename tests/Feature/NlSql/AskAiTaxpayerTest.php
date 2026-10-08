<?php

namespace Tests\Feature\NlSql;

use App\Livewire\AskAi;
use App\Livewire\Invoices;
use App\Models\ChatMessage;
use App\Models\EInvoice;
use App\Models\User;
use App\Services\NlSql\NlSqlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AskAiTaxpayerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        EInvoice::create(['supplier_tin' => '8273197781', 'supplier_name' => 'Abşeron Qida Təchizat MMC', 'recipient_tin' => '1808172501', 'item_name' => 'Süd', 'total_amount' => 10]);
        EInvoice::create(['supplier_tin' => '8273197781', 'item_name' => 'Çörək', 'total_amount' => 5]);
    }

    /** @param array<string, mixed> $attrs */
    private function turn(array $attrs): ChatMessage
    {
        return ChatMessage::create($attrs + ['user_id' => $this->user->id, 'question' => 'q', 'sql' => 'SELECT 1', 'columns' => [], 'rows' => [], 'truncated' => false]);
    }

    public function test_a_taxpayer_is_chosen_by_vöen_and_shown_with_its_name_and_lines(): void
    {
        Livewire::actingAs($this->user)->test(AskAi::class)
            ->set('tinInput', '0000000000')
            ->call('chooseTin')
            ->assertHasErrors('tinInput')
            ->assertSet('tin', '')
            ->set('tinInput', ' 8273197781 ')
            ->call('chooseTin')
            ->assertSet('tin', '8273197781')
            ->assertSee('Abşeron Qida Təchizat MMC')
            ->assertSee('2 lines as seller · 0 as buyer')
            ->assertSee('What does this taxpayer sell?')
            ->call('clearContext')
            ->assertSet('tin', '');
    }

    public function test_a_link_opens_the_chat_on_a_taxpayer_and_an_unknown_one_is_dropped(): void
    {
        Livewire::withQueryParams(['tin' => '1808172501'])->actingAs($this->user)->test(AskAi::class)->assertSet('tin', '1808172501');
        Livewire::withQueryParams(['tin' => 'NOT_THERE'])->actingAs($this->user)->test(AskAi::class)->assertSet('tin', '');
    }

    public function test_questions_carry_the_taxpayer_and_the_history_keeps_to_its_context(): void
    {
        $this->turn(['question' => 'about the whole data', 'context_tin' => null]);
        $this->turn(['question' => 'about the seller', 'context_tin' => '8273197781']);
        $this->turn(['question' => 'about the buyer', 'context_tin' => '1808172501']);

        $service = Mockery::mock(NlSqlService::class);
        $service->shouldReceive('ask')->once()
            ->withArgs(fn (string $q, array $history, ?string $tin) => $tin === '8273197781'
                && array_column($history, 'q') === ['about the seller'])
            ->andReturn(['question' => 'q', 'sql' => 'SELECT 1', 'answer' => null, 'explanation' => 'x', 'columns' => [], 'rows' => [], 'error' => null]);
        $this->app->instance(NlSqlService::class, $service);

        Livewire::actingAs($this->user)->test(AskAi::class)
            ->call('setContext', '8273197781')
            ->set('question', 'Что он продаёт?')
            ->call('ask')
            ->assertSee('VÖEN 8273197781');

        $this->assertSame('8273197781', ChatMessage::latest('id')->value('context_tin'));
    }

    public function test_a_vöen_in_an_answer_opens_that_taxpayer(): void
    {
        $this->turn(['columns' => ['supplier_tin', 'lines'], 'rows' => [['supplier_tin' => '8273197781', 'lines' => 2]]]);

        Livewire::actingAs($this->user)->test(AskAi::class)
            ->assertSeeHtml("wire:click=\"setContext('8273197781')\"")
            ->call('setContext', '8273197781')
            ->assertSet('tin', '8273197781');
    }

    public function test_the_invoices_page_links_each_vöen_to_the_chat(): void
    {
        Livewire::actingAs($this->user)->test(Invoices::class)
            ->assertSee(route('ask', ['tin' => '8273197781']))
            ->assertSee(route('ask', ['tin' => '1808172501']));
    }
}
