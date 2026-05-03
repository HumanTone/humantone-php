<?php

declare(strict_types=1);

namespace HumanTone\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Minimal PSR-18 client double that returns pre-canned responses (or
 * throws pre-canned exceptions) and records sent requests.
 *
 * Constructed with a queue: each item is either a {@see ResponseInterface}
 * to return, or a {@see Throwable} to throw, OR a callable that takes the
 * incoming request and returns/throws.
 */
final class Psr18MockClient implements ClientInterface
{
    /** @var list<ResponseInterface|Throwable|callable(RequestInterface): ResponseInterface> */
    private array $queue;

    /** @var list<RequestInterface> */
    public array $sent = [];

    /**
     * @param iterable<ResponseInterface|Throwable|callable(RequestInterface): ResponseInterface> $queue
     */
    public function __construct(iterable $queue = [])
    {
        $this->queue = is_array($queue) ? array_values($queue) : iterator_to_array($queue, false);
    }

    /**
     * @param ResponseInterface|Throwable|callable(RequestInterface): ResponseInterface $item
     */
    public function enqueue(mixed $item): void
    {
        $this->queue[] = $item;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        if ($this->queue === []) {
            throw new RuntimeException('Psr18MockClient queue exhausted');
        }
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        if (is_callable($next)) {
            return $next($request);
        }
        return $next;
    }

    public function lastRequest(): ?RequestInterface
    {
        $count = count($this->sent);
        return $count > 0 ? $this->sent[$count - 1] : null;
    }
}
