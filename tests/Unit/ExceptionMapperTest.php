<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit;

use ItsJustVita\LaravelZnuny\Exceptions\CustomerNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ExceptionMapper;
use ItsJustVita\LaravelZnuny\Exceptions\GenericZnunyException;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidDynamicFieldException;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidPriorityException;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidQueueException;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidStateException;
use ItsJustVita\LaravelZnuny\Exceptions\QueueNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAccessDeniedException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAuthenticationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConnectionException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyRateLimitException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyRouteNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunySessionExpiredException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyValidationException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExceptionMapperTest extends TestCase
{
    private ExceptionMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ExceptionMapper;
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

        $this->assertInstanceOf(InvalidStateException::class, $exception);
    }

    public function test_it_maps_invalid_priority(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketCreate.InvalidPriority', 'ErrorMessage' => 'Invalid priority']],
            operation: 'TicketCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(InvalidPriorityException::class, $exception);
    }

    public function test_it_maps_invalid_dynamic_field(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketCreate.InvalidDynamicField', 'ErrorMessage' => 'Invalid DynamicField Foo']],
            operation: 'TicketCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(InvalidDynamicFieldException::class, $exception);
    }

    public function test_it_maps_customer_not_found(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'CustomerUserGet.NotFound', 'ErrorMessage' => 'Customer not found']],
            operation: 'CustomerUserGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(CustomerNotFoundException::class, $exception);
    }

    public function test_unknown_not_found_falls_back_to_base_not_found(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'SomeUnknownOp.NotFound', 'ErrorMessage' => 'nope']],
            operation: 'SomeUnknownOp',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(ZnunyNotFoundException::class, $exception);
        $this->assertNotInstanceOf(TicketNotFoundException::class, $exception);
    }

    public function test_unknown_error_code_falls_back_to_generic(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketCreate.MysteryError', 'ErrorMessage' => 'Something unusual']],
            operation: 'TicketCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(GenericZnunyException::class, $exception);
        $this->assertInstanceOf(ZnunyException::class, $exception);
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

        $this->assertInstanceOf(ZnunyAuthenticationException::class, $exception);
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

        $this->assertInstanceOf(ZnunyRateLimitException::class, $exception);
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

        $this->assertInstanceOf(ZnunyServerException::class, $exception);
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

        $this->assertInstanceOf(ZnunyConnectionException::class, $exception);
        $this->assertSame('TicketGet', $exception->getOperation());
        $this->assertSame('tenant-a', $exception->getConnection());
        $this->assertSame(['TicketID' => '1'], $exception->getRequestPayload());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_access_denied_maps_to_access_denied_exception(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketGet.AccessDenied', 'ErrorMessage' => 'TicketGet: User does not have access to the ticket!']],
            operation: 'TicketGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(ZnunyAccessDeniedException::class, $exception);
        $this->assertSame('TicketGet.AccessDenied', $exception->getErrorCode());
    }

    public function test_not_valid_ticket_id_maps_to_ticket_not_found(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketGet.NotValidTicketID', 'ErrorMessage' => 'TicketGet: Could not get Ticket data']],
            operation: 'TicketGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(TicketNotFoundException::class, $exception);
    }

    public function test_not_ticket_data_maps_to_ticket_not_found_for_history(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketHistoryGet.NotTicketData', 'ErrorMessage' => 'TicketHistoryGet: no data']],
            operation: 'TicketHistoryGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(TicketNotFoundException::class, $exception);
    }

    #[DataProvider('validationErrors')]
    public function test_validation_codes_map_to_specific_exceptions(string $code, string $message, string $class): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => $code, 'ErrorMessage' => $message]],
            operation: 'TicketCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertSame($class, $exception::class);
    }

    public static function validationErrors(): array
    {
        return [
            'queue' => ['TicketCreate.InvalidParameter', 'TicketCreate: Ticket->QueueID or Ticket->Queue parameter is invalid!', InvalidQueueException::class],
            'state' => ['TicketUpdate.InvalidParameter', 'TicketUpdate: Ticket->StateID or Ticket->State parameter is invalid!', InvalidStateException::class],
            'priority' => ['TicketCreate.InvalidParameter', 'TicketCreate: Ticket->PriorityID or Ticket->Priority parameter is invalid!', InvalidPriorityException::class],
            'dynamic field' => ['TicketCreate.InvalidParameter', 'TicketCreate: DynamicField->Value parameter is invalid!', InvalidDynamicFieldException::class],
            'missing parameter' => ['TicketCreate.MissingParameter', 'TicketCreate: Ticket->Title parameter is missing!', ZnunyValidationException::class],
            'wrong structure' => ['TicketGet.WrongStructure', 'TicketGet: Structure for Ticket is not correct!', ZnunyValidationException::class],
            'empty request' => ['TicketUpdate.EmptyRequest', 'TicketUpdate: The request data is invalid!', ZnunyValidationException::class],
        ];
    }

    public function test_auth_fail_in_credentials_mode_is_an_authentication_error(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketGet.AuthFail', 'ErrorMessage' => 'TicketGet: Authorization failing!']],
            operation: 'TicketGet',
            connection: 'default',
            payload: [],
            sessionAuth: false,
        );

        $this->assertInstanceOf(ZnunyAuthenticationException::class, $exception);
        $this->assertNotInstanceOf(ZnunySessionExpiredException::class, $exception);
    }

    public function test_webservice_invalid_configuration_is_a_server_error(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'Webservice.InvalidConfiguration', 'ErrorMessage' => 'Broken']],
            operation: 'TicketGet',
            connection: 'default',
            payload: [],
        );

        $this->assertInstanceOf(ZnunyServerException::class, $exception);
    }

    public function test_unknown_error_code_on_a_5xx_falls_back_to_the_status(): void
    {
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'Backend.Offline', 'ErrorMessage' => 'down']],
            operation: 'TicketGet',
            connection: 'default',
            payload: [],
            httpStatus: 503,
        );

        $this->assertInstanceOf(ZnunyServerException::class, $exception);
        $this->assertSame(503, $exception->getHttpStatus());
    }

    public function test_from_response_detects_missing_routes(): void
    {
        $body = "HTTP::REST Error while determine Operation for request URI '/QueueList'.";

        $exception = $this->mapper->fromResponse(500, null, $body, 'QueueList', 'default', [], true, 'POST', '/QueueList');

        $this->assertInstanceOf(ZnunyRouteNotFoundException::class, $exception);
        $this->assertStringContainsString('POST /QueueList', $exception->getMessage());
        $this->assertStringContainsString('php artisan znuny:routes default', $exception->getMessage());
        $this->assertSame(500, $exception->getHttpStatus());
        $this->assertSame(['raw' => $body], $exception->getResponseBody());
    }

    public function test_from_response_maps_404_to_route_not_found(): void
    {
        $exception = $this->mapper->fromResponse(404, null, 'Not Found', 'TicketGet', 'default', [], true, 'GET', '/Ticket/1');

        $this->assertInstanceOf(ZnunyRouteNotFoundException::class, $exception);
    }

    public function test_from_response_reads_the_faultstring(): void
    {
        $exception = $this->mapper->fromResponse(
            500,
            ['faultcode' => 'Server', 'faultstring' => 'Operation failed!'],
            '{"faultcode":"Server","faultstring":"Operation failed!"}',
            'TicketCreate', 'default', [], true, 'POST', '/Ticket',
        );

        $this->assertInstanceOf(ZnunyServerException::class, $exception);
        $this->assertSame('[HTTP 500] Operation failed!', $exception->getMessage());
    }

    public function test_from_response_maps_401_without_body_to_authentication(): void
    {
        $exception = $this->mapper->fromResponse(401, null, '', 'TicketSearch', 'default', [], true, 'GET', '/Ticket');

        $this->assertInstanceOf(ZnunyAuthenticationException::class, $exception);
        $this->assertSame('[HTTP 401] TicketSearch failed', $exception->getMessage());
    }

    public function test_from_response_maps_other_4xx_to_generic(): void
    {
        $exception = $this->mapper->fromResponse(400, null, 'Bad Request', 'TicketSearch', 'default', [], true, 'GET', '/Ticket');

        $this->assertSame(GenericZnunyException::class, $exception::class);
        $this->assertSame(['raw' => 'Bad Request'], $exception->getResponseBody());
    }

    public function test_from_response_rejects_non_json_success_bodies(): void
    {
        $exception = $this->mapper->fromResponse(200, null, '<html>proxy</html>', 'TicketSearch', 'default', [], true, 'GET', '/Ticket');

        $this->assertInstanceOf(ZnunyServerException::class, $exception);
        $this->assertStringContainsString('unexpected non-JSON response', $exception->getMessage());
    }

    public function test_from_response_maps_error_bodies_with_a_success_status(): void
    {
        $exception = $this->mapper->fromResponse(
            200,
            ['Error' => ['ErrorCode' => 'TicketGet.AccessDenied', 'ErrorMessage' => 'denied']],
            '{}',
            'TicketGet', 'default', ['TicketID' => '1'], true, 'GET', '/Ticket/1',
        );

        $this->assertInstanceOf(ZnunyAccessDeniedException::class, $exception);
        $this->assertSame(200, $exception->getHttpStatus());
        $this->assertSame(['TicketID' => '1'], $exception->getRequestPayload());
    }

    public function test_exact_validation_codes_win_over_message_heuristics(): void
    {
        // A custom operation may say "not found" in the message of a validation error.
        $exception = $this->mapper->fromErrorResponse(
            body: ['Error' => ['ErrorCode' => 'TicketCreate.InvalidParameter', 'ErrorMessage' => 'TicketCreate: Ticket->Owner parameter is invalid, user not found!']],
            operation: 'TicketCreate',
            connection: 'default',
            payload: [],
        );

        $this->assertSame(ZnunyValidationException::class, $exception::class);
    }
}
