<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;
use R0bdiabl0\EmailTracker\Providers\SesProvider;

class SesProviderTest extends TestCase
{
    private SesProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new SesProvider;
    }

    public function test_it_returns_correct_name(): void
    {
        $this->assertSame('ses', $this->provider->getName());
    }

    public function test_it_parses_bounce_payload(): void
    {
        $payload = [
            'mail' => [
                'messageId' => 'test-message-123',
                'destination' => ['recipient@example.com'],
                'timestamp' => '2024-01-15T10:30:00.000Z',
            ],
            'bounce' => [
                'bounceType' => 'Permanent',
                'bouncedRecipients' => [
                    ['emailAddress' => 'recipient@example.com'],
                ],
            ],
        ];

        $eventData = $this->provider->parsePayload($payload);

        $this->assertSame('test-message-123', $eventData->messageId);
        $this->assertSame('recipient@example.com', $eventData->email);
        $this->assertSame('ses', $eventData->provider);
        $this->assertSame(EmailEventType::Bounced, $eventData->eventType);
        $this->assertSame('Permanent', $eventData->bounceType);
    }

    public function test_it_parses_complaint_payload(): void
    {
        $payload = [
            'mail' => [
                'messageId' => 'test-message-456',
                'destination' => ['complainer@example.com'],
                'timestamp' => '2024-01-15T11:00:00.000Z',
            ],
            'complaint' => [
                'complaintFeedbackType' => 'abuse',
                'complainedRecipients' => [
                    ['emailAddress' => 'complainer@example.com'],
                ],
            ],
        ];

        $eventData = $this->provider->parsePayload($payload);

        $this->assertSame('test-message-456', $eventData->messageId);
        $this->assertSame('complainer@example.com', $eventData->email);
        $this->assertSame('ses', $eventData->provider);
        $this->assertSame(EmailEventType::Complained, $eventData->eventType);
        $this->assertSame('abuse', $eventData->complaintType);
    }

    public function test_it_parses_delivery_payload(): void
    {
        $payload = [
            'mail' => [
                'messageId' => 'test-message-789',
                'destination' => ['delivered@example.com'],
                'timestamp' => '2024-01-15T12:00:00.000Z',
            ],
            'delivery' => [
                'timestamp' => '2024-01-15T12:00:05.000Z',
                'processingTimeMillis' => 5000,
            ],
        ];

        $eventData = $this->provider->parsePayload($payload);

        $this->assertSame('test-message-789', $eventData->messageId);
        $this->assertSame('delivered@example.com', $eventData->email);
        $this->assertSame('ses', $eventData->provider);
        $this->assertSame(EmailEventType::Delivered, $eventData->eventType);
    }
}
