<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Http;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Http\QueryString;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class QueryStringTest extends TestCase
{
    public function test_scalars_are_raw_url_encoded(): void
    {
        $this->assertSame(
            'Title=Hello%20World%2B1&Queue=Raw%2FSub',
            QueryString::build(['Title' => 'Hello World+1', 'Queue' => 'Raw/Sub']),
        );
    }

    public function test_lists_become_repeated_keys(): void
    {
        $this->assertSame('States=new&States=open&Limit=10', QueryString::build(['States' => ['new', 'open'], 'Limit' => 10]));
    }

    public function test_booleans_become_one_and_zero_and_empty_values_are_skipped(): void
    {
        $this->assertSame('A=1&B=0', QueryString::build(['A' => true, 'B' => false, 'C' => null, 'D' => []]));
    }

    public function test_nested_structures_are_rejected_with_a_hint(): void
    {
        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage("routes['TicketSearch'] = 'POST /TicketSearch'");

        QueryString::build(['DynamicField_Order' => ['Equals' => 'X']], 'TicketSearch');
    }

    public function test_lists_of_arrays_are_rejected(): void
    {
        $this->expectException(ZnunyConfigurationException::class);

        QueryString::build(['Foo' => [['a' => 1]]], 'TicketSearch');
    }
}
