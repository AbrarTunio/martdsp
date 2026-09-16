<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What every driver shares: a short timeout, another try when the line
 * drops or the provider is busy, and errors turned into plain words.
 */
abstract class HttpDriver implements AiDriver
{
    public function __construct(protected readonly AiConnection $connection) {}

    /**
     * The request with this provider's key on it.
     */
    abstract protected function client(): PendingRequest;

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->connection->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('supermart.ai.timeout', 20))
            ->connectTimeout(8)
            ->retry(2, 400, fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)), throw: false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: Response, 1: int} the response, and how long it took in milliseconds
     *
     * @throws AiException
     */
    protected function send(string $method, string $path, array $payload = []): array
    {
        $started = hrtime(true);

        try {
            $response = $method === 'GET'
                ? $this->client()->get($path, $payload)
                : $this->client()->post($path, $payload);
        } catch (ConnectionException) {
            throw AiException::unreachable($this->connection->provider->label());
        }

        if ($response->failed()) {
            throw AiException::fromResponse($response, $this->connection->provider->label());
        }

        return [$response, (int) round((hrtime(true) - $started) / 1_000_000)];
    }
}
