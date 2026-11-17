<?php

declare(strict_types=1);

namespace App\Application\Webhook\Port;

interface HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
