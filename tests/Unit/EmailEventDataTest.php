<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;

class EmailEventDataTest extends TestCase
{
    public function test_it_creates_dto_with_required_fields(): void
    {
        $dto = new EmailEventData(
            messageId: 'test-message-id',
            email: 'test@example.com',
            provider: 'ses',
            eventType: EmailEventType::Sent,
        );

        $this->assertSame('test-message-id', $dto->messageId);
        $this->assertSame('test@example.com', $dto->email);
        $this->assertSame('ses', $dto->provider);
        $this->assertSame(EmailEventType::Sent, $dto->eventType);
        $this->assertNull($dto->timestamp);
        $this->assertNull($dto->bounceType);
        $this->assertNull($dto->complaintType);
        $this->assertSame([], $dto->metadata);
    }

    public function test_it_creates_dto_with_all_fields(): void
    {
        $timestamp = Carbon::now();
        $metadata = ['key' => 'value'];

        $dto = new EmailEventData(
            messageId: 'test-message-id',
            email: 'test@example.com',
            provider: 'ses',
            eventType: EmailEventType::Bounced,
            timestamp: $timestamp,
            bounceType: 'Permanent',
            complaintType: null,
            metadata: $metadata,
        );

        $this->assertSame('test-message-id', $dto->messageId);
        $this->assertSame('test@example.com', $dto->email);
        $this->assertSame('ses', $dto->provider);
        $this->assertSame(EmailEventType::Bounced, $dto->eventType);
        $this->assertSame($timestamp, $dto->timestamp);
        $this->assertSame('Permanent', $dto->bounceType);
        $this->assertNull($dto->complaintType);
        $this->assertSame($metadata, $dto->metadata);
    }

    public function test_dto_is_readonly(): void
    {
        $dto = new EmailEventData(
            messageId: 'test-message-id',
            email: 'test@example.com',
            provider: 'ses',
            eventType: EmailEventType::Sent,
        );

        $reflection = new \ReflectionClass($dto);
        $this->assertTrue($reflection->isReadOnly());
    }
}
