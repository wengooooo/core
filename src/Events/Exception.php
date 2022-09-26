<?php

namespace RoachPHP\Events;

use GuzzleHttp\Exception\GuzzleException;
use RoachPHP\Http\Request;
use Symfony\Contracts\EventDispatcher\Event;

final class Exception extends Event
{
    public const NAME = 'exception.retry';

    public function __construct(public Request $request, public GuzzleException $reason)
    {
    }
}