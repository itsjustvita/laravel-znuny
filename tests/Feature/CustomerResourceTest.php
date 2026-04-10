<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\Customer;
use ItsJustVita\LaravelZnuny\Exceptions\CustomerNotFoundException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class CustomerResourceTest extends TestCase
{
    public function test_find_returns_customer_dto(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*CustomerUserGet*' => Http::response(['CustomerUser' => [[
                'CustomerID' => '12345',
                'CustomerCompany' => 'ACME',
                'UserFirstname' => 'Jane',
                'UserLastname' => 'Doe',
                'UserEmail' => 'jane@acme.test',
                'UserLogin' => 'jane@acme.test',
                'ValidID' => '1',
            ]]]),
        ]);

        $customer = Znuny::customers()->find('12345');

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertSame('12345', $customer->id);
    }

    public function test_find_throws_when_not_found(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*CustomerUserGet*' => Http::response(['CustomerUser' => []]),
        ]);

        $this->expectException(CustomerNotFoundException::class);
        Znuny::customers()->find('404');
    }

    public function test_tickets_shortcut_delegates_to_ticket_builder(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch*' => Http::response(['TicketID' => ['5']]),
            '*TicketGet*' => Http::response(['Ticket' => [[
                'TicketID' => '5',
                'TicketNumber' => '00005',
                'Title' => 't',
                'QueueID' => '1',
                'Queue' => 'Q',
                'State' => 'open',
                'Priority' => '3 normal',
                'Created' => '2026-04-08 10:00:00',
            ]]]),
        ]);

        $tickets = Znuny::customers()->tickets('12345');

        $this->assertCount(1, $tickets);
        $this->assertSame('5', $tickets->first()->id);
    }
}
