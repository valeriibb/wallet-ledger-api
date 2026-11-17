<?php

declare(strict_types=1);

namespace App\Application\Webhook\Exception;

use RuntimeException;

final class WebhookDeliveryNotFound extends RuntimeException {}
