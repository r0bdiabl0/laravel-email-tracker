<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Contracts;

use Closure;
use Symfony\Component\Mime\Email;

interface TrackedMailerInterface
{
    public function initMessage(Email $email): SentEmailContract;

    public function setupTracking(string $body, SentEmailContract $sentEmail): string;

    public function trackingSettings(): array;

    public function getBatchId(): ?int;

    public function getBatch(): ?BatchContract;

    public function setBatch(string $batch): self;

    public function setProvider(string $provider): self;

    public function getProvider(): string;

    public function useInitMessageCallback(Closure $callback): self;

    public function enableAllTracking(): self;

    public function enableOpenTracking(): self;

    public function enableLinkTracking(): self;

    public function enableBounceTracking(): self;

    public function enableComplaintTracking(): self;

    public function enableDeliveryTracking(): self;

    public function disableAllTracking(): self;

    public function disableOpenTracking(): self;

    public function disableLinkTracking(): self;

    public function disableBounceTracking(): self;

    public function disableComplaintTracking(): self;

    public function disableDeliveryTracking(): self;

    public function enableUnsubscribeHeaders(): self;

    public function disableUnsubscribeHeaders(): self;
}
