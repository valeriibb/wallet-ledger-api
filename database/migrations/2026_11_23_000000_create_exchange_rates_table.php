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
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('base', 3);
            $table->char('quote', 3);
            $table->decimal('rate', 20, 10);
            $table->string('source', 100);
            $table->timestampTz('valid_from');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['base', 'quote', 'valid_from']);
        });

        DB::statement("ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_pair_check CHECK (base ~ '^[A-Z]{3}$' AND quote ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_positive_check CHECK (rate > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
