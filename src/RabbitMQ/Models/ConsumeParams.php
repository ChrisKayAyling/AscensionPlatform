<?php

namespace Ascension\RabbitMQ\Models;

use Closure;
use PhpAmqpLib\Wire\AMQPTable;

class ConsumeParams
{
    public readonly array $params;
    public function __construct(
        public readonly string $queue = '',
        public readonly string $consumer_tag = '',
        public readonly bool $no_local = false,
        public readonly bool $no_ack = false,
        public readonly bool $exclusive = false,
        public readonly bool $nowait = false,
        public readonly ?Closure $callback = null,
        public readonly ?int $ticket = null,
        public readonly array|AMQPTable $arguments = []
    ) {
        $this->params = [
            'queue' => $queue,
            'consumer_tag' => $consumer_tag,
            'no_local' => $no_local,
            'no_ack' => $no_ack,
            'exclusive' => $exclusive,
            'nowait' => $nowait,
            'callback' => $callback,
            'ticket' => $ticket,
            'arguments' => $arguments
        ];
    }
}
