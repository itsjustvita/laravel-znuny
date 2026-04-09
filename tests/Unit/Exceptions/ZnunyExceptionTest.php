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
        $this->assertArrayNotHasKey('SessionID', $exception->getRequestPayload());
        $this->assertSame(['Error' => ['ErrorCode' => 'X']], $exception->getResponseBody());
    }
}
