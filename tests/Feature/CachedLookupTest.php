<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class CachedLookupTest extends TestCase
{
    public function test_second_queue_list_call_hits_cache(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList*' => Http::response(['Queue' => [
                ['QueueID' => '1', 'Name' => 'Support', 'GroupID' => '1', 'ValidID' => '1'],
            ]]),
        ]);

        Znuny::queues()->all();
        Znuny::queues()->all();

        Http::assertSentCount(2); // 1 SessionCreate + 1 QueueList (second call cached)
    }

    public function test_flush_invalidates_cache(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList*' => Http::response(['Queue' => []]),
        ]);

        Znuny::queues()->all();
        Znuny::flush('queues');
        Znuny::queues()->all();

        $queueListCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'QueueList'))
            ->count();

        $this->assertSame(2, $queueListCalls);
    }

    public function test_lookups_are_not_shared_between_targets_with_the_same_name(): void
    {
        Http::fake([
            'https://znuny.test/*/SessionCreate' => Http::response(['SessionID' => 'A']),
            'https://znuny.test/*/QueueList' => Http::response(['Queue' => [['QueueID' => '1', 'Name' => 'Default queue']]]),
            'https://other.test/*/SessionCreate' => Http::response(['SessionID' => 'B']),
            'https://other.test/*/QueueList' => Http::response(['Queue' => [['QueueID' => '7', 'Name' => 'Other queue']]]),
        ]);

        Znuny::queues()->all();
        $queues = Znuny::build([
            'name' => 'default',
            'base_url' => 'https://other.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'someone',
            'password' => 'else',
            'routes' => 'operation-name',
        ])->queues()->all();

        $this->assertSame(['Other queue'], $queues->pluck('name')->all());
    }

    public function test_flush_all_clears_the_lookups_of_every_configured_connection(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList*' => Http::response(['Queue' => []]),
        ]);

        Znuny::queues()->all();
        Znuny::flushAll();
        Znuny::queues()->all();

        $this->assertCount(2, collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'QueueList')));
    }
}
