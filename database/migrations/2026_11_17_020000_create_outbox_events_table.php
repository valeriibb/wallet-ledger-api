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
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type', 50);
            $table->uuid('aggregate_id');
            $table->string('event_type', 100);
            $table->jsonb('payload');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->index(['aggregate_type', 'aggregate_id']);
        });

        DB::statement(<<<'SQL'
            CREATE INDEX outbox_events_unpublished_index
            ON outbox_events (occurred_at, id)
            WHERE published_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
