<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDocument;

abstract class TestCase extends BaseTestCase
{
    /** Roles and permissions are seeded once, in the migrate step, not in every test. */
    protected bool $seed = true;

    protected string $seeder = RolePermissionSeeder::class;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        // A test-only approvable table so the shared foundation can be exercised without any real module.
        $app->make('migrator')->path(__DIR__.'/Support/migrations');
        Relation::morphMap(['test_document' => TestDocument::class]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
