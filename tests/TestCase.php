<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Avoid sharing compiled Blade files with the running local server.
        $compiledPath = storage_path('framework/testing-views');
        $this->app['files']->ensureDirectoryExists($compiledPath);
        config(['view.compiled' => $compiledPath]);
    }
}
