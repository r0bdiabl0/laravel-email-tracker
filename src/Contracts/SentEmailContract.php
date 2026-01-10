<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Contracts;

use DateTimeInterface;

interface SentEmailContract
{
    public function getId(): mixed;

    public function getMessageId(): string;

    public function setMessageId(string $messageId): self;

    public function setDeliveredAt(DateTimeInterface $time): self;
}
