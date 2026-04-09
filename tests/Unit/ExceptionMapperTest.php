<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit;

use ItsJustVita\LaravelZnuny\Exceptions\ExceptionMapper;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidQueueException;
use ItsJustVita\LaravelZnuny\Exceptions\QueueNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAuthenticationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyRateLimitException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunySessionExpiredException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class ExceptionMapperTest extends TestCase
{
    private ExceptionMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ExceptionMapper();
    }

    public function test_it_maps_session_expired_error_code(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketGet.AuthFail', 'ErrorMessage' => 'SessionID expired']],
            operation: 'TicketGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(ZnunySessionExpiredException::class, $exception);
        $this->assertSame('TicketGet.AuthFail', $exception->getErrorCode());
    }

    public function test_it_maps_auth_fail_without_session_hint_to_authentication(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'SessionCreate.AuthFail', 'ErrorMessage' => 'Wrong credentials']],
            operation: 'SessionCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(ZnunyAuthenticationException::class, $exception);
        $this->assertNotInstanceOf(ZnunySessionExpiredException::class, $exception);
    }

    public function test_it_maps_ticket_not_found(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketGet.NotFound', 'ErrorMessage' => 'TicketID 42 not found']],
            operation: 'TicketGet',
            connection: 'default',
            payload: ['TicketID' => '42'],
        );

        $this->assertInstanceOf(TicketNotFoundException::class, $exception);
    }

    public function test_it_maps_invalid_queue(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketCreate.InvalidQueue', 'ErrorMessage' => 'Invalid queue']],
            operation: 'TicketCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(InvalidQueueException::class, $exception);
    }

    public function test_it_maps_queue_not_found_for_queue_operations(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'QueueGet.NotFound', 'ErrorMessage' => 'Queue not found']],
            operation: 'QueueGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(QueueNotFoundException::class, $exception);
    }

    public function test_it_maps_rate_limit(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'RateLimitExceeded', 'ErrorMessage' => 'Too many']],
            operation: 'TicketSearch',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(ZnunyRateLimitException::class, $exception);
    }

    public function test_it_maps_http_5xx_to_server_exception(): void
    {
        $exception = $this->mapper->fromHttpStatus(
            status: 503,
            body: ['message' => 'Service Unavailable'],
            operation: 'TicketGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(ZnunyServerException::class, $exception);
    }

    public function test_it_propagates_metadata_to_exception(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketGet.NotFound', 'ErrorMessage' => 'gone']],
            operation: 'TicketGet',
            connection: 'tenant-a',
            payload: ['TicketID' => '99'],
        );

        $this->assertSame('TicketGet', $exception->getOperation());
        $this->assertSame('tenant-a', $exception->getConnection());
        $this->assertSame(['TicketID' => '99'], $exception->getRequestPayload());
    }
}
