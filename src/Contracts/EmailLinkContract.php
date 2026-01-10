<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Contracts;

interface EmailLinkContract
{
    public function getId(): mixed;

    public function originalUrl(): string;

    public function setClicked(bool $clicked): self;

    public function incrementClickCount(): self;
}
