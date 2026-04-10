<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use ItsJustVita\LaravelZnuny\Data\Type;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class TypeTest extends TestCase
{
    public function test_it_builds_from_znuny_response(): void
    {
        $type = Type::fromZnunyResponse([
            'TypeID' => '2',
            'Name' => 'Incident',
            'ValidID' => '1',
        ]);

        $this->assertSame('2', $type->id);
        $this->assertSame('Incident', $type->name);
        $this->assertTrue($type->valid);
    }

    public function test_it_serializes_to_array(): void
    {
        $type = Type::fromZnunyResponse([
            'TypeID' => '2',
            'Name' => 'Incident',
            'ValidID' => '1',
        ]);

        $this->assertSame([
            'TypeID' => '2',
            'Name' => 'Incident',
            'ValidID' => '1',
        ], $type->toArray());
    }
}
