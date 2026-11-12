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
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('key', 255);
            $table->char('request_hash', 64);
            $table->string('status', 10);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->foreignUuid('transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->timestampTz('locked_until');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('expires_at');

            $table->primary(['owner_id', 'key']);
            $table->index('expires_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_keys
            ADD CONSTRAINT idempotency_keys_status_check
            CHECK (status IN ('processing', 'completed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_keys
            ADD CONSTRAINT idempotency_keys_request_hash_check
            CHECK (request_hash ~ '^[a-f0-9]{64}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_keys
            ADD CONSTRAINT idempotency_keys_completed_response_check
            CHECK (
                (status = 'processing' AND response_code IS NULL AND response_body IS NULL AND transaction_id IS NULL)
                OR
                (status = 'completed' AND response_code IS NOT NULL AND response_body IS NOT NULL AND transaction_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
