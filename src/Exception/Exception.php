<?php

declare(strict_types=1);

/**
 * Copyright (c) 2022 Pavlo Komarov
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/roach-php/roach
 */

namespace RoachPHP\Exception;

use GuzzleHttp\Exception\GuzzleException;
use RoachPHP\Http\Request;
use RoachPHP\Http\Response;

class Exception extends \Exception
{
    private GuzzleException $exception;
    private Request $request;

    public function __construct(Request $request, GuzzleException $exception)
//    public function __construct(Request $request, ?Response $response, string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct('An exception occurred while sending a request', previous: $exception);
        $this->request = $request;
        $this->exception = $exception;
    }

    public function getGuzzleException(): GuzzleException
    {
        return $this->exception;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function setRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
    }
}