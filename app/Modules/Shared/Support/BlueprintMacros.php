<?php

namespace App\Modules\Shared\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;

/**
 * Schema helpers so every master/transaction table gets the same columns.
 *
 *   $table->masterColumns();        // company_id, code, status, approval_*, remarks, audit cols, soft deletes
 *   $table->locationColumn();       // location_id (branch) FK
 *   $table->amount('net_amount');   // decimal(15,2)
 *   $table->quantity('qty');        // decimal(15,3)
 */
final class BlueprintMacros
{
    public static function register(): void
    {
        Blueprint::macro('amount', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 15, 2)->default(0);
        });

        Blueprint::macro('quantity', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 15, 3)->default(0);
        });

        Blueprint::macro('locationColumn', function (bool $nullable = false): ForeignKeyDefinition {
            /** @var Blueprint $this */
            $column = $this->foreignId('location_id');
            if ($nullable) {
                $column->nullable();
            }

            return $column->constrained('locations')->restrictOnDelete();
        });

        Blueprint::macro('masterColumns', function (bool $withCompany = true, bool $withCode = true, bool $withApproval = true, bool $withStatus = true): void {
            /** @var Blueprint $this */
            if ($withCompany) {
                $this->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            }
            if ($withCode) {
                $this->string('code', 30);
            }

            if ($withStatus) {
                $this->string('status', 16)->default('active')->index();
            }

            if ($withApproval) {
                $this->string('approval_status', 16)->default('draft')->index();
                $this->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $this->timestamp('approved_at')->nullable();
            }

            $this->text('remarks')->nullable();
            $this->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $this->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $this->timestamps();
            $this->softDeletes();

            if ($withCompany && $withCode) {
                $this->unique(['company_id', 'code']);
            }
        });
    }
}
