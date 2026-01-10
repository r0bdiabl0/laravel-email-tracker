<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Feature;

use R0bdiabl0\EmailTracker\Facades\EmailTracker;
use R0bdiabl0\EmailTracker\Tests\TestCase;
use R0bdiabl0\EmailTracker\TrackedMailer;

class ServiceProviderTest extends TestCase
{
    public function test_service_provider_is_registered(): void
    {
        $this->assertTrue($this->app->bound('email-tracker'));
    }

    public function test_facade_returns_tracked_mailer(): void
    {
        $mailer = $this->app->make('email-tracker');
        $this->assertInstanceOf(TrackedMailer::class, $mailer);
    }

    public function test_config_is_published(): void
    {
        $this->assertNotNull(config('email-tracker'));
        $this->assertIsArray(config('email-tracker.providers'));
    }

    public function test_default_provider_is_ses(): void
    {
        $this->assertSame('ses', config('email-tracker.default_provider'));
    }

    public function test_ses_provider_is_enabled_by_default(): void
    {
        $this->assertTrue(EmailTracker::isProviderEnabled('ses'));
    }

    public function test_other_providers_are_disabled_by_default(): void
    {
        $this->assertFalse(EmailTracker::isProviderEnabled('resend'));
        $this->assertFalse(EmailTracker::isProviderEnabled('postal'));
        $this->assertFalse(EmailTracker::isProviderEnabled('mailgun'));
    }
}
