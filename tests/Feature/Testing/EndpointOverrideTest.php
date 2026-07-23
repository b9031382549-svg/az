<?php

namespace Tests\Feature\Testing;

use App\Models\TestRun;
use App\Services\Testing\EndpointOverride;
use Tests\TestCase;

class EndpointOverrideTest extends TestCase
{
    public function test_apply_routes_decision_stages_to_the_endpoint_then_restore_reverts(): void
    {
        config([
            'classify.direct.model' => 'openai/gpt-oss-120b',
            'classify.direct.granularity' => 'code',
            'classify.broker.model' => 'deepseek/deepseek-chat',
            'classify.broker.answer_granularity' => 'code',
            'classify.expand_model' => 'deepseek/deepseek-chat',
            'services.openrouter.classify_model' => 'deepseek/deepseek-chat',
            'services.nebius.base_url' => 'https://api.tokenfactory.nebius.com/v1',
            'services.nebius.api_key' => 'orig-key',
        ]);

        // Bare model ids → the "nebius:" provider prefix is added automatically.
        // Decision on the fine-tuned model, expand on base (both at the endpoint).
        $run = new TestRun([
            'model_override' => 'xif',
            'expand_model_override' => 'base',
            'endpoint_base_url' => 'http://10.0.0.1:8000/v1',
            'endpoint_api_key' => 'sk-vmtest',
        ]);

        $prior = EndpointOverride::apply($run);

        $this->assertSame('nebius:xif', config('classify.direct.model'));
        $this->assertSame('nebius:xif', config('classify.broker.model'));
        $this->assertSame('nebius:xif', config('services.openrouter.classify_model'));
        $this->assertSame('nebius:base', config('classify.expand_model'));          // separate expand model
        $this->assertSame('heading', config('classify.direct.granularity'));       // forced for the FT model
        $this->assertSame('heading', config('classify.broker.answer_granularity'));
        $this->assertSame('http://10.0.0.1:8000/v1', config('services.nebius.base_url'));
        $this->assertSame('sk-vmtest', config('services.nebius.api_key'));

        EndpointOverride::restore($prior);

        $this->assertSame('openai/gpt-oss-120b', config('classify.direct.model'));
        $this->assertSame('code', config('classify.direct.granularity'));
        $this->assertSame('deepseek/deepseek-chat', config('classify.broker.model'));
        $this->assertSame('deepseek/deepseek-chat', config('classify.expand_model'));
        $this->assertSame('https://api.tokenfactory.nebius.com/v1', config('services.nebius.base_url'));
        $this->assertSame('orig-key', config('services.nebius.api_key'));
    }

    public function test_expand_stays_on_prod_when_no_expand_model_given(): void
    {
        config(['classify.expand_model' => 'deepseek/deepseek-chat']);

        // Decision routed to the endpoint, but no expand model → expand untouched.
        $run = new TestRun(['model_override' => 'xif', 'endpoint_base_url' => 'http://10.0.0.1:8000/v1']);
        EndpointOverride::apply($run);

        $this->assertSame('nebius:xif', config('classify.direct.model'));
        $this->assertSame('deepseek/deepseek-chat', config('classify.expand_model')); // unchanged
    }

    public function test_no_override_is_a_noop(): void
    {
        config(['classify.direct.model' => 'openai/gpt-oss-120b']);

        $run = new TestRun(['model_override' => null]);
        $prior = EndpointOverride::apply($run);

        $this->assertSame([], $prior);
        $this->assertSame('openai/gpt-oss-120b', config('classify.direct.model'));
    }

    public function test_already_prefixed_model_is_left_as_is(): void
    {
        config(['services.nebius.base_url' => 'https://api.tokenfactory.nebius.com/v1']);

        $run = new TestRun(['model_override' => 'nebius:base', 'endpoint_base_url' => 'http://10.0.0.2:8000/v1']);
        EndpointOverride::apply($run);

        $this->assertSame('nebius:base', config('classify.direct.model'));
        $this->assertSame('http://10.0.0.2:8000/v1', config('services.nebius.base_url'));
    }
}
