<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use ItsJustVita\LaravelZnuny\ZnunyClient;

abstract class Resource
{
    public function __construct(
        protected readonly ZnunyClient $client,
    ) {}
}
