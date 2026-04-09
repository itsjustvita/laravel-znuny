<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use ItsJustVita\LaravelZnuny\Data\State;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class StateTest extends TestCase
{
    public function test_it_builds_from_znuny_response(): void
    {
        $state = State::fromZnunyResponse([
            'StateID'  => '4',
            'Name'     => 'open',
            'TypeName' => 'open',
            'ValidID'  => '1',
        ]);

        $this->assertSame('4', $state->id);
        $this->assertSame('open', $state->name);
        $this->assertSame('open', $state->typeName);
        $this->assertTrue($state->valid);
    }

    public function test_it_serializes_to_array(): void
    {
        $state = State::fromZnunyResponse([
            'StateID'  => '4',
            'Name'     => 'open',
            'TypeName' => 'open',
            'ValidID'  => '1',
        ]);

        $this->assertSame([
            'StateID'  => '4',
            'Name'     => 'open',
            'TypeName' => 'open',
            'ValidID'  => '1',
        ], $state->toArray());
    }
}
