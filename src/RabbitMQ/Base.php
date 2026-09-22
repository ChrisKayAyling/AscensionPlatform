<?php

namespace Ascension\RabbitMQ;

use Ascension\Core;
use Ascension\RabbitMQ\Interfaces\IWorker;
use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Ascension\RabbitMQ\Models\BindParams;
use Ascension\RabbitMQ\Models\ConsumeParams;
use Ascension\RabbitMQ\Models\ExchangeParams;
use Ascension\RabbitMQ\Models\QueueParams;
use Ascension\RabbitMQ\Interfaces;

class Base
{
    /**
     * @var AMQPStreamConnection
     */
    public AMQPStreamConnection $connection;

    /**
     * @var AMQPChannel
     */
    public AMQPChannel $channel;

    /**
     * @var ExchangeParams
     */
    protected ExchangeParams $exchangeParams;

    /**
     * @var QueueParams
     */
    protected QueueParams $queueParams;

    /**
     * @var BindParams
     */
    protected BindParams $bindParams;

    /**
     * @var ConsumeParams
     */
    protected ConsumeParams $consumeParams;

    /**
     * @var AMQPTable
     */
    protected AMQPTable $bindErrorArgs;

    /**
     * @var QueueParams
     */
    protected QueueParams $errorQueueParams;

    /**
     * @var BindParams
     */
    protected BindParams $errorBindParams;

    public function __construct()
    {
        if ($this instanceof IWorker) {
            $this->setup();
        } else {
            $classname = get_class();
            throw new Exception("Method 'setup' mey not defined in $classname. Have you implemented the 'IWorker' interface?");
        }

        $this->connection = new AMQPStreamConnection(
            Core::$Resources['Settings']->RabbitMQ->Hostname,
            Core::$Resources['Settings']->RabbitMQ->Port,
            Core::$Resources['Settings']->RabbitMQ->Username,
            Core::$Resources['Settings']->RabbitMQ->Password
        );

        $this->channel = $this->connection->channel();
        $this->run();
    }


    /**
     * Creates a queue to hold messages in error state
     *
     * @return void
     * @throws Exception
     */
    protected function createErrorQueue(): void
    {
        if (!property_exists($this, 'exchangeParams')) {
            throw new Exception('Class ' . get_class($this) . ' must define $exchangeParams');
        }

        $this->connect($this->exchangeParams, $this->errorQueueParams, $this->errorBindParams);
    }

    /**
     * run
     * @return void
     */
    protected function run(): void
    {
        try {
            $this->createErrorQueue();
            $this->connect($this->exchangeParams, $this->queueParams, $this->bindParams);
        } catch (AMQPConnectionClosedException $e) {
        } catch (AMQPProtocolChannelException $e) {
            echo $e->getMessage();
            die();
        }

        $this->consume($this->consumeParams);

        $this->disconnect();
    }

    /**
     * @param ExchangeParams $exchangeParams
     * @param QueueParams $queueParams
     * @param BindParams $bindParams
     * @return void
     */
    protected function connect(ExchangeParams $exchangeParams, QueueParams $queueParams, BindParams $bindParams): void
    {
        $this->channel->exchange_declare(...$exchangeParams->params);
        $this->channel->queue_declare(...$queueParams->params);
        $this->channel->queue_bind(...$bindParams->params);

        $this->channel->basic_qos(0, 1, null);
    }

    /**
     * Closes the channel and connection.
     *
     * @return void
     */
    protected function disconnect(): void
    {
        try {
            $this->channel->close();
            $this->connection->close();
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    public function queueTask($exchangeName, $bindingName, $messageData, ?AMQPTable $header): void
    {
        try {
            $defaultHeaders = array(
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            );
            if (!is_null($header)) {
                $defaultHeaders['application_headers'] = $header;
            }

            $msg = new AMQPMessage(
                json_encode($messageData),
                $defaultHeaders
            );

            $this->channel->basic_publish($msg, $exchangeName, $bindingName);
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    /**
     * Waits for messages. Consumes messages using the callback function defined within child worker classes.
     *
     * @param \Workers\Models\ConsumeParams $args
     * @return void
     */
    protected function consume(ConsumeParams $args): void
    {
        $this->channel->basic_consume(...$args->params);

        while ($this->channel->is_open()) {
            $this->channel->wait();
        }
    }
    /**
     * Decodes message headers and data. Calls work function to perform work.
     *
     * @return Closure
     */
    protected function process(): Closure
    {
        echo " [*] Waiting for messages. To exit press CTRL+C\n";

        return function (AMQPMessage $msg) {
            $applicationHeaders = $msg->get('application_headers');
            $headers = $applicationHeaders->getNativeData();

            if (empty($headers)) {
                throw new Exception("No headers have been set on the message");
            }

            $data = json_decode($msg->body, true);

            if ($this instanceof IWorker) {
                $this->work($headers, $data);
            } else {
                $classname = get_called_class();
                throw new Exception("Method 'work' mey not defined in $classname. Have you implemented the 'IWorker' interface?");
            }

            $msg->ack();
        };
    }
}
