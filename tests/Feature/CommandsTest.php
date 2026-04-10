<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class CommandsTest extends TestCase
{
    public function test_test_connection_command_reports_success(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList*' => Http::response(['Queue' => []]),
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

    public function test_cache_clear_command_flushes_default_connection(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList*' => Http::response(['Queue' => [
                ['QueueID' => '1', 'Name' => 'Support', 'GroupID' => '1', 'ValidID' => '1'],
            ]]),
        ]);

        // Populate cache
        \Znuny::queues()->all();
        $this->assertNotNull(Cache::get('znuny:lookup:default:queues'));

        $this->artisan('znuny:cache-clear')->assertExitCode(0);

        $this->assertNull(Cache::get('znuny:lookup:default:queues'));
    }

    public function test_cache_clear_all_flag_flushes_every_connection(): void
    {
        config()->set('znuny.connections.tenant-a', [
            'base_url' => 'https://tenant-a.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'u', 'password' => 'p', 'verify_ssl' => false, 'timeout' => 5,
        ]);

        $this->artisan('znuny:cache-clear --all')->assertExitCode(0);
    }

    public function test_generate_fields_writes_helper_class(): void
    {
        $target = storage_path('app/znuny-test/DynamicFields.php');
        @unlink($target);
        @mkdir(dirname($target), 0777, true);

        config()->set('znuny.dynamic_fields', [
            'CustomerSegment' => ['type' => 'string', 'allowed' => ['business', 'private']],
            'OrderId' => ['type' => 'string'],
            'Priority' => ['type' => 'integer'],
        ]);

        $this->artisan('znuny:generate-fields', ['--output' => $target])
            ->assertExitCode(0);

        $this->assertFileExists($target);
        $contents = file_get_contents($target);
        $this->assertStringContainsString('public function customerSegment(', $contents);
        $this->assertStringContainsString('public function orderId(', $contents);
        $this->assertStringContainsString('public function priority(', $contents);
        $this->assertStringContainsString('namespace App\\Znuny;', $contents);
    }
}
