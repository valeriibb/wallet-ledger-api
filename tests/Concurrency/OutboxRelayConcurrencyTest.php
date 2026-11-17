<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Application\Outbox\Port\OutboxRelayStore;
use App\Application\Outbox\Port\OutboxWriter;
use App\Application\Outbox\RelayOutboxEvents;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FileRecordingEventPublisher;
use Tests\Support\ParallelProcessRunner;
use Tests\TestCase;
use Throwable;

final class OutboxRelayConcurrencyTest extends TestCase
{
    #[Test]
    public function concurrent_relays_skip_locked_events_without_duplicate_publication(): void
    {
        DB::table('outbox_events')->delete();
        $writer = app(OutboxWriter::class);

        for ($number = 1; $number <= 50; $number++) {
            $writer->append('test', $this->uuidFor($number), 'test.created', ['number' => $number]);
        }

        $path = sys_get_temp_dir().'/wallet-outbox-'.bin2hex(random_bytes(8)).'.log';
        $results = (new ParallelProcessRunner)->run(
            range(1, 10),
            10,
            static function (int $_job) use ($path): string {
                try {
                    $relay = new RelayOutboxEvents(
                        app(OutboxRelayStore::class),
                        new FileRecordingEventPublisher($path),
                    );
                    $result = $relay->execute(10);

                    return 'published:'.$result->published;
                } catch (Throwable $exception) {
                    return 'error:'.$exception::class.':'.$exception->getMessage();
                }
            },
        );

        $errors = array_values(array_filter(
            $results,
            static fn (string $result): bool => str_starts_with($result, 'error:')
                || str_starts_with($result, 'worker_error:'),
        ));
        $published = array_sum(array_map(
            static fn (string $result): int => str_starts_with($result, 'published:')
                ? (int) substr($result, 10)
                : 0,
            $results,
        ));
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        @unlink($path);

        self::assertSame([], $errors);
        self::assertSame(50, $published);
        self::assertIsArray($lines);
        self::assertCount(50, $lines);
        self::assertCount(50, array_unique($lines));
        self::assertSame(50, DB::table('outbox_events')->whereNotNull('published_at')->count());
        self::assertSame(0, DB::table('outbox_events')->whereNull('published_at')->count());
    }

    private function uuidFor(int $number): string
    {
        return sprintf('00000000-0000-7000-8000-%012d', $number);
    }
}
