<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Enums;

enum BounceType: string
{
    case Permanent = 'Permanent';
    case Transient = 'Transient';
    case Undetermined = 'Undetermined';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent (Hard Bounce)',
            self::Transient => 'Transient (Soft Bounce)',
            self::Undetermined => 'Undetermined',
        };
    }

    public function isPermanent(): bool
    {
        return $this === self::Permanent;
    }

    public function isTransient(): bool
    {
        return $this === self::Transient;
    }

    public function shouldBlockFutureSends(): bool
    {
        return $this === self::Permanent;
    }
}
