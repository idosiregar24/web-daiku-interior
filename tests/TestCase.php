<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Feature tests assert Inertia props, not bundled assets — without
     * this every new page 500s in tests until `npm run build` has been
     * re-run, coupling the PHP suite to a stale Vite manifest.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
