<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class CommandsTest extends TestCase
{
    public function test_test_connection_command_reports_success(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList*'    => Http::response(['Queue' => []]),
        ]);

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('Znuny connection [default] OK')
            ->assertExitCode(0);
    }

    public function test_test_connection_command_reports_failure(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['Error' => ['ErrorCode' => 'SessionCreate.AuthFail', 'ErrorMessage' => 'Wrong password']]),
        ]);

        $this->artisan('znuny:test-connection')
            ->expectsOutputToContain('Znuny connection [default] FAILED')
            ->assertExitCode(1);
    }
}
