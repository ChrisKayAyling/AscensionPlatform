<?php

namespace Ascension;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class RestClient
{
    /**
     * @var GuzzleException $exception
     */
    public static GuzzleException $exception;

    /**
     * @var Client|ClientInterface
     */
    public static $GuzzleClient;

    /**
     * @var \Psr\Http\Message\ResponseInterface
     */
    public static \Psr\Http\Message\ResponseInterface $response;

    /**
     * @param ClientInterface $client
     */
    public function __construct(ClientInterface $client = new Client())
    {
        self::$GuzzleClient = $client;
    }

    /**
     * @throws GuzzleException
     */
    public static function request(
        $url = "",
        $method = "GET",
        $verifySSL = false,
        $headers = array(),
        $data = array()
    ) {
        try {
            self::$response = self::$GuzzleClient->request(
                strtoupper($method),
                $url,
                [
                    'verify' => $verifySSL,
                    'headers' => $headers,
                    'json' => $data
                ]
            );
            return true;
        } catch (GuzzleException $exception) {
            self::$exception = $exception;
            return false;
        }
    }
}
