<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 20);
            $table->jsonb('summary')->default('{}');
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->index(['started_at', 'id']);
        });

        DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_status_check CHECK (status IN ('running', 'clean', 'discrepancies', 'failed'))");

        Schema::create('reconciliation_discrepancies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->string('kind', 50);
            $table->uuid('account_id')->nullable();
            $table->bigInteger('expected');
            $table->bigInteger('actual');
            $table->jsonb('details')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['run_id', 'kind']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_discrepancies');
        Schema::dropIfExists('reconciliation_runs');
    }
};
