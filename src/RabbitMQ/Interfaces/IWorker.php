<?php

namespace Ascension\RabbitMQ\Interfaces;

interface IWorker
{
    public function setup(): void;
    public function work(mixed $headers, array $data): void;
}
