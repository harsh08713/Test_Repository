<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chart of accounts: hierarchical groups of unlimited depth (adjacency list on parent_id).
        // `nature` is held on every row (inherited from the root) so reports can filter without walking the tree.
        // `is_system` marks the standard Indian groups seeded for every company: they cannot be deleted or moved.
        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->foreignId('parent_id')->nullable()->constrained('account_groups')->restrictOnDelete();
            $table->string('name');
            $table->string('nature', 12);                 // asset | liability | income | expense
            $table->boolean('is_system')->default(false);
            $table->index(['company_id', 'parent_id']);
        });

        Schema::create('item_groups', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->foreignId('parent_id')->nullable()->constrained('item_groups')->restrictOnDelete();
            $table->string('name');
            $table->index(['company_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_groups');
        Schema::dropIfExists('account_groups');
    }
};
