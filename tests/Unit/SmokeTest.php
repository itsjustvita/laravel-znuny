<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit;

use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyManager;

final class SmokeTest extends TestCase
{
    public function test_service_provider_registers_manager(): void
    {
        $manager = $this->app->make(ZnunyManager::class);

        $this->assertInstanceOf(ZnunyManager::class, $manager);
    }

    public function test_facade_resolves_the_manager(): void
    {
        $this->assertInstanceOf(
            ZnunyManager::class,
            \Znuny::getFacadeRoot(),
        );
    }
}
