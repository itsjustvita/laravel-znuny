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

    public function test_resolve_returns_business_queue_id(): void
    {
        config()->set('znuny.queues.resolution.business.relocation', '901');
        config()->set('znuny.queues.resolution.private.relocation', '902');

        $this->assertSame('901', Znuny::queues()->resolve('relocation', businessCustomer: true));
        $this->assertSame('902', Znuny::queues()->resolve('relocation', businessCustomer: false));
    }

    public function test_resolve_falls_back_to_default_when_not_configured(): void
    {
        config()->set('znuny.queues.default', '803');

        $this->assertSame('803', Znuny::queues()->resolve('unknown_type', businessCustomer: true));
    }

    public function test_resolve_works_for_all_common_ticket_types(): void
    {
        config()->set('znuny.queues.resolution.business', [
            'data_changes'      => '100',
            'relocation'        => '101',
            'cancellation'      => '102',
            'tariff_change'     => '103',
            'contract_transfer' => '104',
        ]);

        $this->assertSame('100', Znuny::queues()->resolve('data_changes', businessCustomer: true));
        $this->assertSame('101', Znuny::queues()->resolve('relocation', businessCustomer: true));
        $this->assertSame('102', Znuny::queues()->resolve('cancellation', businessCustomer: true));
        $this->assertSame('103', Znuny::queues()->resolve('tariff_change', businessCustomer: true));
        $this->assertSame('104', Znuny::queues()->resolve('contract_transfer', businessCustomer: true));
    }
}
