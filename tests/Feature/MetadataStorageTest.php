<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Feature;

use R0bdiabl0\EmailTracker\Models\EmailBounce;
use R0bdiabl0\EmailTracker\Models\EmailComplaint;
use R0bdiabl0\EmailTracker\Models\SentEmail;
use R0bdiabl0\EmailTracker\Tests\TestCase;

class MetadataStorageTest extends TestCase
{
    public function test_email_bounce_stores_metadata_as_array(): void
    {
        $sentEmail = SentEmail::create([
            'message_id' => 'test-message-123',
            'email' => 'test@example.com',
            'provider' => 'ses',
            'bounce_tracking' => true,
        ]);

        $metadata = [
            'bounce' => [
                'bounceType' => 'Permanent',
                'bouncedRecipients' => [
                    [
                        'emailAddress' => 'test@example.com',
                        'diagnosticCode' => 'smtp; 550 5.1.1 The email account does not exist',
                    ],
                ],
            ],
            'mail' => [
                'messageId' => 'test-message-123',
            ],
        ];

        $bounce = EmailBounce::create([
            'provider' => 'ses',
            'sent_email_id' => $sentEmail->id,
            'type' => 'Permanent',
            'email' => 'test@example.com',
            'bounced_at' => now(),
            'metadata' => $metadata,
        ]);

        // Verify the metadata is stored
        $this->assertNotNull($bounce->metadata);
        $this->assertIsArray($bounce->metadata);

        // Verify we can access nested data
        $this->assertSame('Permanent', $bounce->metadata['bounce']['bounceType']);
        $this->assertSame(
            'smtp; 550 5.1.1 The email account does not exist',
            $bounce->metadata['bounce']['bouncedRecipients'][0]['diagnosticCode']
        );

        // Verify it persists to database and can be retrieved
        $retrievedBounce = EmailBounce::find($bounce->id);
        $this->assertIsArray($retrievedBounce->metadata);
        $this->assertSame('Permanent', $retrievedBounce->metadata['bounce']['bounceType']);
    }

    public function test_email_complaint_stores_metadata_as_array(): void
    {
        $sentEmail = SentEmail::create([
            'message_id' => 'test-message-456',
            'email' => 'complainer@example.com',
            'provider' => 'ses',
            'complaint_tracking' => true,
        ]);

        $metadata = [
            'complaint' => [
                'complaintFeedbackType' => 'abuse',
                'complainedRecipients' => [
                    ['emailAddress' => 'complainer@example.com'],
                ],
            ],
            'mail' => [
                'messageId' => 'test-message-456',
            ],
        ];

        $complaint = EmailComplaint::create([
            'provider' => 'ses',
            'sent_email_id' => $sentEmail->id,
            'type' => 'abuse',
            'email' => 'complainer@example.com',
            'complained_at' => now(),
            'metadata' => $metadata,
        ]);

        // Verify the metadata is stored
        $this->assertNotNull($complaint->metadata);
        $this->assertIsArray($complaint->metadata);

        // Verify we can access nested data
        $this->assertSame('abuse', $complaint->metadata['complaint']['complaintFeedbackType']);

        // Verify it persists to database
        $retrievedComplaint = EmailComplaint::find($complaint->id);
        $this->assertIsArray($retrievedComplaint->metadata);
        $this->assertSame('abuse', $retrievedComplaint->metadata['complaint']['complaintFeedbackType']);
    }

    public function test_email_bounce_handles_null_metadata(): void
    {
        $sentEmail = SentEmail::create([
            'message_id' => 'test-message-789',
            'email' => 'test@example.com',
            'provider' => 'ses',
            'bounce_tracking' => true,
        ]);

        $bounce = EmailBounce::create([
            'provider' => 'ses',
            'sent_email_id' => $sentEmail->id,
            'type' => 'Permanent',
            'email' => 'test@example.com',
            'bounced_at' => now(),
            'metadata' => null,
        ]);

        $this->assertNull($bounce->metadata);

        // Verify it persists correctly
        $retrievedBounce = EmailBounce::find($bounce->id);
        $this->assertNull($retrievedBounce->metadata);
    }

