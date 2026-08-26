<?php

declare(strict_types=1);

namespace App\Settings;

enum SettingType: string
{
    case String = 'string';
    case Boolean = 'boolean';
    case Integer = 'integer';
}
