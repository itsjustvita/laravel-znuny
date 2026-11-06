<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyServiceProvider;
use Znuny;

/**
 * Laravel merges a package config only at the top level, so a config file that was published
 * by an earlier release lacks nested keys that were added later.
 */
final class PublishedConfigTest extends TestCase
{
    /**
     * Register the package the way an application does whose config file was published by 0.2.1.
     */
    private function registerWithConfigPublishedBefore(): void
    {
        config([
            'znuny.http' => ['retries' => 5, 'retry_delay' => 100, 'batch_size' => 20],
            'znuny.session' => ['cache_store' => null, 'cache_prefix' => 'znuny:session:', 'ttl' => 600, 'auto_retry_on_expired' => true],
        ]);

        (new ZnunyServiceProvider($this->app))->register();
    }

    public function test_nested_keys_added_later_get_their_defaults(): void
    {
        $this->registerWithConfigPublishedBefore();

        $this->assertArrayHasKey('search_limit', config('znuny.http'));
        $this->assertNull(config('znuny.http.search_limit'));
        $this->assertTrue(config('znuny.session.per_host'));
    }

    public function test_values_of_the_published_config_are_kept(): void
    {
        $this->registerWithConfigPublishedBefore();

        $this->assertSame(5, config('znuny.http.retries'));
        $this->assertSame(20, config('znuny.http.batch_size'));
        $this->assertSame(600, config('znuny.session.ttl'));
        $this->assertSame('operation-name', config('znuny.connections.default.routes'));
    }

    public function test_their_environment_variables_work_without_republishing(): void
    {
        $_SERVER['ZNUNY_SEARCH_LIMIT'] = '750';
        $_SERVER['ZNUNY_SESSION_PER_HOST'] = 'false';

        try {
            $this->registerWithConfigPublishedBefore();

            $this->assertSame(750, Znuny::client()->settings()->searchLimit);
            $this->assertFalse(config('znuny.session.per_host'));
        } finally {
            unset($_SERVER['ZNUNY_SEARCH_LIMIT'], $_SERVER['ZNUNY_SESSION_PER_HOST']);
        }
    }
}
