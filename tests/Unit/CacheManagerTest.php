<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use ItsJustVita\LaravelZnuny\Caching\CacheManager;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class CacheManagerTest extends TestCase
{
    public function test_remember_caches_and_returns_value(): void
    {
        $manager = new CacheManager(
            cache: Cache::store('array'),
            prefix: 'znuny:lookup:',
            ttls: ['queues' => 3600],
            enabled: true,
        );

        $calls = 0;
        $resolver = function () use (&$calls): array {
            $calls++;
            return [['QueueID' => '1']];
        };

        $first  = $manager->remember('default', 'queues', $resolver);
        $second = $manager->remember('default', 'queues', $resolver);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function test_disabled_cache_always_calls_resolver(): void
    {
        $manager = new CacheManager(
            cache: Cache::store('array'),
            prefix: 'znuny:lookup:',
            ttls: ['queues' => 3600],
            enabled: false,
        );

        $calls = 0;
        $resolver = function () use (&$calls): array {
            $calls++;
            return [];
        };

        $manager->remember('default', 'queues', $resolver);
        $manager->remember('default', 'queues', $resolver);

        $this->assertSame(2, $calls);
    }

    public function test_flush_removes_single_resource_for_connection(): void
    {
        $manager = new CacheManager(Cache::store('array'), 'znuny:lookup:', ['queues' => 3600, 'states' => 3600], true);

        $manager->remember('default', 'queues', fn () => ['q']);
        $manager->remember('default', 'states', fn () => ['s']);

        $manager->flush('default', 'queues');

        $calls = 0;
        $manager->remember('default', 'queues', function () use (&$calls) { $calls++; return ['q']; });
        $this->assertSame(1, $calls);

        // states still cached
        $calls2 = 0;
        $manager->remember('default', 'states', function () use (&$calls2) { $calls2++; return ['s']; });
        $this->assertSame(0, $calls2);
    }

    public function test_flush_all_removes_every_resource_for_connection(): void
    {
        $manager = new CacheManager(Cache::store('array'), 'znuny:lookup:', ['queues' => 3600, 'states' => 3600], true);

        $manager->remember('default', 'queues', fn () => ['q']);
        $manager->remember('default', 'states', fn () => ['s']);

        $manager->flush('default');

        $qCalls = $sCalls = 0;
        $manager->remember('default', 'queues', function () use (&$qCalls) { $qCalls++; return ['q']; });
        $manager->remember('default', 'states', function () use (&$sCalls) { $sCalls++; return ['s']; });

        $this->assertSame(1, $qCalls);
        $this->assertSame(1, $sCalls);
    }
}
