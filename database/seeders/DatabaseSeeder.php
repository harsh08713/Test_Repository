<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Needed everywhere: permissions and the standard roles.
        $this->call(RolePermissionSeeder::class);

        // System data shared by every company (the 15 industry templates), also in production.
        $this->call(IndustryTemplateSeeder::class);

        // Demo companies and one user per role, with a known password: development only.
        if (! app()->isProduction()) {
            $this->call(DemoCompanySeeder::class);
        }
    }
}