    public function test_email_bounce_handles_empty_array_metadata(): void
    {
        $sentEmail = SentEmail::create([
            'message_id' => 'test-message-empty',
            'email' => 'test@example.com',
            'provider' => 'resend',
            'bounce_tracking' => true,
        ]);

        $bounce = EmailBounce::create([
            'provider' => 'resend',
            'sent_email_id' => $sentEmail->id,
            'type' => 'Permanent',
            'email' => 'test@example.com',
            'bounced_at' => now(),
            'metadata' => [],
        ]);

        // Empty array should be stored as empty array (cast handles this)
        $retrievedBounce = EmailBounce::find($bounce->id);
        $this->assertIsArray($retrievedBounce->metadata);
        $this->assertEmpty($retrievedBounce->metadata);
    }

    public function test_metadata_stores_provider_specific_data(): void
    {
        // Test that different provider payloads are stored correctly
        $sentEmail = SentEmail::create([
            'message_id' => 'resend-message-123',
            'email' => 'test@example.com',
            'provider' => 'resend',
            'bounce_tracking' => true,
        ]);

        // Resend-style payload
        $resendMetadata = [
            'type' => 'email.bounced',
            'data' => [
                'email_id' => 'resend-message-123',
                'to' => ['test@example.com'],
                'created_at' => '2024-01-15T10:30:00.000Z',
            ],
        ];

        $bounce = EmailBounce::create([
            'provider' => 'resend',
            'sent_email_id' => $sentEmail->id,
            'type' => 'Permanent',
            'email' => 'test@example.com',
            'bounced_at' => now(),
            'metadata' => $resendMetadata,
        ]);

        $retrievedBounce = EmailBounce::find($bounce->id);
        $this->assertSame('email.bounced', $retrievedBounce->metadata['type']);
        $this->assertSame(['test@example.com'], $retrievedBounce->metadata['data']['to']);
    }

    public function test_metadata_stores_large_payload(): void
    {
        $sentEmail = SentEmail::create([
            'message_id' => 'large-message-123',
            'email' => 'test@example.com',
            'provider' => 'ses',
            'bounce_tracking' => true,
        ]);

        // Simulate a large SES payload with multiple recipients and full mail object
        $largeMetadata = [
            'mail' => [
                'messageId' => 'large-message-123',
                'timestamp' => '2024-01-15T10:30:00.000Z',
                'source' => 'sender@example.com',
                'destination' => ['test@example.com'],
                'headers' => [
                    ['name' => 'From', 'value' => 'sender@example.com'],
                    ['name' => 'To', 'value' => 'test@example.com'],
                    ['name' => 'Subject', 'value' => 'Test Email Subject'],
                    ['name' => 'Message-ID', 'value' => '<large-message-123@mail.example.com>'],
                ],
                'commonHeaders' => [
                    'from' => ['sender@example.com'],
                    'to' => ['test@example.com'],
                    'subject' => 'Test Email Subject',
                ],
            ],
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
                'bouncedRecipients' => [
                    [
                        'emailAddress' => 'test@example.com',
                        'action' => 'failed',
                        'status' => '5.1.1',
                        'diagnosticCode' => 'smtp; 550 5.1.1 The email account that you tried to reach does not exist. Please try double-checking the recipient\'s email address for typos or unnecessary spaces.',
                    ],
                ],
                'timestamp' => '2024-01-15T10:30:05.000Z',
                'reportingMTA' => 'dns; example-smtp-out.amazonses.com',
            ],
        ];

        $bounce = EmailBounce::create([
            'provider' => 'ses',
            'sent_email_id' => $sentEmail->id,
            'type' => 'Permanent',
            'email' => 'test@example.com',
            'bounced_at' => now(),
            'metadata' => $largeMetadata,
        ]);

        // Verify the large payload is stored and retrieved correctly
        $retrievedBounce = EmailBounce::find($bounce->id);
        $this->assertIsArray($retrievedBounce->metadata);
        $this->assertSame('5.1.1', $retrievedBounce->metadata['bounce']['bouncedRecipients'][0]['status']);
        $this->assertCount(4, $retrievedBounce->metadata['mail']['headers']);
    }
}
