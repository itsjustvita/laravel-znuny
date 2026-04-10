<?php

namespace ItsJustVita\LaravelZnuny;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Exceptions\ExceptionMapper;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunySessionExpiredException;
use ItsJustVita\LaravelZnuny\Session\SessionManager;
use Throwable;

class ZnunyClient
{
    public function __construct(
        protected readonly string $connection,
        protected readonly array $config,
        protected readonly SessionManager $sessionManager,
        protected readonly ExceptionMapper $exceptionMapper,
    ) {}

    public function connection(): string
    {
        return $this->connection;
    }

    public function request(string $operation, array $payload = []): array
    {
        return $this->requestWithRetry(
            $operation,
            $payload,
            retriesLeft: $this->autoRetryEnabled() ? 1 : 0,
        );
    }

    protected function requestWithRetry(string $operation, array $payload, int $retriesLeft): array
    {
        $sessionId = $this->sessionManager->sessionId(
            $this->connection,
            fn (string $name) => $this->createSession(),
        );

        $body = array_merge(['SessionID' => $sessionId], $payload);

        try {
            return $this->dispatch($operation, $body);
        } catch (ZnunySessionExpiredException $e) {
            if ($retriesLeft <= 0) {
                throw $e;
            }

            $this->sessionManager->invalidate($this->connection);

            return $this->requestWithRetry($operation, $payload, $retriesLeft - 1);
        }
    }

    protected function autoRetryEnabled(): bool
    {
        return (bool) config('znuny.session.auto_retry_on_expired', true);
    }

    protected function createSession(): string
    {
        $body = [
            'UserLogin' => $this->config['username'],
            'Password'  => $this->config['password'],
        ];

        $response = $this->dispatch('SessionCreate', $body);

        if (! isset($response['SessionID'])) {
            throw $this->exceptionMapper->fromErrorResponse(
                body: $response,
                operation: 'SessionCreate',
                connection: $this->connection,
                payload: $body,
            );
        }

        return (string) $response['SessionID'];
    }

    protected function dispatch(string $operation, array $body): array
    {
        $url = rtrim($this->config['base_url'], '/') . '/' . ltrim($operation, '/');

        event(new \ItsJustVita\LaravelZnuny\Events\ZnunyRequestSent(
            connection: $this->connection,
            operation: $operation,
            payload: $this->redact($body),
        ));

        $start = microtime(true);

        try {
            $response = $this->httpClient()->post($url, $body);
        } catch (ConnectionException $e) {
            $ex = $this->exceptionMapper->fromTransportError($e, $operation, $this->connection, $body);
            event(new \ItsJustVita\LaravelZnuny\Events\ZnunyRequestFailed(
                connection: $this->connection,
                operation: $operation,
                exception: $ex,
                payload: $this->redact($body),
            ));
            throw $ex;
        }

        $elapsed = (microtime(true) - $start) * 1000.0;

        if ($response->serverError()) {
            $ex = $this->exceptionMapper->fromHttpStatus(
                status: $response->status(),
                body: $response->json() ?? [],
                operation: $operation,
                connection: $this->connection,
                payload: $body,
            );
            event(new \ItsJustVita\LaravelZnuny\Events\ZnunyRequestFailed(
                connection: $this->connection,
                operation: $operation,
                exception: $ex,
                payload: $this->redact($body),
            ));
            throw $ex;
        }

        $data = $response->json() ?? [];

        if (isset($data['Error'])) {
            $ex = $this->exceptionMapper->fromErrorResponse(
                body: $data,
                operation: $operation,
                connection: $this->connection,
                payload: $body,
            );
            event(new \ItsJustVita\LaravelZnuny\Events\ZnunyRequestFailed(
                connection: $this->connection,
                operation: $operation,
                exception: $ex,
                payload: $this->redact($body),
            ));
            throw $ex;
        }

        event(new \ItsJustVita\LaravelZnuny\Events\ZnunyResponseReceived(
            connection: $this->connection,
            operation: $operation,
            responseTimeMs: $elapsed,
            payload: $this->redact($body),
            response: $data,
        ));

        return $data;
    }

    protected function redact(array $payload): array
    {
        foreach (['SessionID', 'Password', 'UserPw'] as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    protected function httpClient(): PendingRequest
    {
        $retries = max(0, (int) ($this->config['retries'] ?? config('znuny.http.retries', 3)));
        $baseDelay = max(10, (int) ($this->config['retry_delay'] ?? config('znuny.http.retry_delay', 100)));
        $delays = $retries > 0
            ? array_map(fn (int $i) => $baseDelay * (2 ** $i), range(0, $retries - 1))
            : [0];

        $client = Http::asJson()
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 3));

        if ($retries > 0) {
            $client = $client->retry(
                $delays,
                throw: false,
                when: function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        return $exception->response->serverError();
                    }

                    return false;
                },
            );
        }

        if (! ($this->config['verify_ssl'] ?? true)) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client;
    }
}
