<?php

namespace Ascension\RabbitMQ\Models;

use PhpAmqpLib\Wire\AMQPTable;

class BindParams
{
    public readonly array $params;
    public function __construct(
        public readonly string $queue,
        public readonly string $exchange,
        public readonly string $routing_key = '',
        public readonly bool $nowait = false,
        public readonly array|AMQPTable $arguments = [],
        public readonly ?int $ticket = null
    ) {
        $this->params = [
            'queue' => $queue,
            'exchange' => $exchange,
            'routing_key' => $routing_key,
            'nowait' => $nowait,
            'arguments' => $arguments,
            'ticket' => $ticket
        ];
    }
}
