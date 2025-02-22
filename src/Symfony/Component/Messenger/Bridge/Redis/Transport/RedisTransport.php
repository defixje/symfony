<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Bridge\Redis\Transport;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @author Alexander Schranz <alexander@sulu.io>
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
class RedisTransport implements TransportInterface, SetupableTransportInterface
{
    private SerializerInterface $serializer;
    private Connection $connection;
    private RedisReceiver $receiver;
    private RedisSender $sender;

    public function __construct(Connection $connection, SerializerInterface $serializer = null)
    {
        $this->connection = $connection;
        $this->serializer = $serializer ?? new PhpSerializer();
    }

    /**
     * {@inheritdoc}
     */
    public function get(): iterable
    {
        return $this->getReceiver()->get();
    }

    /**
     * {@inheritdoc}
     */
    public function ack(Envelope $envelope): void
    {
        $this->getReceiver()->ack($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(Envelope $envelope): void
    {
        $this->getReceiver()->reject($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function send(Envelope $envelope): Envelope
    {
        return $this->getSender()->send($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function setup(): void
    {
        $this->connection->setup();
    }

    private function getReceiver(): RedisReceiver
    {
        return $this->receiver ??= new RedisReceiver($this->connection, $this->serializer);
    }

    private function getSender(): RedisSender
    {
        return $this->sender ??= new RedisSender($this->connection, $this->serializer);
    }
}

if (!class_exists(\Symfony\Component\Messenger\Transport\RedisExt\RedisTransport::class, false)) {
    class_alias(RedisTransport::class, \Symfony\Component\Messenger\Transport\RedisExt\RedisTransport::class);
}
