<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Enums;

enum ComplaintType: string
{
    case Abuse = 'abuse';
    case AuthFailure = 'auth-failure';
    case Fraud = 'fraud';
    case NotSpam = 'not-spam';
    case Other = 'other';
    case Virus = 'virus';

    public function label(): string
    {
        return match ($this) {
            self::Abuse => 'Abuse/Spam',
            self::AuthFailure => 'Authentication Failure',
            self::Fraud => 'Fraud',
            self::NotSpam => 'Not Spam',
            self::Other => 'Other',
            self::Virus => 'Virus',
        };
    }

    public function shouldBlockFutureSends(): bool
    {
        return match ($this) {
            self::Abuse, self::Fraud, self::Virus => true,
            default => false,
        };
    }
}
