<?php

namespace Tests\Feature\Testing;

use App\Models\ClassificationItem;
use App\Services\Classify\Mechanisms\BrokerDescentMechanism;
use App\Services\Classify\Mechanisms\ClassifierMechanism;
use App\Services\Classify\Mechanisms\DirectLlmMechanism;
use App\Services\Classify\Mechanisms\MechanismResult;
use App\Services\Classify\Mechanisms\VectorMechanism;
use App\Services\Testing\DatasetRowClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DatasetRowClassifierTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['enabled' => ['vector', 'broker', 'direct'], 'shadow' => [], 'cache' => false, 'search' => false];

    public function test_a_throwing_mechanism_abstains_and_cannot_fake_agreement(): void
    {
        $this->bindMechanism(VectorMechanism::class, $this->coded('0901000000'));
        $this->bindMechanism(BrokerDescentMechanism::class, $this->thrower());
        $this->bindMechanism(DirectLlmMechanism::class, $this->thrower());

        $item = $this->item();
        app(DatasetRowClassifier::class)->run($item, self::ALL);

        // Every mechanism keeps a row (2 abstaining) — so the majority denominator is 3.
        $this->assertSame(3, $item->results()->count());
        $this->assertSame(2, $item->results()->where('status', 'error')->count());

        // 1 coded of 3 is NOT a majority (threshold 2). Dropping the two abstentions
        // would leave 1 of 1 and wrongly resolve 'agreed'.
        $this->assertSame('conflict', $item->fresh()->resolution);
    }

    public function test_two_of_three_agree_resolves_at_the_heading(): void
    {
        $this->bindMechanism(VectorMechanism::class, $this->coded('0901000000'));
        $this->bindMechanism(BrokerDescentMechanism::class, $this->coded('0901110000'));
        $this->bindMechanism(DirectLlmMechanism::class, $this->coded('0902000000'));

        $item = $this->item();
        app(DatasetRowClassifier::class)->run($item, self::ALL);

        $this->assertSame('agreed', $item->fresh()->resolution);
        $this->assertSame('0901', $item->fresh()->final_code);
    }

    private function bindMechanism(string $class, ClassifierMechanism $fake): void
    {
        $this->app->bind($class, fn () => $fake);
    }

    private function coded(string $code): ClassifierMechanism
    {
        return new class($code) implements ClassifierMechanism
        {
            public function __construct(private string $code) {}

            public function key(): string
            {
                return 'fake';
            }

            public function classify(string $text): MechanismResult
            {
                return new MechanismResult($this->code, null, 'good', 0.9, 'auto_confirmed');
            }
        };
    }

    private function thrower(): ClassifierMechanism
    {
        return new class implements ClassifierMechanism
        {
            public function key(): string
            {
                return 'fake';
            }

            public function classify(string $text): MechanismResult
            {
                throw new RuntimeException('mechanism blew up');
            }
        };
    }

    private function item(): ClassificationItem
    {
        return ClassificationItem::create([
            'batch' => 'testrun:1', 'source_text' => 'coffee',
            'source_hash' => bin2hex(random_bytes(32)), 'resolution' => 'pending',
        ]);
    }
}
