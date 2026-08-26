<?php

declare(strict_types=1);

namespace App\Settings;

use RuntimeException;

final class UnknownSettingException extends RuntimeException
{
    public static function for(string $key): self
    {
        return new self("[{$key}] is not a known setting.");
    }
}
