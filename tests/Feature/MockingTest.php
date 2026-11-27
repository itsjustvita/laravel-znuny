<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use ItsJustVita\LaravelZnuny\Builders\TicketBuilder;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Resources\SessionResource;
use ItsJustVita\LaravelZnuny\Resources\TicketResource;
use ItsJustVita\LaravelZnuny\Resources\TimeAccountingResource;
use ItsJustVita\LaravelZnuny\Resources\UserResource;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyClient;
use ItsJustVita\LaravelZnuny\ZnunyManager;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Znuny;

/**
 * Applications test with Laravel's facade mocking and with Mockery, next to Znuny::fake().
 * Both need classes that are not final, which is why the service classes stay open.
 */
final class MockingTest extends TestCase
{
    public function test_the_facade_can_be_mocked(): void
    {
        $ticket = Ticket::fromZnunyResponse(['TicketID' => '42', 'Title' => 'Mocked']);
        $tickets = Mockery::mock(TicketResource::class);
        $tickets->shouldReceive('find')->once()->with('42')->andReturn($ticket);

        Znuny::shouldReceive('tickets')->once()->andReturn($tickets);

        $this->assertSame('Mocked', Znuny::tickets()->find('42')->title);
    }

    public function test_the_manager_can_be_mocked_in_part(): void
    {
        $manager = Mockery::mock(ZnunyManager::class, [$this->app])->makePartial();
        $manager->shouldReceive('call')->once()->with('TicketSearch', ['Limit' => 1])->andReturn(['TicketID' => ['7']]);

        $this->assertSame('default', $manager->connectionName());
        $this->assertSame(['TicketID' => ['7']], $manager->call('TicketSearch', ['Limit' => 1]));
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function serviceClasses(): array
    {
        return [
            'ZnunyManager' => [ZnunyManager::class],
            'ZnunyClient' => [ZnunyClient::class],
            'TicketResource' => [TicketResource::class],
            'SessionResource' => [SessionResource::class],
            'TimeAccountingResource' => [TimeAccountingResource::class],
            'UserResource' => [UserResource::class],
            'TicketBuilder' => [TicketBuilder::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('serviceClasses')]
    public function test_service_classes_can_be_mocked(string $class): void
    {
        $this->assertFalse((new ReflectionClass($class))->isFinal());
        $this->assertInstanceOf($class, Mockery::mock($class));
    }
}
