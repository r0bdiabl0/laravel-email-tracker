<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker;

use Exception;
use R0bdiabl0\EmailTracker\Contracts\BatchContract;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Contracts\TrackedMailerInterface;

trait TrackingTrait
{
    protected bool $openTracking = false;

    protected bool $linkTracking = false;

    protected bool $bounceTracking = false;

    protected bool $complaintTracking = false;

    protected bool $deliveryTracking = false;

    protected ?BatchContract $batch = null;

    /**
     * Process email body to add tracking elements.
     *
     * @throws Exception
     */
    public function setupTracking(string $body, SentEmailContract $sentEmail): string
    {
        $this->batch = null;

        $mailProcessor = new MailProcessor($sentEmail, $body);

        if ($this->openTracking) {
            $mailProcessor->openTracking();
        }

        if ($this->linkTracking) {
            $mailProcessor->linkTracking();
        }

        return $mailProcessor->getEmailBody();
    }

    /**
     * Set the batch for email grouping.
     */
    public function setBatch(string $batch): TrackedMailerInterface
    {
        $this->batch = ModelResolver::get('batch')::firstOrCreate(['name' => $batch]);

        return $this;
    }

    public function getBatch(): ?BatchContract
    {
        return $this->batch;
    }

    public function getBatchId(): ?int
    {
        return $this->batch?->getId();
    }

    public function enableOpenTracking(): TrackedMailerInterface
    {
        $this->openTracking = true;

        return $this;
    }

    public function enableLinkTracking(): TrackedMailerInterface
    {
        $this->linkTracking = true;

        return $this;
    }

    public function enableBounceTracking(): TrackedMailerInterface
    {
        $this->bounceTracking = true;

        return $this;
    }

    public function enableComplaintTracking(): TrackedMailerInterface
    {
        $this->complaintTracking = true;

        return $this;
    }

    public function enableDeliveryTracking(): TrackedMailerInterface
    {
        $this->deliveryTracking = true;

        return $this;
    }

    public function disableOpenTracking(): TrackedMailerInterface
    {
        $this->openTracking = false;

        return $this;
    }

    public function disableLinkTracking(): TrackedMailerInterface
    {
        $this->linkTracking = false;

        return $this;
    }

    public function disableBounceTracking(): TrackedMailerInterface
    {
        $this->bounceTracking = false;

        return $this;
    }

    public function disableComplaintTracking(): TrackedMailerInterface
    {
        $this->complaintTracking = false;

        return $this;
    }

    public function disableDeliveryTracking(): TrackedMailerInterface
    {
        $this->deliveryTracking = false;

        return $this;
    }

    public function enableAllTracking(): TrackedMailerInterface
    {
        return $this->enableOpenTracking()
            ->enableLinkTracking()
            ->enableBounceTracking()
            ->enableComplaintTracking()
            ->enableDeliveryTracking();
    }

    public function disableAllTracking(): TrackedMailerInterface
    {
        return $this->disableOpenTracking()
            ->disableLinkTracking()
            ->disableBounceTracking()
            ->disableComplaintTracking()
            ->disableDeliveryTracking();
    }

    public function trackingSettings(): array
    {
        return [
            'openTracking' => $this->openTracking,
            'linkTracking' => $this->linkTracking,
            'bounceTracking' => $this->bounceTracking,
            'complaintTracking' => $this->complaintTracking,
            'deliveryTracking' => $this->deliveryTracking,
        ];
    }
}
