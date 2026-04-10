<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;

class CacheClearCommand extends Command
{
    protected $signature = 'znuny:cache-clear';

    protected $description = 'Clear Znuny lookup caches';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
