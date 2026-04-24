<?php

namespace ItsJustVita\LaravelZnuny\Exceptions;

class ExceptionMapper
{
    public function fromErrorResponse(array $body, string $operation, string $connection, array $payload): ZnunyException
    {
        $error = is_array($body['Error'] ?? null) ? $body['Error'] : [];
        $code = (string) ($error['ErrorCode'] ?? '');
        $message = (string) ($error['ErrorMessage'] ?? 'Unknown Znuny error');

        $exception = $this->selectExceptionClass($code, $message, $operation);
        $exception->setErrorCode($code);
        $exception->setOperation($operation);
        $exception->setConnection($connection);
        $exception->setRequestPayload($payload);
        $exception->setResponseBody($body);

        return $exception;
    }

    public function fromHttpStatus(int $status, array $body, string $operation, string $connection, array $payload): ZnunyException
    {
        $error = is_array($body['Error'] ?? null) ? $body['Error'] : [];
        $code = (string) ($error['ErrorCode'] ?? '');
        $znunyMessage = (string) ($error['ErrorMessage'] ?? $body['message'] ?? "{$operation} failed");
        $message = sprintf('[HTTP %d] %s', $status, $znunyMessage);

        $exception = match (true) {
            $status === 401 || $status === 403 => new ZnunyAuthenticationException($message),
            $status === 429 => new ZnunyRateLimitException($message),
            $status >= 500 => new ZnunyServerException($message),
            default => new ZnunyServerException($message),
        };

        $exception->setErrorCode($code);
        $exception->setOperation($operation);
        $exception->setConnection($connection);
        $exception->setRequestPayload($payload);
        $exception->setResponseBody($body);

        return $exception;
    }

    public function fromTransportError(\Throwable $previous, string $operation, string $connection, array $payload): ZnunyConnectionException
    {
        $exception = new ZnunyConnectionException($previous->getMessage(), 0, $previous);
        $exception->setOperation($operation);
        $exception->setConnection($connection);
        $exception->setRequestPayload($payload);

        return $exception;
    }

    private function selectExceptionClass(string $code, string $message, string $operation): ZnunyException
    {
        $haystack = strtolower($code.' '.$message);
        $lowerMessage = strtolower($message);
        $fullMessage = $message !== '' ? "[{$code}] {$message}" : $code;

        // AuthFail on any operation other than SessionCreate means the cached session
        // has expired — the agent already authenticated successfully before this point.
        // Only SessionCreate.AuthFail indicates genuinely wrong credentials.
        if (str_contains($haystack, 'authfail') && $operation !== 'SessionCreate') {
            return new ZnunySessionExpiredException($fullMessage);
        }
        if (str_contains($lowerMessage, 'sessionid') && str_contains($lowerMessage, 'invalid')) {
            return new ZnunySessionExpiredException($fullMessage);
        }
        if (str_contains($lowerMessage, 'session') && str_contains($lowerMessage, 'expired')) {
            return new ZnunySessionExpiredException($fullMessage);
        }

        // Auth fail (any other cause, e.g. SessionCreate.AuthFail)
        if (str_contains($haystack, 'authfail')) {
            return new ZnunyAuthenticationException($fullMessage);
        }

        // Not found
        if (str_contains($haystack, 'notfound') || str_contains($haystack, 'not found')) {
            return $this->notFoundForOperation($operation, $fullMessage);
        }

        // Invalid (validation) subtypes
        if (str_contains($haystack, 'invalidqueue') || str_contains($haystack, 'invalid queue')) {
            return new InvalidQueueException($fullMessage);
        }
        if (str_contains($haystack, 'invalidstate') || str_contains($haystack, 'invalid state')) {
            return new InvalidStateException($fullMessage);
        }
        if (str_contains($haystack, 'invalidpriority') || str_contains($haystack, 'invalid priority')) {
            return new InvalidPriorityException($fullMessage);
        }
        if (str_contains($haystack, 'invaliddynamicfield') || str_contains($haystack, 'invalid dynamicfield')) {
            return new InvalidDynamicFieldException($fullMessage);
        }

        // Rate limit
        if (str_contains($haystack, 'ratelimit') || str_contains($haystack, 'too many')) {
            return new ZnunyRateLimitException($fullMessage);
        }

        // Fallback: concrete generic exception (named class for log grouping)
        return new GenericZnunyException($fullMessage);
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
}
