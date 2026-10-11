<?php

namespace ItsJustVita\LaravelZnuny\Exceptions;

use Throwable;

class ExceptionMapper
{
    private const VALIDATION_SUFFIXES = ['MissingParameter', 'InvalidParameter', 'WrongStructure', 'EmptyRequest'];

    private const NOT_FOUND_SUFFIXES = ['NotValidTicketID', 'NotTicketData'];

    /**
     * Map a body that contains Znuny's {"Error": {"ErrorCode", "ErrorMessage"}} structure.
     */
    public function fromErrorResponse(
        array $body,
        string $operation,
        string $connection,
        array $payload,
        bool $sessionAuth = true,
        ?int $httpStatus = null,
    ): ZnunyException {
        [$code, $message] = $this->errorParts($body);

        $fullMessage = match (true) {
            $message !== '' => "[{$code}] {$message}",
            $code !== '' => $code,
            default => 'Unknown Znuny error',
        };

        $exception = $this->forErrorCode($code, $message, $operation, $sessionAuth, $fullMessage)
            ?? ($httpStatus !== null && $httpStatus >= 400
                ? $this->forStatus($httpStatus, sprintf('[HTTP %d] %s', $httpStatus, $fullMessage))
                : new GenericZnunyException($fullMessage));

        return $this->decorate($exception, $operation, $connection, $payload, $body, $code, $httpStatus);
    }

    /**
     * Map an HTTP error status whose JSON body has no "Error" structure.
     */
    public function fromHttpStatus(int $status, array $body, string $operation, string $connection, array $payload): ZnunyException
    {
        [$code, $message] = $this->errorParts($body);
        $detail = $message !== '' ? $message : (string) ($body['faultstring'] ?? $body['message'] ?? "{$operation} failed");

        return $this->decorate(
            $this->forStatus($status, sprintf('[HTTP %d] %s', $status, $detail)),
            $operation,
            $connection,
            $payload,
            $body,
            $code,
            $status,
        );
    }

    /**
     * Map any unsuccessful response: Znuny error bodies, HTTP errors, missing routes and non-JSON bodies.
     *
     * @param  array<array-key, mixed>|null  $json  the decoded body, null when it is not JSON
     */
    public function fromResponse(
        int $status,
        ?array $json,
        string $rawBody,
        string $operation,
        string $connection,
        array $payload,
        bool $sessionAuth,
        string $method,
        string $path,
    ): ZnunyException {
        if ($json !== null && isset($json['Error'])) {
            return $this->fromErrorResponse($json, $operation, $connection, $payload, $sessionAuth, $status);
        }

        if ($status === 404 || str_contains($rawBody, 'determine Operation')) {
            $exception = new ZnunyRouteNotFoundException(sprintf(
                'Znuny has no route for %s %s (operation [%s], connection [%s]). '
                .'Check the "routes" configuration of the connection (php artisan znuny:routes %s).',
                $method,
                $path,
                $operation,
                $connection,
                $connection,
            ));

            return $this->decorate($exception, $operation, $connection, $payload, $json ?? $this->raw($rawBody), '', $status);
        }

        if ($status >= 400) {
            if ($json !== null) {
                return $this->fromHttpStatus($status, $json, $operation, $connection, $payload);
            }

            $detail = $this->excerpt($rawBody);

            return $this->decorate(
                $this->forStatus($status, sprintf('[HTTP %d] %s', $status, $detail !== '' ? $detail : "{$operation} failed")),
                $operation,
                $connection,
                $payload,
                $this->raw($rawBody),
                '',
                $status,
            );
        }

        $exception = new ZnunyServerException(sprintf(
            'Znuny returned an unexpected non-JSON response for %s %s (operation [%s]).',
            $method,
            $path,
            $operation,
        ));

        return $this->decorate($exception, $operation, $connection, $payload, $this->raw($rawBody), '', $status);
    }

    public function fromTransportError(Throwable $previous, string $operation, string $connection, array $payload): ZnunyConnectionException
    {
        $exception = new ZnunyConnectionException($previous->getMessage(), 0, $previous);
        $exception->setOperation($operation);
        $exception->setConnection($connection);
        $exception->setRequestPayload($payload);

        return $exception;
    }

