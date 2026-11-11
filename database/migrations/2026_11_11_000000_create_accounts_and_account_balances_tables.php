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
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->char('currency', 3);
            $table->string('status', 10)->default('active');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('owner_id');
            $table->unique(['id', 'currency'], 'accounts_id_currency_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE accounts
            ADD CONSTRAINT accounts_type_check
            CHECK (type IN ('user_wallet', 'system_cash', 'system_fees', 'system_fx', 'system_suspense'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE accounts
            ADD CONSTRAINT accounts_owner_type_check
            CHECK ((type = 'user_wallet' AND owner_id IS NOT NULL) OR (type <> 'user_wallet' AND owner_id IS NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE accounts
            ADD CONSTRAINT accounts_currency_check
            CHECK (currency ~ '^[A-Z]{3}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE accounts
            ADD CONSTRAINT accounts_status_check
            CHECK (status IN ('active', 'frozen', 'closed'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX accounts_user_wallet_owner_currency_unique
            ON accounts (owner_id, currency)
            WHERE type = 'user_wallet'
        SQL);

        Schema::create('account_balances', function (Blueprint $table) {
            $table->foreignUuid('account_id')->primary()->constrained('accounts')->cascadeOnDelete();
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('version')->default(0);
            $table->boolean('allow_negative')->default(false);
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE account_balances
            ADD CONSTRAINT account_balances_non_negative_check
            CHECK (balance >= 0 OR allow_negative = true)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE account_balances
            ADD CONSTRAINT account_balances_version_non_negative_check
            CHECK (version >= 0)
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_account_balance_policy()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                account_type text;
            BEGIN
                SELECT type INTO account_type
                FROM accounts
                WHERE id = NEW.account_id;

                IF account_type = 'user_wallet' AND NEW.allow_negative THEN
                    RAISE EXCEPTION 'User wallet accounts cannot allow negative balances.';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER account_balances_enforce_policy
            BEFORE INSERT OR UPDATE OF account_id, balance, allow_negative
            ON account_balances
            FOR EACH ROW
            EXECUTE FUNCTION enforce_account_balance_policy()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS account_balances_enforce_policy ON account_balances');
        DB::statement('DROP FUNCTION IF EXISTS enforce_account_balance_policy()');
        Schema::dropIfExists('account_balances');
        Schema::dropIfExists('accounts');
    }
};
