<?php

namespace Ascension\RabbitMQ;

class Worker extends Base
{
    /**
     * @var $Repository
     */
    protected $Repository;

    /**
     * @var Exchange
     */
    protected $Exchange;

    /**
     * __construct()
     * @param $Repository
     * @param Exchange $exchange
     */
    public function __construct(
        $Repository,
        Exchange $exchange
    ) {
        parent::__construct();
        $this->Resource = $Repository;
        $this->Exchange = $exchange;
    }

    /**
     * preflightChecks
     *
     * @return false|void
     */
    public function preflightChecks()
    {
        if (!method_exists($this->Repository, 'messageConsumer')) {
            return false;
        }
    }

    /**
     * listen
     * @return void
     */
    public function listen()
    {

        if (!$this->preflightChecks()) {
            error_log("Provided repository does not provide 'messageConsumer' method. Worker will terminate.");
            exit();
        }

        $channel = $this->Resource->channel();

        $channel->exchange_declare($this->Exchange->exchange, $this->Exchange->type, true, false, false);

        $channel->queue_declare(
            $this->Exchange->action . "_" . $this->Exchange->unit . "_queue",
            true,
            true,
            false,
            false
        );

        $channel->queue_bind(
            $this->Exchange->action . "_" . $this->Exchange->unit . "_queue",
            $this->Exchange->exchange,
            $this->Exchange->routeKey
        );

        echo " [*] Waiting for messages. To exit press CTRL+C\n";

        $callback = function ($msg) {
            $this->Repository->messageConsumer($msg->body);
        };

        $channel->basic_consume(
            $this->Exchange->action . "_" . $this->Exchange->unit . "_queue",
            '',
            false,
            false,
            false,
            false,
            $callback
        );

        while ($channel->is_open()) {
            $channel->wait();
        }

        $channel->close();
        $this->Resource->close();
    }
}
