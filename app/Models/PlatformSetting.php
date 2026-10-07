<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One platform-wide key-value setting. A missing row means "use config".
 *
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
class PlatformSetting extends Model
{
    public const REGISTRATION_MODE = 'registration.mode';

    public const REGISTRATION_DAILY_CAP = 'registration.daily_cap';
}
