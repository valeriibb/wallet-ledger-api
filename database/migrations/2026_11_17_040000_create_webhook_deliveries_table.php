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
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('outbox_events')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->timestampTz('next_retry_at')->useCurrent();
            $table->timestampTz('locked_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->unique(['endpoint_id', 'event_id']);
            $table->index(['status', 'next_retry_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE webhook_deliveries
            ADD CONSTRAINT webhook_deliveries_status_check
            CHECK (status IN ('pending', 'processing', 'delivered', 'dead_letter'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
