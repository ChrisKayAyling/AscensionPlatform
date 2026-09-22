<?php

namespace Ascension\RabbitMQ\Models;

use PhpAmqpLib\Wire\AMQPTable;

class QueueParams
{
    public readonly array $params;
    public function __construct(
        public readonly string $queue,
        public readonly bool $passive = false,
        public readonly bool $durable = false,
        public readonly bool $exclusive = false,
        public readonly bool $auto_delete = true,
        public readonly bool $nowait = false,
        public readonly array|AMQPTable $arguments = [],
        public readonly ?int $ticket = null
    ) {
        $this->params = [
            'queue' => $queue,
            'passive' => $passive,
            'durable' => $durable,
            'exclusive' => $exclusive,
            'auto_delete' => $auto_delete,
            'nowait' => $nowait,
            'arguments' => $arguments,
            'ticket' => $ticket
        ];
    }
}
