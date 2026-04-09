<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use ItsJustVita\LaravelZnuny\Data\Priority;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class PriorityTest extends TestCase
{
    public function test_it_builds_from_znuny_response(): void
    {
        $priority = Priority::fromZnunyResponse([
            'PriorityID' => '3',
            'Name'       => '3 normal',
            'ValidID'    => '1',
        ]);

        $this->assertSame('3', $priority->id);
        $this->assertSame('3 normal', $priority->name);
        $this->assertTrue($priority->valid);
    }
}
