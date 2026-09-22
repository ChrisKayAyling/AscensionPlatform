<?php

namespace Ascension\RabbitMQ\Models;

use PhpAmqpLib\Wire\AMQPTable;

class ExchangeParams
{
    public readonly array $params;
    public function __construct(
        public readonly string $exchange,
        public readonly string $type,
        public readonly bool $passive = false,
        public readonly bool $durable = false,
        public readonly bool $auto_delete = true,
        public readonly bool $internal = false,
        public readonly bool $nowait = false,
        public readonly array|AMQPTable $arguments = [],
        public readonly ?int $ticket = null
    ) {
        $this->params = [
            'exchange' => $exchange,
            'type' => $type,
            'passive' => $passive,
            'durable' => $durable,
            'auto_delete' => $auto_delete,
            'internal' => $internal,
            'nowait' => $nowait,
            'arguments' => $arguments,
            'ticket' => $ticket
        ];
    }
}
