<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Exceptions;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class ZnunyExceptionTest extends TestCase
{
    public function test_it_carries_contextual_metadata(): void
    {
        $exception = new class('boom') extends ZnunyException {};
        $exception
            ->setOperation('TicketGet')
            ->setErrorCode('TicketGet.AuthFail')
            ->setConnection('tenant-a')
            ->setRequestPayload(['SessionID' => 'REDACT', 'TicketID' => '42'])
            ->setResponseBody(['Error' => ['ErrorCode' => 'X']]);

        $this->assertSame('TicketGet', $exception->getOperation());
        $this->assertSame('TicketGet.AuthFail', $exception->getErrorCode());
        $this->assertSame('tenant-a', $exception->getConnection());
        $payload = $exception->getRequestPayload();
        $this->assertArrayHasKey('SessionID', $payload);
        $this->assertSame('[REDACTED]', $payload['SessionID']);
        $this->assertSame(['Error' => ['ErrorCode' => 'X']], $exception->getResponseBody());
    }

    public function test_it_sanitizes_case_insensitively(): void
    {
        $exception = new class('boom') extends ZnunyException {};
        $exception->setRequestPayload(['password' => 'secret', 'Password' => 'secret', 'SESSIONID' => 'x']);

        $payload = $exception->getRequestPayload();
        $this->assertSame('[REDACTED]', $payload['password']);
        $this->assertSame('[REDACTED]', $payload['Password']);
        $this->assertSame('[REDACTED]', $payload['SESSIONID']);
    }

    public function test_it_sanitizes_nested_payloads(): void
    {
        $exception = new class('boom') extends ZnunyException {};
        $exception->setRequestPayload([
            'Ticket' => [
                'Title' => 'ok',
                'Auth'  => ['Password' => 'hunter2', 'SessionID' => 'abc'],
            ],
            'TopLevel' => ['nested' => ['Token' => 'xyz']],
        ]);

        $payload = $exception->getRequestPayload();
        $this->assertSame('ok', $payload['Ticket']['Title']);
        $this->assertSame('[REDACTED]', $payload['Ticket']['Auth']['Password']);
        $this->assertSame('[REDACTED]', $payload['Ticket']['Auth']['SessionID']);
        $this->assertSame('[REDACTED]', $payload['TopLevel']['nested']['Token']);
    }
}
