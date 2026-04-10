<?php

namespace ItsJustVita\LaravelZnuny\Facades;

use Illuminate\Support\Facades\Facade;
use ItsJustVita\LaravelZnuny\ZnunyManager;

/**
 * @method static \ItsJustVita\LaravelZnuny\ZnunyManager connection(?string $name = null)
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
