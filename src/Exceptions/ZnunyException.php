<?php

namespace ItsJustVita\LaravelZnuny\Exceptions;

use ItsJustVita\LaravelZnuny\Support\Redactor;
use RuntimeException;

abstract class ZnunyException extends RuntimeException
{
    protected string $operation = '';

    protected string $errorCode = '';

    protected string $connection = '';

    protected array $requestPayload = [];

    protected array $responseBody = [];

    protected ?int $httpStatus = null;

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
        $this->requestPayload = Redactor::redact($payload);

        return $this;
    }

    public function getRequestPayload(): array
    {
        return $this->requestPayload;
    }

    public function setResponseBody(array $body): static
    {
        $this->responseBody = Redactor::redact($body);

        return $this;
    }

    public function getResponseBody(): array
    {
        return $this->responseBody;
    }

    public function setHttpStatus(?int $status): static
    {
        $this->httpStatus = $status;

        return $this;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function context(): array
    {
        return [
            'znuny_operation' => $this->operation,
            'znuny_error_code' => $this->errorCode,
            'znuny_connection' => $this->connection,
            'znuny_http_status' => $this->httpStatus,
            'znuny_payload' => $this->requestPayload,
            'znuny_response' => $this->responseBody,
        ];
    }
}
