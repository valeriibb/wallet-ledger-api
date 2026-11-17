<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Outbox\RelayOutboxEvents;
use Illuminate\Console\Command;

final class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay {--batch=100 : Maximum events to process}';

    protected $description = 'Publish one batch of pending transactional outbox events';

    public function handle(RelayOutboxEvents $relay): int
    {
        $batchSize = filter_var($this->option('batch'), FILTER_VALIDATE_INT);

        if ($batchSize === false) {
            $this->error('The batch option must be an integer.');

            return self::INVALID;
        }

        $result = $relay->execute($batchSize);
        $this->info("Processed {$result->processed}; published {$result->published}; failed {$result->failed}.");

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
