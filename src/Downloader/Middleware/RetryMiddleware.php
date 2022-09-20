<?php

declare(strict_types=1);

namespace RoachPHP\Downloader\Middleware;

use DateTime;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Promise;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RoachPHP\Events\RequestDropped;
use RoachPHP\Events\RequestScheduling;
use RoachPHP\Exception\Exception;
use RoachPHP\Http\Request;
use RoachPHP\Http\Response;
use RoachPHP\Scheduling\RequestSchedulerInterface;
use RoachPHP\Support\Configurable;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use GuzzleHttp\Exception\GuzzleException;

class RetryMiddleware implements ExceptionMiddlewareInterface, RequestMiddlewareInterface, ResponseMiddlewareInterface
{
    use Configurable;

    private RequestSchedulerInterface $requestScheduler;
    private EventDispatcherInterface $eventDispatcher;

    // HTTP date format
    public const DATE_FORMAT = 'D, d M Y H:i:s T';

    // Default retry header (off by default; configurable)
    public const RETRY_HEADER = 'X-Retry-Counter';

    // Default retry-after header
    public const RETRY_AFTER_HEADER = 'Retry-After';

    public array $options;

    public function __construct(RequestSchedulerInterface $requestScheduler, EventDispatcherInterface $eventDispatcher)
    {
        $this->requestScheduler = $requestScheduler;
        $this->eventDispatcher = $eventDispatcher;
        $this->options = $this->defaultOptions();
    }

    public function handleRequest(Request $request): Request
    {
        return $request;
    }

    public function handleResponse(Response $response): Response
    {
        if($this->option('on_retry_response_callback') && $this->option('on_retry_response_callback')($response) && $this->countRemainingRetries($response->getRequest()) > 0) {
            $this->doRetry($response->getRequest(), $response);
        }

        return $response;
    }

    public function handleException(Exception $exception): Exception
    {

        $reason = $exception->getGuzzleException();
        $request = $exception->getRequest();
        var_dump($reason->getMessage());
        var_dump($reason instanceof RequestException);
        var_dump(get_class($reason));

        if ($reason instanceof BadResponseException) {
            $response = new Response($reason->getResponse(), $request);

            if ($this->shouldRetryHttpResponse($request, $response)) {
                $this->doRetry($request, $response);
            }
            // If this was a connection exception, test to see if we should retry based on connect timeout rules
        } elseif ($reason instanceof ConnectException || $reason instanceof RequestException) {
            // If was another type of exception, test if we should retry based on timeout rules
            var_dump($this->shouldRetryConnectException($request));
            if ($this->shouldRetryConnectException($request)) {
                $this->doRetry($request);
            }
        }
        return $exception;
    }


    /**
     * Retry the request
     *
     * Increments the retry count, determines the delay (timeout), executes callbacks, sleeps, and re-sends the request
     *
     * @param RequestInterface $request
     * @param array<string,mixed> $options
     * @param ResponseInterface|null $response
     * @return Promise
     */
    protected function doRetry(Request $request, Response $response = null) {
        // Increment the retry count
        $retries = $request->getMeta('retry_count', 0);
        $request = $request->withMeta("retry_count", ++$retries);
        // Determine the delay timeout
        $delayTimeout = $this->determineDelayTimeout($response);
        // Callback?
        if ($this->option('on_retry_callback')) {
            call_user_func_array(
                $this->option('on_retry_callback'),
                [
                    (int)  $this->option('retry_count'),
                    $delayTimeout,
                    &$request,
                    &$options,
                    $response
                ]
            );
        }

        // Delay!
        usleep((int) ($delayTimeout * 1e6));

        $this->eventDispatcher->dispatch(
            new RequestScheduling($request),
            RequestScheduling::NAME,
        );


        if ($request->wasDropped()) {
            $this->eventDispatcher->dispatch(
                new RequestDropped($request),
                RequestDropped::NAME,
            );

        }

        $this->requestScheduler->schedule($request);

    }


    /**
     * Decide whether to retry on connect exception
     *
     * @param array<string,mixed> $options
     * @return bool
     */
    protected function shouldRetryConnectException(Request $request): bool
    {
        return $this->option('retry_enabled')
            && ($this->option('retry_on_timeout') ?? false)
            && $this->hasTimeAvailable() !== false
            && $this->countRemainingRetries($request) > 0;
    }

    /**
     * Check whether to retry a request that received an HTTP response
     *
     * This checks three things:
     *
     * 1. The response status code against the status codes that should be retried
     * 2. The number of attempts made thus far for this request
     * 3. If 'give_up_after_secs' option is set, time is still available
     *
     * @param Request $request
     * @param Response|null $response
     * @return bool  TRUE if the response should be retried, FALSE if not
     */
    protected function shouldRetryHttpResponse(Request $request, Response $response = null): bool
    {
        $statuses = array_map('\intval', (array) $this->option('retry_on_status'));
        $hasRetryAfterHeader = $response && $response->getResponse()->hasHeader('Retry-After');
        switch (true) {
            case $this->option('retry_enabled') === false:
            case $this->hasTimeAvailable() === false:
            case $this->countRemainingRetries($request) === 0: // No Retry-After header, and it is required?  Give up!
            case (! $hasRetryAfterHeader && $this->option('retry_only_if_retry_after_header')):
                return false;

            // Conditions met; see if status code matches one that can be retried
            default:
                $statusCode = $response ? $response->getStatus() : 0;
                return in_array($statusCode, $statuses, true);
        }
    }

