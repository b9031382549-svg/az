<?php

namespace Tests\Feature\Classify;

use App\Services\Classify\ClassifierService;
use App\Services\Classify\Mechanisms\VectorMechanism;
use App\Services\Classify\ProductBriefService;
use Mockery;
use Tests\TestCase;

// Pins the pure adapter that maps ClassifierService::classify()'s array onto a
// MechanismResult — behaviour that must survive every later phase.
class VectorMechanismTest extends TestCase
{
    public function test_maps_classifier_result_to_mechanism_result(): void
    {
        $svc = Mockery::mock(ClassifierService::class);
        $svc->shouldReceive('classify')->once()->with('Şpris', 'disposable syringe')->andReturn([
            'code' => '9018311000', 'catalog_id' => 42, 'kind' => 'good',
            'confidence' => 0.9, 'status' => 'auto_confirmed',
            'candidates' => [['code' => '9018311000']], 'reason' => 'match',
            'usage' => ['total_tokens' => 100], 'tier' => 2, 'escalated' => true,
        ]);
        $briefs = Mockery::mock(ProductBriefService::class);
        $briefs->shouldReceive('brief')->with('Şpris')->andReturn(['identity' => 'disposable syringe']);

        $m = new VectorMechanism($svc, $briefs);
        $r = $m->classify('Şpris');

        $this->assertSame('vector', $m->key());
        $this->assertSame('9018311000', $r->matchedCode);
        $this->assertSame(42, $r->catalogId);
        $this->assertSame('good', $r->kind);
        $this->assertSame(0.9, $r->confidence);
        $this->assertSame('auto_confirmed', $r->status);
        $this->assertSame(2, $r->tier);
        $this->assertSame(config('services.openrouter.classify_model'), $r->model);
        $this->assertSame('match', $r->explanation);
        $this->assertSame('9018311000', $r->toRow()['matched_code']);
    }

    public function test_null_code_yields_no_model_and_passthrough_status(): void
    {
        $svc = Mockery::mock(ClassifierService::class);
        $svc->shouldReceive('classify')->andReturn([
            'code' => null, 'catalog_id' => null, 'kind' => null,
            'confidence' => null, 'status' => 'no_match', 'candidates' => [],
            'reason' => 'No confident match', 'usage' => [], 'tier' => 1,
        ]);
        $briefs = Mockery::mock(ProductBriefService::class);
        $briefs->shouldReceive('brief')->andReturn(null); // no brief → identity null

        $r = (new VectorMechanism($svc, $briefs))->classify('xyz');

        $this->assertNull($r->matchedCode);
        $this->assertNull($r->model);
        $this->assertSame('no_match', $r->status);
        $this->assertNull($r->toRow()['usage']);
    }
}
