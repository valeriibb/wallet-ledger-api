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
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 20);
            $table->string('status', 10)->default('posted');
            $table->uuid('reversal_of')->nullable();
            $table->string('reference')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('reversal_of');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_transactions
            ADD CONSTRAINT ledger_transactions_type_check
            CHECK (type IN ('transfer', 'deposit', 'withdrawal', 'conversion', 'reversal', 'fee'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_transactions
            ADD CONSTRAINT ledger_transactions_status_check
            CHECK (status IN ('posted', 'reversed'))
        SQL);

        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->foreign('reversal_of')
                ->references('id')
                ->on('ledger_transactions')
                ->restrictOnDelete();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained('ledger_transactions')->restrictOnDelete();
            $table->uuid('account_id');
            $table->string('direction', 6);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->bigInteger('balance_after');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('transaction_id');
            $table->index(['account_id', 'created_at', 'id'], 'ledger_entries_account_statement_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_account_currency_foreign
            FOREIGN KEY (account_id, currency)
            REFERENCES accounts (id, currency)
            ON DELETE RESTRICT
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_direction_check
            CHECK (direction IN ('debit', 'credit'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_amount_positive_check
            CHECK (amount > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_currency_check
            CHECK (currency ~ '^[A-Z]{3}$')
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_ledger_entry_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'Ledger entries are immutable; create a reversal instead.';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER ledger_entries_immutable
            BEFORE UPDATE OR DELETE
            ON ledger_entries
            FOR EACH ROW
            EXECUTE FUNCTION prevent_ledger_entry_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_truncate
            BEFORE TRUNCATE
            ON ledger_entries
            FOR EACH STATEMENT
            EXECUTE FUNCTION prevent_ledger_entry_mutation()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_truncate ON ledger_entries');
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_immutable ON ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS prevent_ledger_entry_mutation()');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
    }
};
