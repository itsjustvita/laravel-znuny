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

    private function sanitize(array $payload): array
    {
        $redactKeys = ['Password', 'SessionID', 'UserPw', 'Secret'];

        foreach ($payload as $key => $value) {
            if (in_array($key, $redactKeys, true)) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }
}
