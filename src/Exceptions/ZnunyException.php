<?php

namespace ItsJustVita\LaravelZnuny\Exceptions;

use RuntimeException;

abstract class ZnunyException extends RuntimeException
{
    protected string $operation = '';

    protected string $errorCode = '';

    protected string $connection = '';

    protected array $requestPayload = [];

    protected array $responseBody = [];

    public function setOperation(string $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function setErrorCode(string $code): static
    {
        $this->errorCode = $code;

        return $this;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function setConnection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function getConnection(): string
    {
        return $this->connection;
    }

    public function setRequestPayload(array $payload): static
    {
        $this->requestPayload = $this->sanitize($payload);

        return $this;
    }

    public function getRequestPayload(): array
    {
        return $this->requestPayload;
    }

    public function setResponseBody(array $body): static
    {
        $this->responseBody = $body;

        return $this;
    }

    public function getResponseBody(): array
    {
        return $this->responseBody;
    }

    public function context(): array
    {
        return [
            'znuny_operation' => $this->operation,
            'znuny_error_code' => $this->errorCode,
            'znuny_connection' => $this->connection,
            'znuny_payload' => $this->requestPayload,
            'znuny_response' => $this->responseBody,
        ];
    }

    private function sanitize(array $payload): array
    {
        $redactKeys = [
            'password', 'sessionid', 'userpw', 'pw', 'newpw',
            'secret', 'token', 'accesstoken', 'apikey', 'authorization', 'otp',
        ];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value);

                continue;
            }

            if (is_string($key) && in_array(strtolower($key), $redactKeys, true)) {
                $payload[$key] = '[REDACTED]';
            }
        }

        return $payload;
    }
}
