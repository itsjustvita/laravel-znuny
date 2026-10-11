<?php

namespace ItsJustVita\LaravelZnuny;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ItsJustVita\LaravelZnuny\Config\ConnectionConfig;
use ItsJustVita\LaravelZnuny\Events\ZnunyRequestFailed;
use ItsJustVita\LaravelZnuny\Events\ZnunyRequestSent;
use ItsJustVita\LaravelZnuny\Events\ZnunyResponseReceived;
use ItsJustVita\LaravelZnuny\Events\ZnunySessionRefreshed;
use ItsJustVita\LaravelZnuny\Exceptions\ExceptionMapper;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunySessionExpiredException;
use ItsJustVita\LaravelZnuny\Http\PreparedRequest;
use ItsJustVita\LaravelZnuny\Http\RequestBuilder;
use ItsJustVita\LaravelZnuny\Routing\RouteMap;
use ItsJustVita\LaravelZnuny\Session\SessionManager;
use ItsJustVita\LaravelZnuny\Support\Redactor;
use stdClass;
use Throwable;

class ZnunyClient
{
    /**
     * Operations that only read data. They are repeated after timeouts and server errors.
     * Everything else is repeated only when no connection could be established, because
     * Znuny may already have processed it (a slow TicketCreate would create duplicates).
     */
    protected const READ_OPERATIONS = [
        'SessionCreate', 'SessionGet', 'TicketGet', 'TicketSearch', 'TicketHistoryGet',
        'QueueList', 'QueueGet', 'StateList', 'PriorityList', 'TypeList', 'CustomerUserGet',
    ];

    protected readonly ConnectionConfig $settings;

    protected readonly RequestBuilder $requests;

    /**
     * @param  array<string, mixed>  $config  connection config, see config/znuny.php
     */
    public function __construct(
        protected readonly string $connection,
        array $config,
        protected readonly SessionManager $sessionManager,
        protected readonly ExceptionMapper $exceptionMapper = new ExceptionMapper,
    ) {
        $this->settings = ConnectionConfig::fromArray($connection, $config, (array) config('znuny.http', []));
        $this->requests = new RequestBuilder($this->settings);
    }

    public function connection(): string
    {
        return $this->connection;
    }

    public function settings(): ConnectionConfig
    {
        return $this->settings;
    }

    public function routes(): RouteMap
    {
        return $this->settings->routes;
    }

    /**
     * Call any Znuny operation with routing, authentication, error mapping, events and logging.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function call(string $operation, array $payload = []): array
    {
        if (! $this->settings->usesSession()) {
            return $this->send($operation, $payload, $this->credentials());
        }

        try {
            return $this->send($operation, $payload, ['SessionID' => $this->sessionId()]);
        } catch (ZnunySessionExpiredException $e) {
            if (! config('znuny.session.auto_retry_on_expired', true)) {
                throw $e;
            }

            $this->sessionManager->invalidate($this->sessionKey());
            $sessionId = $this->sessionId();

            event(new ZnunySessionRefreshed(connection: $this->connection));

            return $this->send($operation, $payload, ['SessionID' => $sessionId]);
        }
    }

    /**
     * @deprecated since 0.2.0, use call()
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function request(string $operation, array $payload = []): array
    {
        return $this->call($operation, $payload);
    }

    /**
     * Check the credentials: a fresh SessionCreate in session mode, a minimal
     * TicketSearch in credentials mode.
     */
    public function ping(): bool
    {
        if ($this->settings->usesSession()) {
            $this->createSession();
        } else {
            $this->call('TicketSearch', ['Limit' => 1]);
        }

        return true;
    }

    protected function sessionId(): string
    {
        return $this->sessionManager->sessionId($this->sessionKey(), fn (): string => $this->createSession());
    }

    protected function sessionKey(): string
    {
        return $this->settings->sessionKey((string) config('app.key', ''));
    }

    protected function createSession(): string
    {
        $response = $this->send('SessionCreate', [], $this->credentials());
        $sessionId = (string) ($response['SessionID'] ?? '');

        if ($sessionId === '') {
            throw $this->exceptionMapper->fromErrorResponse(
                body: $response,
                operation: 'SessionCreate',
                connection: $this->connection,
                payload: $this->credentials(),
                sessionAuth: false,
            );
        }

        return $sessionId;
    }

