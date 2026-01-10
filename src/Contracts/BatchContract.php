<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Contracts;

interface BatchContract
{
    public function getId(): mixed;

    public static function resolve(string $name): ?self;
}
