<?php

declare(strict_types=1);

namespace App\Application\Webhook\Exception;

use DomainException;

final class InvalidWebhookUrl extends DomainException {}