    private function forErrorCode(string $code, string $message, string $operation, bool $sessionAuth, string $fullMessage): ?ZnunyException
    {
        $suffix = str_contains($code, '.') ? substr($code, strrpos($code, '.') + 1) : $code;
        $haystack = strtolower($code.' '.$message);
        $lowerMessage = strtolower($message);

        // AuthFail on any operation other than SessionCreate means the cached session expired.
        // Without sessions (credentials mode) and for SessionCreate it means wrong credentials.
        if ($suffix === 'AuthFail' || str_contains($haystack, 'authfail')) {
            return $sessionAuth && $operation !== 'SessionCreate'
                ? new ZnunySessionExpiredException($fullMessage)
                : new ZnunyAuthenticationException($fullMessage);
        }

        if ($sessionAuth && str_contains($lowerMessage, 'session') && (
            str_contains($lowerMessage, 'expired')
            || (str_contains($lowerMessage, 'sessionid') && str_contains($lowerMessage, 'invalid'))
        )) {
            return new ZnunySessionExpiredException($fullMessage);
        }

        if ($suffix === 'AccessDenied') {
            return new ZnunyAccessDeniedException($fullMessage);
        }

        if (in_array($suffix, self::NOT_FOUND_SUFFIXES, true)) {
            return $this->notFoundForOperation($operation, $fullMessage);
        }

        if (in_array($suffix, self::VALIDATION_SUFFIXES, true)) {
            return $this->validation($haystack, $fullMessage);
        }

        // Heuristics from 0.1 for codes that Znuny core does not use (custom operations).
        if (str_contains($haystack, 'notfound') || str_contains($haystack, 'not found')) {
            return $this->notFoundForOperation($operation, $fullMessage);
        }

        if (preg_match('/invalid ?(queue|state|priority|dynamicfield)/', $haystack) === 1) {
            return $this->validation($haystack, $fullMessage);
        }

        if (str_contains($haystack, 'ratelimit') || str_contains($haystack, 'too many')) {
            return new ZnunyRateLimitException($fullMessage);
        }

        if ($code === 'Webservice.InvalidConfiguration') {
            return new ZnunyServerException($fullMessage);
        }

        return null;
    }

    private function validation(string $haystack, string $message): ZnunyValidationException
    {
        return match (true) {
            preg_match('/ticket->queue|invalid ?queue/', $haystack) === 1 => new InvalidQueueException($message),
            preg_match('/ticket->state|invalid ?state/', $haystack) === 1 => new InvalidStateException($message),
            preg_match('/ticket->priority|invalid ?priority/', $haystack) === 1 => new InvalidPriorityException($message),
            str_contains($haystack, 'dynamicfield') => new InvalidDynamicFieldException($message),
            default => new ZnunyValidationException($message),
        };
    }

    private function notFoundForOperation(string $operation, string $message): ZnunyNotFoundException
    {
        return match (true) {
            str_starts_with($operation, 'Ticket') => new TicketNotFoundException($message),
            str_starts_with($operation, 'Queue') => new QueueNotFoundException($message),
            str_starts_with($operation, 'Customer') => new CustomerNotFoundException($message),
            default => new ZnunyNotFoundException($message),
        };
    }

    private function forStatus(int $status, string $message): ZnunyException
    {
        return match (true) {
            $status === 401, $status === 403 => new ZnunyAuthenticationException($message),
            $status === 429 => new ZnunyRateLimitException($message),
            $status >= 500 => new ZnunyServerException($message),
            default => new GenericZnunyException($message),
        };
    }

    /** @return array{0: string, 1: string} */
    private function errorParts(array $body): array
    {
        $error = is_array($body['Error'] ?? null) ? $body['Error'] : [];

        return [(string) ($error['ErrorCode'] ?? ''), (string) ($error['ErrorMessage'] ?? '')];
    }

    private function decorate(
        ZnunyException $exception,
        string $operation,
        string $connection,
        array $payload,
        array $body,
        string $code,
        ?int $status,
    ): ZnunyException {
        return $exception
            ->setErrorCode($code)
            ->setOperation($operation)
            ->setConnection($connection)
            ->setRequestPayload($payload)
            ->setResponseBody($body)
            ->setHttpStatus($status);
    }

    /** @return array{raw: string} */
    private function raw(string $body): array
    {
        return ['raw' => mb_substr($body, 0, 1000)];
    }

    private function excerpt(string $body): string
    {
        return trim(mb_substr(trim($body), 0, 500));
    }
}
