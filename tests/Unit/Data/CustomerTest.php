<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use ItsJustVita\LaravelZnuny\Data\Customer;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class CustomerTest extends TestCase
{
    public function test_it_builds_from_znuny_response(): void
    {
        $customer = Customer::fromZnunyResponse([
            'CustomerID'      => '12345',
            'CustomerCompany' => 'ACME Corp',
            'UserFirstname'   => 'Jane',
            'UserLastname'    => 'Doe',
            'UserEmail'       => 'jane@acme.test',
            'UserLogin'       => 'jane@acme.test',
            'ValidID'         => '1',
        ]);

        $this->assertSame('12345', $customer->id);
        $this->assertSame('ACME Corp', $customer->company);
        $this->assertSame('Jane', $customer->firstName);
        $this->assertSame('Doe', $customer->lastName);
        $this->assertSame('jane@acme.test', $customer->email);
        $this->assertSame('jane@acme.test', $customer->login);
        $this->assertTrue($customer->valid);
    }

    public function test_it_serializes_to_array(): void
    {
        $customer = Customer::fromZnunyResponse([
            'CustomerID'      => '12345',
            'CustomerCompany' => 'ACME Corp',
            'UserFirstname'   => 'Jane',
            'UserLastname'    => 'Doe',
            'UserEmail'       => 'jane@acme.test',
            'UserLogin'       => 'jane@acme.test',
            'ValidID'         => '1',
        ]);

        $this->assertSame([
            'CustomerID'      => '12345',
            'CustomerCompany' => 'ACME Corp',
            'UserFirstname'   => 'Jane',
            'UserLastname'    => 'Doe',
            'UserEmail'       => 'jane@acme.test',
            'UserLogin'       => 'jane@acme.test',
            'ValidID'         => '1',
        ], $customer->toArray());
    }
}
