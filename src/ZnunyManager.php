<?php

namespace ItsJustVita\LaravelZnuny;

use Illuminate\Contracts\Foundation\Application;

class ZnunyManager
{
    public function __construct(
        protected Application $app,
    ) {}
}
