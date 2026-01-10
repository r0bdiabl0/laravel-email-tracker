<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Enums;

enum EmailEventType: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Bounced => 'Bounced',
            self::Complained => 'Complained',
            self::Opened => 'Opened',
            self::Clicked => 'Clicked',
            self::Rejected => 'Rejected',
        };
    }

    public function isNegative(): bool
    {
        return match ($this) {
            self::Bounced, self::Complained, self::Rejected => true,
            default => false,
        };
    }
}
