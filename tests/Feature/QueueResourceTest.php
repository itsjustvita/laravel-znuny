<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\Queue;
use ItsJustVita\LaravelZnuny\Exceptions\QueueNotFoundException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class QueueResourceTest extends TestCase
{
    public function test_all_returns_collection_of_queue_dtos(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList*'    => Http::response(['Queue' => [
                ['QueueID' => '1',   'Name' => 'Postmaster', 'GroupID' => '1', 'ValidID' => '1'],
                ['QueueID' => '803', 'Name' => 'Support',    'GroupID' => '1', 'ValidID' => '1'],
            ]]),
        ]);

        $queues = Znuny::queues()->all();

        $this->assertCount(2, $queues);
        $this->assertInstanceOf(Queue::class, $queues->first());
    }

    public function test_find_by_id(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueGet*'     => Http::response(['Queue' => [[
                'QueueID' => '803', 'Name' => 'Support', 'GroupID' => '1', 'ValidID' => '1',
            ]]]),
        ]);

        $queue = Znuny::queues()->find('803');

        $this->assertInstanceOf(Queue::class, $queue);
        $this->assertSame('Support', $queue->name);
    }

    public function test_find_by_id_throws_when_missing(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueGet*'     => Http::response(['Queue' => []]),
        ]);

        $this->expectException(QueueNotFoundException::class);
        Znuny::queues()->find('404');
    }

    public function test_find_by_name_scans_queue_list(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList*'    => Http::response(['Queue' => [
                ['QueueID' => '1',   'Name' => 'Postmaster', 'GroupID' => '1', 'ValidID' => '1'],
                ['QueueID' => '803', 'Name' => 'Support',    'GroupID' => '1', 'ValidID' => '1'],
            ]]),
        ]);

        $queue = Znuny::queues()->findByName('Support');

        $this->assertSame('803', $queue->id);
    }

    public function test_find_by_name_throws_when_missing(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList*'    => Http::response(['Queue' => [
                ['QueueID' => '1', 'Name' => 'Postmaster', 'GroupID' => '1', 'ValidID' => '1'],
            ]]),
        ]);

        $this->expectException(QueueNotFoundException::class);
        Znuny::queues()->findByName('Nonexistent');
    }
}
