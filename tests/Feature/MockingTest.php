<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Collection;
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
use Mockery\MockInterface;
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
        // As Laravel's partialMock() does it: without calling the constructor.
        $manager = $this->partialMock(ZnunyManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('call')->once()->with('TicketSearch', ['Limit' => 1])->andReturn(['TicketID' => ['7']]);
        });

        $this->assertSame('default', $manager->connectionName());
        $this->assertSame(['TicketID' => ['7']], $manager->call('TicketSearch', ['Limit' => 1]));
    }

    public function test_the_facade_can_be_mocked_in_part(): void
    {
        Znuny::partialMock()->shouldReceive('ping')->once()->andReturn(true);

        $this->assertTrue(Znuny::ping());
        $this->assertSame('default', Znuny::connectionName());
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2: list<mixed>, 3: mixed}> a class, one of its methods, the arguments and what the mock answers
     */
    public static function serviceClasses(): array
    {
        return [
            'ZnunyManager' => [ZnunyManager::class, 'ping', [], true],
            'ZnunyClient' => [ZnunyClient::class, 'call', ['TicketSearch', ['Limit' => 1]], ['TicketID' => ['7']]],
            'TicketResource' => [TicketResource::class, 'findMany', [['1', '2']], new Collection],
            'SessionResource' => [SessionResource::class, 'logout', [], null],
            'TimeAccountingResource' => [TimeAccountingResource::class, 'entries', ['jane', new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30')], new Collection],
            'UserResource' => [UserResource::class, 'clearOutOfOffice', ['jane'], null],
            'TicketBuilder' => [TicketBuilder::class, 'count', [], 3],
        ];
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $arguments
     */
    #[DataProvider('serviceClasses')]
    public function test_service_classes_can_be_mocked(string $class, string $method, array $arguments, mixed $answer): void
    {
        $this->assertFalse((new ReflectionClass($class))->isFinal());

        $mock = Mockery::mock($class);
        $mock->shouldReceive($method)->once()->withArgs($arguments)->andReturn($answer);

        $this->assertInstanceOf($class, $mock);
        $this->assertSame($answer, $mock->{$method}(...$arguments));
    }
}
