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

    public function test_it_maps_sessionid_invalid_to_session_expired(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketGet.AuthFail', 'ErrorMessage' => 'SessionID is invalid']],
            operation: 'TicketGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(ZnunySessionExpiredException::class, $exception);
    }

    public function test_it_maps_session_expired_prose_message(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'QueueGet.AuthFail', 'ErrorMessage' => 'Your session has expired, please login again']],
            operation: 'QueueGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(ZnunySessionExpiredException::class, $exception);
    }

    public function test_it_maps_invalid_state(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketCreate.InvalidState', 'ErrorMessage' => 'Invalid state']],
            operation: 'TicketCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\InvalidStateException::class, $exception);
    }

    public function test_it_maps_invalid_priority(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketCreate.InvalidPriority', 'ErrorMessage' => 'Invalid priority']],
            operation: 'TicketCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\InvalidPriorityException::class, $exception);
    }

    public function test_it_maps_invalid_dynamic_field(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketCreate.InvalidDynamicField', 'ErrorMessage' => 'Invalid DynamicField Foo']],
            operation: 'TicketCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\InvalidDynamicFieldException::class, $exception);
    }

    public function test_it_maps_customer_not_found(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'CustomerUserGet.NotFound', 'ErrorMessage' => 'Customer not found']],
            operation: 'CustomerUserGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\CustomerNotFoundException::class, $exception);
    }

    public function test_unknown_not_found_falls_back_to_base_not_found(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'SomeUnknownOp.NotFound', 'ErrorMessage' => 'nope']],
            operation: 'SomeUnknownOp',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\ZnunyNotFoundException::class, $exception);
        $this->assertNotInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException::class, $exception);
    }

    public function test_unknown_error_code_falls_back_to_generic(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketCreate.MysteryError', 'ErrorMessage' => 'Something unusual']],
            operation: 'TicketCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\GenericZnunyException::class, $exception);
        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\ZnunyException::class, $exception);
    }

    public function test_http_401_maps_to_authentication(): void
    {
        $exception = $this->mapper->fromHttpStatus(
            status: 401,
            body: [],
            operation: 'TicketGet',
            connection: 'default',
            payload: ['TicketID' => '1'],
        );

        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\ZnunyAuthenticationException::class, $exception);
        $this->assertSame('TicketGet', $exception->getOperation());
    }

    public function test_http_429_maps_to_rate_limit(): void
    {
        $exception = $this->mapper->fromHttpStatus(
            status: 429,
            body: [],
            operation: 'TicketSearch',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\ZnunyRateLimitException::class, $exception);
    }

    public function test_http_status_propagates_znuny_error_message(): void
    {
        $exception = $this->mapper->fromHttpStatus(
            status: 503,
            body: ['Error' => ['ErrorCode' => 'Backend.Offline', 'ErrorMessage' => 'Database unreachable']],
            operation: 'TicketGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException::class, $exception);
        $this->assertSame('Backend.Offline', $exception->getErrorCode());
        $this->assertStringContainsString('Database unreachable', $exception->getMessage());
    }

    public function test_from_transport_error_returns_connection_exception_with_previous(): void
    {
        $previous = new \RuntimeException('cURL error 28: connection timed out');
        $exception = $this->mapper->fromTransportError(
            previous: $previous,
            operation: 'TicketGet',
            connection: 'tenant-a',
            payload: ['TicketID' => '1'],
        );

        $this->assertInstanceOf(\ItsJustVita\LaravelZnuny\Exceptions\ZnunyConnectionException::class, $exception);
        $this->assertSame('TicketGet', $exception->getOperation());
        $this->assertSame('tenant-a', $exception->getConnection());
        $this->assertSame(['TicketID' => '1'], $exception->getRequestPayload());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
