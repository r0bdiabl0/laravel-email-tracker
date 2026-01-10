<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\DataTransferObjects;

use Carbon\Carbon;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;

final readonly class EmailEventData
{
    public function __construct(
        public string $messageId,
        public string $email,
        public string $provider,
        public EmailEventType $eventType,
        public ?Carbon $timestamp = null,
        public ?string $bounceType = null,
        public ?string $complaintType = null,
        public ?string $diagnosticCode = null,
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            messageId: $data['message_id'] ?? $data['messageId'] ?? '',
            email: $data['email'] ?? '',
            provider: $data['provider'] ?? 'ses',
            eventType: is_string($data['event_type'] ?? null)
                ? EmailEventType::from($data['event_type'])
                : ($data['event_type'] ?? EmailEventType::Sent),
            timestamp: isset($data['timestamp'])
                ? Carbon::parse($data['timestamp'])
                : null,
            bounceType: $data['bounce_type'] ?? $data['bounceType'] ?? null,
            complaintType: $data['complaint_type'] ?? $data['complaintType'] ?? null,
            diagnosticCode: $data['diagnostic_code'] ?? $data['diagnosticCode'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'email' => $this->email,
            'provider' => $this->provider,
            'event_type' => $this->eventType->value,
            'timestamp' => $this->timestamp?->toIso8601String(),
            'bounce_type' => $this->bounceType,
            'complaint_type' => $this->complaintType,
            'diagnostic_code' => $this->diagnosticCode,
            'metadata' => $this->metadata,
        ];
    }
}
