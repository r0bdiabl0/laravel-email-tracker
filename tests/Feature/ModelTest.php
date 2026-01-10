<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Feature;

use R0bdiabl0\EmailTracker\ModelResolver;
use R0bdiabl0\EmailTracker\Models\Batch;
use R0bdiabl0\EmailTracker\Models\EmailBounce;
use R0bdiabl0\EmailTracker\Models\EmailComplaint;
use R0bdiabl0\EmailTracker\Models\EmailLink;
use R0bdiabl0\EmailTracker\Models\EmailOpen;
use R0bdiabl0\EmailTracker\Models\SentEmail;
use R0bdiabl0\EmailTracker\Tests\TestCase;

class ModelTest extends TestCase
{
    public function test_model_resolver_returns_correct_models(): void
    {
        $this->assertSame(SentEmail::class, ModelResolver::get('sent_email'));
        $this->assertSame(EmailBounce::class, ModelResolver::get('email_bounce'));
        $this->assertSame(EmailComplaint::class, ModelResolver::get('email_complaint'));
        $this->assertSame(EmailOpen::class, ModelResolver::get('email_open'));
        $this->assertSame(EmailLink::class, ModelResolver::get('email_link'));
        $this->assertSame(Batch::class, ModelResolver::get('batch'));
    }

    public function test_sent_email_uses_configurable_table(): void
    {
        $model = new SentEmail;
        $this->assertSame('sent_emails', $model->getTable());
    }

    public function test_sent_email_with_custom_prefix(): void
    {
        config(['email-tracker.table_prefix' => 'tracker']);

        $model = new SentEmail;
        $this->assertSame('tracker_sent_emails', $model->getTable());

        // Reset
        config(['email-tracker.table_prefix' => '']);
    }

    public function test_email_bounce_uses_configurable_table(): void
    {
        $model = new EmailBounce;
        $this->assertSame('email_bounces', $model->getTable());
    }

    public function test_batch_uses_configurable_table(): void
    {
        $model = new Batch;
        $this->assertSame('batches', $model->getTable());
    }
}
