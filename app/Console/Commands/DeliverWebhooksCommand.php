<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Webhook\DeliverWebhooks;
use Illuminate\Console\Command;

final class DeliverWebhooksCommand extends Command
{
    protected $signature = 'webhooks:deliver {--batch=100}';

    protected $description = 'Deliver one batch of due webhooks';

    public function handle(DeliverWebhooks $deliveries): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);

        if ($batch === false || $batch < 1 || $batch > 1000) {
            $this->error('Batch must be between 1 and 1000.');

            return self::INVALID;
        }

        $result = $deliveries->execute($batch);
        $this->info("Processed {$result->processed}; delivered {$result->delivered}; failed {$result->failed}.");

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
