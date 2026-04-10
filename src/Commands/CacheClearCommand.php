<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelZnuny\ZnunyManager;

class CacheClearCommand extends Command
{
    protected $signature = 'znuny:cache-clear
        {connection? : The connection whose cache should be cleared (defaults to default connection)}
        {--resource= : Optional resource name (queues, states, priorities, types, customers)}
        {--all : Clear cache for every configured connection}';

    protected $description = 'Clear Znuny lookup caches';

    public function handle(ZnunyManager $manager): int
    {
        if ($this->option('all')) {
            $manager->flushAll();
            $this->info('Znuny lookup caches cleared for all connections.');

            return self::SUCCESS;
        }

        $connection = (string) ($this->argument('connection') ?? config('znuny.default', 'default'));
        $resource = $this->option('resource');

        $manager->connection($connection)->flush($resource);
        $label = $resource ? "{$resource} " : '';
        $this->info("Znuny {$label}cache cleared for connection [{$connection}].");

        return self::SUCCESS;
    }
}
