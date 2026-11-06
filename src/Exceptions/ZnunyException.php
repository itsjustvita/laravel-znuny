<?php

namespace ItsJustVita\LaravelZnuny\Exceptions;

use ItsJustVita\LaravelZnuny\Support\Redactor;
use RuntimeException;

abstract class ZnunyException extends RuntimeException
{
    protected string $operation = '';

    protected string $errorCode = '';

    protected string $connection = '';

    /**
     * @var array<string, mixed>
     */
    protected array $requestPayload = [];

    /**
     * @var array<array-key, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $payload
     */
    public function setRequestPayload(array $payload): static
    {
        $this->requestPayload = Redactor::redact($payload);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequestPayload(): array
    {
        return $this->requestPayload;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    public function setResponseBody(array $body): static
    {
        $this->responseBody = Redactor::redact($body);

        return $this;
    }

    /**
     * @return array<array-key, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
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
