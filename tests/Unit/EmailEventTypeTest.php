<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;

class EmailEventTypeTest extends TestCase
{
    public function test_it_has_correct_values(): void
    {
        $this->assertSame('sent', EmailEventType::Sent->value);
        $this->assertSame('delivered', EmailEventType::Delivered->value);
        $this->assertSame('bounced', EmailEventType::Bounced->value);
        $this->assertSame('complained', EmailEventType::Complained->value);
        $this->assertSame('opened', EmailEventType::Opened->value);
        $this->assertSame('clicked', EmailEventType::Clicked->value);
        $this->assertSame('rejected', EmailEventType::Rejected->value);
    }

    public function test_it_returns_correct_labels(): void
    {
        $this->assertSame('Sent', EmailEventType::Sent->label());
        $this->assertSame('Delivered', EmailEventType::Delivered->label());
        $this->assertSame('Bounced', EmailEventType::Bounced->label());
        $this->assertSame('Complained', EmailEventType::Complained->label());
    }

    public function test_it_identifies_negative_events(): void
    {
        $this->assertTrue(EmailEventType::Bounced->isNegative());
        $this->assertTrue(EmailEventType::Complained->isNegative());
        $this->assertTrue(EmailEventType::Rejected->isNegative());

        $this->assertFalse(EmailEventType::Sent->isNegative());
        $this->assertFalse(EmailEventType::Delivered->isNegative());
        $this->assertFalse(EmailEventType::Opened->isNegative());
        $this->assertFalse(EmailEventType::Clicked->isNegative());
    }
}
