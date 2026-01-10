<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use R0bdiabl0\EmailTracker\Enums\BounceType;

class BounceTypeTest extends TestCase
{
    public function test_it_has_correct_values(): void
    {
        $this->assertSame('Permanent', BounceType::Permanent->value);
        $this->assertSame('Transient', BounceType::Transient->value);
        $this->assertSame('Undetermined', BounceType::Undetermined->value);
    }

    public function test_it_returns_correct_labels(): void
    {
        $this->assertSame('Permanent (Hard Bounce)', BounceType::Permanent->label());
        $this->assertSame('Transient (Soft Bounce)', BounceType::Transient->label());
        $this->assertSame('Undetermined', BounceType::Undetermined->label());
    }

    public function test_it_identifies_permanent_bounces(): void
    {
        $this->assertTrue(BounceType::Permanent->isPermanent());
        $this->assertFalse(BounceType::Transient->isPermanent());
        $this->assertFalse(BounceType::Undetermined->isPermanent());
    }
}
