<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;

class GenerateDynamicFieldsCommand extends Command
{
    protected $signature = 'znuny:generate-fields';

    protected $description = 'Generate a typed DynamicFields helper';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
