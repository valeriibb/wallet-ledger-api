<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_transactions
            ADD CONSTRAINT ledger_transactions_reversal_link_check
            CHECK ((type = 'reversal') = (reversal_of IS NOT NULL))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_transactions_reversal_of_unique
            ON ledger_transactions (reversal_of)
            WHERE reversal_of IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ledger_transactions_reversal_of_unique');
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_transactions
            DROP CONSTRAINT IF EXISTS ledger_transactions_reversal_link_check
        SQL);
    }
};
