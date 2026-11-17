<?php

declare(strict_types=1);

namespace App\Application\Reversal\Exception;

use DomainException;

final class ReversalCannotBeReversed extends DomainException {}
