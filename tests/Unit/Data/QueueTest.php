<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use ItsJustVita\LaravelZnuny\Data\Queue;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class QueueTest extends TestCase
{
    public function test_it_builds_from_znuny_response(): void
    {
        $queue = Queue::fromZnunyResponse([
            'QueueID'         => '803',
            'Name'            => 'Support',
            'GroupID'         => '1',
            'ValidID'         => '1',
            'SystemAddressID' => '1',
            'Comment'         => 'Primary support queue',
        ]);

        $this->assertSame('803', $queue->id);
        $this->assertSame('Support', $queue->name);
        $this->assertSame('1', $queue->groupId);
        $this->assertTrue($queue->valid);
        $this->assertSame('Primary support queue', $queue->comment);
    }

    public function test_invalid_queue_is_marked_invalid(): void
    {
        $queue = Queue::fromZnunyResponse([
            'QueueID' => '9',
            'Name'    => 'Old Queue',
            'GroupID' => '1',
            'ValidID' => '2',
        ]);

        $this->assertFalse($queue->valid);
    }
}
