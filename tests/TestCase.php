<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Every page includes the built CSS through @vite; the suite never builds it.
        $this->withoutVite();
    }
}