    /** @return array<string, string> */
    protected function credentials(): array
    {
        return [
            $this->settings->loginKey() => $this->settings->username,
            'Password' => $this->settings->password,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $auth
     * @return array<array-key, mixed>
     */
    protected function send(string $operation, array $payload, array $auth): array
    {
        try {
            $request = $this->requests->build($operation, $payload, $auth);
        } catch (ZnunyConfigurationException $e) {
            throw $e->setOperation($operation)->setConnection($this->connection);
        }

        $data = array_merge($auth, $payload);
        $redacted = Redactor::redact($data);

        event(new ZnunyRequestSent(
            connection: $this->connection,
            operation: $operation,
            payload: $redacted,
            method: $request->method,
            path: $request->path,
        ));

        $this->log('info', 'log_requests', 'Znuny request', [
            'connection' => $this->connection,
            'operation' => $operation,
            'method' => $request->method,
            'path' => $request->path,
            'payload' => Redactor::forLog($data),
        ]);

        $start = microtime(true);

        try {
            $response = $this->dispatch($request, $operation);
        } catch (ConnectionException $e) {
            // Transport messages contain the request URL, which can carry credentials in the
            // query string (payload transport on GET routes) or a session ID in the path.
            $message = Redactor::scrub(str_replace($request->url, $this->settings->baseUrl.$request->path, $e->getMessage()));

            if (str_contains($message, 'Empty reply from server')) {
                $message .= ' Znuny answers this way when the web service named in base_url does not exist.';
            }

            throw $this->failed($operation, $request, $redacted, $this->exceptionMapper->fromTransportError(
                new ConnectionException($message, (int) $e->getCode()),
                $operation,
                $this->connection,
                $data,
            ));
        }

        $elapsed = (microtime(true) - $start) * 1000.0;
        $json = $this->decode($response);

        if ($json === null || $response->failed() || isset($json['Error'])) {
            throw $this->failed($operation, $request, $redacted, $this->exceptionMapper->fromResponse(
                status: $response->status(),
                json: $json,
                rawBody: $response->body(),
                operation: $operation,
                connection: $this->connection,
                payload: $data,
                sessionAuth: $this->settings->usesSession(),
                method: $request->method,
                path: $request->path,
            ));
        }

        event(new ZnunyResponseReceived(
            connection: $this->connection,
            operation: $operation,
            responseTimeMs: $elapsed,
            payload: $redacted,
            response: Redactor::redact($json),
            status: $response->status(),
        ));

        $this->log('debug', 'log_responses', 'Znuny response', [
            'connection' => $this->connection,
            'operation' => $operation,
            'status' => $response->status(),
            'duration_ms' => round($elapsed, 1),
            'response' => Redactor::forLog($json),
        ]);

        return $json;
    }

    protected function dispatch(PreparedRequest $request, string $operation): Response
    {
        $repeatable = $request->method === 'GET' || in_array($operation, self::READ_OPERATIONS, true);
        $client = $this->httpClient($repeatable)->withHeaders($request->headers);

        if ($request->body === null) {
            return $client->send('GET', $request->url);
        }

        $body = json_encode(
            $request->body === [] ? new stdClass : $request->body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $client->withBody($body, 'application/json')->send($request->method, $request->url);
    }

    /** @return array<array-key, mixed>|null */
    protected function decode(Response $response): ?array
    {
        $decoded = json_decode($response->body(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Dispatch the failure event, write the error log and hand the exception back for throwing.
     */
    protected function failed(string $operation, PreparedRequest $request, array $redacted, ZnunyException $exception): ZnunyException
    {
        event(new ZnunyRequestFailed(
            connection: $this->connection,
            operation: $operation,
            exception: $exception,
            payload: $redacted,
        ));

        $this->log('error', 'log_errors', 'Znuny error', [
            'connection' => $this->connection,
            'operation' => $operation,
            'method' => $request->method,
            'path' => $request->path,
            'http_status' => $exception->getHttpStatus(),
            'code' => $exception->getErrorCode(),
            'message' => $exception->getMessage(),
        ]);

        return $exception;
    }

    protected function log(string $level, string $toggle, string $message, array $context): void
    {
        if (! config('znuny.logging.enabled') || ! config("znuny.logging.{$toggle}")) {
            return;
        }

        Log::channel((string) config('znuny.logging.channel', 'znuny'))->{$level}($message, $context);
    }

    /**
     * @param  bool  $repeatable  whether the request may be repeated after it possibly reached Znuny
     */
    protected function httpClient(bool $repeatable = true): PendingRequest
    {
        $client = Http::timeout($this->settings->timeout)
            ->connectTimeout($this->settings->connectTimeout);

        if ($this->settings->retries > 0) {
            $delays = array_map(
                fn (int $attempt): int => $this->settings->retryDelay * (2 ** $attempt),
                range(0, $this->settings->retries - 1),
            );

            $client = $client->retry($delays, throw: false, when: function (Throwable $exception) use ($repeatable): bool {
                if ($exception instanceof ConnectionException) {
                    // cURL 6/7: the request never reached Znuny, so repeating it is always safe.
                    return $repeatable || preg_match('/cURL error (6|7):/', $exception->getMessage()) === 1;
                }

                // A missing route is a configuration problem that no retry can fix.
                return $repeatable
                    && $exception instanceof RequestException
                    && $exception->response->serverError()
                    && ! str_contains($exception->response->body(), 'determine Operation');
            });
        }

        return $this->settings->verifySsl ? $client : $client->withOptions(['verify' => false]);
    }
}
