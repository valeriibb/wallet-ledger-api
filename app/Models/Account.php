<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $owner_id
 * @property string $type
 */
final class Account extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
