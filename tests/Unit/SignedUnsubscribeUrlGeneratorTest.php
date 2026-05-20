<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Unit;

use DateTimeInterface;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Services\SignedUnsubscribeUrlGenerator;
use R0bdiabl0\EmailTracker\Tests\TestCase;

class SignedUnsubscribeUrlGeneratorTest extends TestCase
{
    private function fakeEmail(string $address, string $messageId): SentEmailContract
    {
        return new class($address, $messageId) implements SentEmailContract
        {
            public function __construct(private string $address, private string $messageId) {}

            public function getId(): mixed
            {
                return 1;
            }

            public function getEmail(): string
            {
                return $this->address;
            }

            public function getMessageId(): string
            {
                return $this->messageId;
            }

            public function setMessageId(string $messageId): self
            {
                $this->messageId = $messageId;

                return $this;
            }

            public function setDeliveredAt(DateTimeInterface $time): self
            {
                return $this;
            }
        };
    }

    public function test_generates_non_expiring_signed_url_by_default(): void
    {
        config()->set('email-tracker.unsubscribe.signature_expiration', 0);

        $url = (new SignedUnsubscribeUrlGenerator)->generate(
            $this->fakeEmail('user@example.com', 'msg-123'),
        );

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('expires=', $url);
    }

    public function test_generates_temporary_signed_url_when_expiration_set(): void
    {
        config()->set('email-tracker.unsubscribe.signature_expiration', 24);

        $url = (new SignedUnsubscribeUrlGenerator)->generate(
            $this->fakeEmail('user@example.com', 'msg-123'),
        );

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }
}
