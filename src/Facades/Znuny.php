<?php

namespace ItsJustVita\LaravelZnuny\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ItsJustVita\LaravelZnuny\ZnunyManager connection(?string $name = null)
 * @see \ItsJustVita\LaravelZnuny\ZnunyManager
 */
class Znuny extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'znuny';
    }
}
