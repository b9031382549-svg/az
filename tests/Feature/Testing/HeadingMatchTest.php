<?php

namespace Tests\Feature\Testing;

use App\Services\Classify\HeadingMatch;
use Tests\TestCase;

class HeadingMatchTest extends TestCase
{
    public function test_heading_takes_the_first_four_digits(): void
    {
        $this->assertSame('0901', HeadingMatch::heading('0901123456'));
        $this->assertSame('8471', HeadingMatch::heading('8471'));
        $this->assertNull(HeadingMatch::heading(null));
        $this->assertNull(HeadingMatch::heading(''));
    }

    public function test_service_detection_matches_the_harness_semantics(): void
    {
        $this->assertTrue(HeadingMatch::isService('service', null));
        $this->assertTrue(HeadingMatch::isService('99', null));
        $this->assertTrue(HeadingMatch::isService(null, '9901000000'));
        $this->assertFalse(HeadingMatch::isService('good', '0901000000'));
        $this->assertFalse(HeadingMatch::isService(null, null));
    }

    public function test_correct_scores_goods_on_heading_and_services_on_flag(): void
    {
        // goods: heading must match, and it must not read as a service
        $this->assertTrue(HeadingMatch::correct('0901123456', 'good', '0901', false));
        $this->assertFalse(HeadingMatch::correct('0902123456', 'good', '0901', false));
        $this->assertFalse(HeadingMatch::correct(null, 'good', '0901', false));

        // services: only the flag matters
        $this->assertTrue(HeadingMatch::correct('9901', 'service', null, true));
        $this->assertTrue(HeadingMatch::correct(null, 'service', null, true));
        $this->assertFalse(HeadingMatch::correct('0901123456', 'good', null, true)); // a good is not a service
    }
}
