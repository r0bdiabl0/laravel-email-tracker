<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Postal\Client;
use Postal\Send\Message as SendMessage;
use R0bdiabl0\EmailTracker\Transports\PostalTransport;

class PostalTransportTest extends TestCase
{
    public function test_postal_sdk_classes_exist(): void
    {
        $this->assertTrue(class_exists(Client::class), 'Postal\Client class should exist');
        $this->assertTrue(class_exists(SendMessage::class), 'Postal\Send\Message class should exist');
    }

    public function test_transport_can_be_instantiated(): void
    {
        $transport = new PostalTransport('https://postal.example.com', 'test-api-key');

        $this->assertInstanceOf(PostalTransport::class, $transport);
    }

    public function test_transport_string_representation(): void
    {
        $transport = new PostalTransport('https://postal.example.com', 'test-api-key');

        $this->assertSame('postal', (string) $transport);
    }
}
