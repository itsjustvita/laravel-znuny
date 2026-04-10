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
}
