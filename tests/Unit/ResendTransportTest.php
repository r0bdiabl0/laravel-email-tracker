<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use R0bdiabl0\EmailTracker\Transports\ResendTransport;
use Resend\Client;

class ResendTransportTest extends TestCase
{
    public function test_resend_sdk_classes_exist(): void
    {
        $this->assertTrue(class_exists(Client::class), 'Resend\Client class should exist');
    }

    public function test_transport_can_be_instantiated(): void
    {
        $transport = new ResendTransport('re_test_api_key');

        $this->assertInstanceOf(ResendTransport::class, $transport);
    }

    public function test_transport_string_representation(): void
    {
        $transport = new ResendTransport('re_test_api_key');

        $this->assertSame('resend', (string) $transport);
    }
}
