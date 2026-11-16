<?php

declare(strict_types=1);

namespace Coinsnap\Exception;

use Coinsnap\Http\ResponseInterface;

class RequestException extends CoinsnapException
{
    private $responseBody;

    // The body stays out of the message: error replies can echo submitted customer data.
    public function __construct(string $method, string $url, ResponseInterface $response)
    {
        $this->responseBody = $response->getBody();
        $message = 'Error during ' . $method . ' to ' . $url . '. Got response (' . $response->getStatus() . ').';
        parent::__construct($message, $response->getStatus());
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
