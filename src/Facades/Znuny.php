<?php

namespace ItsJustVita\LaravelZnuny\Facades;

use Illuminate\Support\Facades\Facade;
use ItsJustVita\LaravelZnuny\ZnunyManager;

/**
 * @method static \ItsJustVita\LaravelZnuny\ZnunyManager connection(?string $name = null)
 * @method static \ItsJustVita\LaravelZnuny\ZnunyManager build(array $config)
 * @method static \ItsJustVita\LaravelZnuny\ZnunyManager usingCredentials(string $baseUrl, string $username, string $password, bool $verifySSL = true, int $timeout = 30, array $options = [])
 * @method static string connectionName()
 * @method static \ItsJustVita\LaravelZnuny\ZnunyClient client()
 * @method static array call(string $operation, array $payload = [])
 * @method static \ItsJustVita\LaravelZnuny\Resources\TicketResource tickets()
 * @method static \ItsJustVita\LaravelZnuny\Resources\QueueResource queues()
 * @method static \ItsJustVita\LaravelZnuny\Resources\StateResource states()
 * @method static \ItsJustVita\LaravelZnuny\Resources\PriorityResource priorities()
 * @method static \ItsJustVita\LaravelZnuny\Resources\TypeResource types()
 * @method static \ItsJustVita\LaravelZnuny\Resources\CustomerResource customers()
 * @method static bool ping()
 * @method static void flush(?string $resource = null)
 * @method static void flushAll()
 *
 * @see ZnunyManager
 */
class Znuny extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'znuny';
    }
}