    /**
     * Count the number of retries remaining.  Always returns 0 or greater.
     *
     * @param array<string,mixed> $options
     * @return int
     */
    protected function countRemainingRetries(Request $request): int
    {
        $retryCount  = $request->getMeta('retry_count') !== null ? (int) $request->getMeta('retry_count') : 0;

        $numAllowed  = $this->option('max_retry_attempts') !== null
            ? (int) $this->option('max_retry_attempts')
            : $this->option('max_retry_attempts');

        var_dump((int) max([$numAllowed - $retryCount, 0]));
        return (int) max([$numAllowed - $retryCount, 0]);
    }

    /**
     * @param array<string,mixed> $options
     * @return bool
     */
    protected function hasTimeAvailable(): bool
    {
        // If there is not a 'give_up_after_secs' option, or it is set to a non-truthy value, bail
        if (! $this->option('give_up_after_secs')) {
            return true;
        }

        $giveUpAfterTimestamp = $this->option('first_request_timestamp') + abs(intval($this->option('give_up_after_secs')));
        return $this->option('request_timestamp') < $giveUpAfterTimestamp;
    }

    /**
     * Attempt to derive the timeout from the `Retry-After` (or custom) header value
     *
     * The spec allows the header value to either be a number of seconds or a datetime.
     *
     * @param string $headerValue
     * @param string $dateFormat
     * @return float|null  The number of seconds to wait, or NULL if unsuccessful (invalid header)
     */
    protected function deriveTimeoutFromHeader(string $headerValue, string $dateFormat = self::DATE_FORMAT): ?float
    {
        // The timeout will either be a number or a HTTP-formatted date,
        // or seconds (integer)
        if (is_numeric($headerValue)) {
            return (float) trim($headerValue);
        } elseif ($date = DateTime::createFromFormat($dateFormat ?: self::DATE_FORMAT, trim($headerValue))) {
            return (float) $date->format('U') - time();
        }

        return null;
    }

    /**
     * Determine the delay timeout
     *
     * Attempts to read and interpret the configured Retry-After header, or defaults
     * to a built-in incremental back-off algorithm.
     *
     * @param array<string,mixed> $options
     * @param Response $response
     * @return float  Delay timeout, in seconds
     */
    protected function determineDelayTimeout(Response $response = null): float
    {
        // If 'default_retry_multiplier' option is a callable, call it to determine the default timeout...
        if (is_callable($this->option('default_retry_multiplier'))) {
            $defaultDelayTimeout = (float) call_user_func(
                $this->option('default_retry_multiplier'),
                $this->option('retry_count'),
                $response
            );
        } else { // ...or if it is a numeric value (default), use that.
            $defaultDelayTimeout = (float) $this->option('default_retry_multiplier') * $this->option('retry_count');
        }

        // Retry-After can be a delay in seconds or a date
        // (see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Retry-After)
        if ($response && $response->getResponse()->hasHeader($this->option('retry_after_header'))) {
            $timeout = $this->deriveTimeoutFromHeader(
                    $response->getResponse()->getHeader($this->option('retry_after_header'))[0],
                    $this->option('retry_after_date_format')
                ) ?? $defaultDelayTimeout;
        } else {
            $timeout = abs($defaultDelayTimeout);
        }

        // If the max_allowable_timeout_secs is set, ensure the timeout value is less than that
        if (! is_null($this->option('max_allowable_timeout_secs')) && abs($this->option('max_allowable_timeout_secs')) > 0) {
            $timeout = min(abs($timeout), (float) abs($this->option('max_allowable_timeout_secs')));
        } else {
            $timeout = abs($timeout);
        }

        // If 'give_up_after_secs' is set, account for it in determining the timeout
        if ($this->option('give_up_after_secs')) {
            $giveUpAfterSecs = abs((float) $this->option('give_up_after_secs'));
            $timeSinceFirstReq =  $this->option('request_timestamp') - $this->option('first_request_timestamp');
            $timeout = min($timeout, ($giveUpAfterSecs - $timeSinceFirstReq));
        }

        return $timeout;
    }

    private function defaultOptions(): array
    {
        return [

            // Retry enabled.  Toggle retry on or off per request
            'retry_enabled'                    => true,
            
            'retry_count' =>                    0,

            // If server doesn't provide a Retry-After header, then set a default back-off delay
            // NOTE: This can either be a float, or it can be a callable that returns a (accepts count and response|null)
            'default_retry_multiplier'         => 1.5,

            // Set a maximum number of attempts per request
            'max_retry_attempts'               => 3,

            // Maximum allowable timeout seconds
            'max_allowable_timeout_secs'       => null,

            // Give up after seconds
            'give_up_after_secs'               => null,

            // Set this to TRUE to retry only if the HTTP Retry-After header is specified
            'retry_only_if_retry_after_header' => false,

            // Only retry when status is equal to these response codes
            'retry_on_status'                  => ['429', '503'],

            // Callback to trigger before delay occurs (accepts count, delay, request, response, options)
            'on_retry_callback'                => null,

            'on_retry_response_callback'       => null,

            // Retry on connect timeout?
            'retry_on_timeout'                 => true,

            // Add the number of retries to an X-Header
            'expose_retry_header'              => false,

            // The header key
            'retry_header'                     => self::RETRY_HEADER,

            // The retry after header key
            'retry_after_header'               => self::RETRY_AFTER_HEADER,

            // Date format
            'retry_after_date_format'          => self::DATE_FORMAT
        ];
    }
}