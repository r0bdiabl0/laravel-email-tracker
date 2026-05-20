<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Feature;

use Closure;
use DateTimeInterface;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Contracts\UnsubscribeUrlGenerator;
use R0bdiabl0\EmailTracker\Services\SignedUnsubscribeUrlGenerator;
use R0bdiabl0\EmailTracker\Tests\TestCase;
use R0bdiabl0\EmailTracker\TrackedMailer;
use Symfony\Component\Mime\Header\Headers;

class UnsubscribeUrlGeneratorBindingTest extends TestCase
{
    private function fakeEmail(): SentEmailContract
    {
        return new class implements SentEmailContract
        {
            public function getId(): mixed
            {
                return 1;
            }

            public function getEmail(): string
            {
                return 'user@example.com';
            }

            public function getMessageId(): string
            {
                return 'msg-123';
            }

            public function setMessageId(string $messageId): self
            {
                return $this;
            }

            public function setDeliveredAt(DateTimeInterface $time): self
            {
                return $this;
            }
        };
    }

    private function customGenerator(): UnsubscribeUrlGenerator
    {
        return new class implements UnsubscribeUrlGenerator
        {
            public function generate(SentEmailContract $email): string
            {
                return 'https://example.com/u/custom-token';
            }
        };
    }

    public function test_default_binding_resolves_to_signed_generator(): void
    {
        $this->assertInstanceOf(
            SignedUnsubscribeUrlGenerator::class,
            $this->app->make(UnsubscribeUrlGenerator::class),
        );
    }

    public function test_app_can_override_the_generator_binding(): void
    {
        $this->app->bind(UnsubscribeUrlGenerator::class, fn () => $this->customGenerator());

        $this->assertSame(
            'https://example.com/u/custom-token',
            $this->app->make(UnsubscribeUrlGenerator::class)->generate($this->fakeEmail()),
        );
    }

    public function test_emits_exactly_one_list_unsubscribe_header_with_custom_url(): void
    {
        config()->set('email-tracker.unsubscribe.enabled', true);
        config()->set('email-tracker.unsubscribe.mailto', null);

        $this->app->bind(UnsubscribeUrlGenerator::class, fn () => $this->customGenerator());

        $mailer = $this->app->make(TrackedMailer::class);
        $headers = new Headers;

        // addUnsubscribeHeaders() is protected; invoke it bound to the mailer instance.
        $invoke = Closure::bind(
            function (Headers $headers, SentEmailContract $email) {
                $this->addUnsubscribeHeaders($headers, $email);
            },
            $mailer,
            TrackedMailer::class,
        );
        $invoke($headers, $this->fakeEmail());

        $listUnsubscribe = iterator_to_array($headers->all('list-unsubscribe'), false);
        $this->assertCount(1, $listUnsubscribe);
        $this->assertStringContainsString(
            'https://example.com/u/custom-token',
            $listUnsubscribe[0]->getBodyAsString(),
        );

        $post = iterator_to_array($headers->all('list-unsubscribe-post'), false);
        $this->assertCount(1, $post);
    }
}
