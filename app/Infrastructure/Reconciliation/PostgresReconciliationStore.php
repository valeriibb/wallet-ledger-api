<?php

declare(strict_types=1);

namespace App\Infrastructure\Reconciliation;

use App\Application\Reconciliation\Port\ReconciliationStore;
use App\Application\Reconciliation\ReconciliationResult;
use App\Application\Reconciliation\ReconciliationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

final class PostgresReconciliationStore implements ReconciliationStore
{
    public function run(int $staleOutboxMinutes): ReconciliationResult
    {
        $runId = (string) Str::uuid7();
        $ownsTransaction = DB::connection()->transactionLevel() === 0;
        DB::table('reconciliation_runs')->insert(['id' => $runId, 'status' => 'running']);

        try {
            $result = DB::transaction(function () use ($runId, $staleOutboxMinutes, $ownsTransaction): ReconciliationResult {
                if ($ownsTransaction) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }

                $discrepancies = [
                    ...$this->currencyImbalances(),
                    ...$this->accountBalanceMismatches(),
                    ...$this->balanceSequenceMismatches(),
                    ...$this->transactionImbalances(),
                    ...$this->staleOutboxEvents($staleOutboxMinutes),
                ];
                $counts = [];

                foreach ($discrepancies as $discrepancy) {
                    $kind = $discrepancy['kind'];
                    $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                    DB::table('reconciliation_discrepancies')->insert([
                        'id' => (string) Str::uuid7(),
                        'run_id' => $runId,
                        ...$discrepancy,
                        'details' => json_encode($discrepancy['details'], JSON_THROW_ON_ERROR),
                    ]);
                }

                ksort($counts);
                $count = count($discrepancies);
                $status = $count === 0 ? 'clean' : 'discrepancies';
                $summary = [
                    'discrepancy_count' => $count,
                    'counts_by_kind' => $counts,
                    'stale_outbox_minutes' => $staleOutboxMinutes,
                ];
                DB::table('reconciliation_runs')->where('id', $runId)->update([
                    'status' => $status,
                    'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                    'finished_at' => now(),
                ]);

                return new ReconciliationResult($runId, $status, $count, $counts);
            });
        } catch (Throwable $exception) {
            DB::table('reconciliation_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'summary' => json_encode(['error' => 'Reconciliation execution failed.'], JSON_THROW_ON_ERROR),
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        Log::log($result->discrepancyCount === 0 ? 'info' : 'warning', 'reconciliation.completed', [
            'run_id' => $result->runId,
            'reconciliation_discrepancies' => $result->discrepancyCount,
            'counts_by_kind' => $result->countsByKind,
        ]);

        return $result;
    }

    public function latest(int $limit): array
    {
        $runs = DB::table('reconciliation_runs')->orderByDesc('started_at')->orderByDesc('id')->limit($limit)->get();

        $result = $runs->map(function (object $run): ReconciliationRun {
            $data = (array) $run;
            $id = $this->string($data['id'] ?? null);
            $summary = $this->jsonObject($data['summary'] ?? null);
            /** @var list<array{id: string, kind: string, account_id: ?string, expected: int, actual: int, details: array<string, mixed>}> $discrepancies */
            $discrepancies = DB::table('reconciliation_discrepancies')
                ->where('run_id', $id)
                ->orderBy('kind')
                ->orderBy('id')
                ->get()
                ->map(function (object $row): array {
                    $item = (array) $row;

                    return [
                        'id' => $this->string($item['id'] ?? null),
                        'kind' => $this->string($item['kind'] ?? null),
                        'account_id' => is_string($item['account_id'] ?? null) ? $item['account_id'] : null,
                        'expected' => $this->integer($item['expected'] ?? null),
                        'actual' => $this->integer($item['actual'] ?? null),
                        'details' => $this->jsonObject($item['details'] ?? null),
                    ];
                })->values()->all();

            return new ReconciliationRun(
                $id,
                $this->string($data['status'] ?? null),
                $summary,
                $this->string($data['started_at'] ?? null),
                is_string($data['finished_at'] ?? null) ? $data['finished_at'] : null,
                $discrepancies,
            );
        })->values()->all();

        /** @var list<ReconciliationRun> $result */
        return $result;
    }

    /** @return list<array{kind: string, account_id: ?string, expected: int, actual: int, details: array<string, mixed>}> */
    private function currencyImbalances(): array
    {
        /** @var list<object{currency: string, net: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT currency, SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END)::bigint AS net
            FROM ledger_entries GROUP BY currency
            HAVING SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) <> 0
        SQL);

        return array_map(fn (object $row): array => $this->discrepancy(
            'currency_imbalance', null, 0, $this->integer($row->net), ['currency' => $this->string($row->currency)],
        ), $rows);
    }

    /** @return list<array{kind: string, account_id: ?string, expected: int, actual: int, details: array<string, mixed>}> */
    private function accountBalanceMismatches(): array
    {
        /** @var list<object{account_id: string, expected: int|string, actual: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT b.account_id, COALESCE(SUM(CASE WHEN e.direction = 'credit' THEN e.amount ELSE -e.amount END), 0)::bigint AS expected,
                   b.balance::bigint AS actual
            FROM account_balances b LEFT JOIN ledger_entries e ON e.account_id = b.account_id
            GROUP BY b.account_id, b.balance
            HAVING COALESCE(SUM(CASE WHEN e.direction = 'credit' THEN e.amount ELSE -e.amount END), 0) <> b.balance
        SQL);

        return array_map(fn (object $row): array => $this->discrepancy(
            'account_balance_mismatch', $this->string($row->account_id), $this->integer($row->expected), $this->integer($row->actual), [],
        ), $rows);
    }

    /** @return list<array{kind: string, account_id: ?string, expected: int, actual: int, details: array<string, mixed>}> */
    private function balanceSequenceMismatches(): array
    {
        /** @var list<object{account_id: string, entry_id: string, expected: int|string, actual: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT account_id, entry_id, expected, actual FROM (
                SELECT account_id, id AS entry_id, balance_after::bigint AS actual,
                       SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END)
                           OVER (PARTITION BY account_id ORDER BY created_at, id)::bigint AS expected
                FROM ledger_entries
            ) sequence WHERE expected <> actual
        SQL);

        return array_map(fn (object $row): array => $this->discrepancy(
            'balance_sequence_mismatch', $this->string($row->account_id), $this->integer($row->expected), $this->integer($row->actual), ['entry_id' => $this->string($row->entry_id)],
        ), $rows);
    }

    /** @return list<array{kind: string, account_id: ?string, expected: int, actual: int, details: array<string, mixed>}> */
    private function transactionImbalances(): array
    {
        /** @var list<object{transaction_id: string, currency: string, net: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT transaction_id, currency, SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END)::bigint AS net
            FROM ledger_entries GROUP BY transaction_id, currency
            HAVING SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) <> 0
        SQL);

        return array_map(fn (object $row): array => $this->discrepancy(
            'transaction_imbalance', null, 0, $this->integer($row->net), ['transaction_id' => $this->string($row->transaction_id), 'currency' => $this->string($row->currency)],
        ), $rows);
    }

    /** @return list<array{kind: string, account_id: ?string, expected: int, actual: int, details: array<string, mixed>}> */
    private function staleOutboxEvents(int $minutes): array
    {
        /** @var list<object{id: string, event_type: string, age_seconds: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT id, event_type, EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - occurred_at))::bigint AS age_seconds
            FROM outbox_events
            WHERE published_at IS NULL AND occurred_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 minute')
        SQL, [$minutes]);

        return array_map(fn (object $row): array => $this->discrepancy(
            'stale_outbox_event', null, 0, $this->integer($row->age_seconds), ['event_id' => $this->string($row->id), 'event_type' => $this->string($row->event_type)],
        ), $rows);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{kind: string, account_id: ?string, expected: int, actual: int, details: array<string, mixed>}
     */
    private function discrepancy(string $kind, ?string $accountId, int $expected, int $actual, array $details): array
    {
        return [
            'kind' => $kind,
            'account_id' => $accountId,
            'expected' => $expected,
            'actual' => $actual,
            'details' => $details,
        ];
    }

    private function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('The database returned invalid reconciliation text.');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new RuntimeException('The database returned an invalid reconciliation integer.');
        }

        return $integer;
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value): array
    {
        if (! is_string($value)) {
            throw new RuntimeException('The database returned invalid reconciliation JSON.');
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The database returned invalid reconciliation JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('The reconciliation JSON must be an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
