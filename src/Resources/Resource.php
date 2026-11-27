<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use ItsJustVita\LaravelZnuny\ZnunyClient;

/**
 * @internal Not covered by the package's stability promise.
 */
abstract class Resource
{
    /**
     * @internal
     */
    public function __construct(
        protected readonly ZnunyClient $client,
    ) {}
}
