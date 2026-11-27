<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelZnuny\ZnunyManager;

class CacheClearCommand extends Command
{
    protected $signature = 'znuny:cache-clear
        {connection? : The connection whose cache should be cleared (defaults to default connection)}
        {--resource= : Optional resource name (queues, states, priorities, types)}
        {--all : Clear cache for every configured connection}';

    protected $description = 'Clear Znuny lookup caches (deprecated, removed in 1.0 with the lookups)';

    public function handle(ZnunyManager $manager): int
    {
        $this->warn('znuny:cache-clear is deprecated and will be removed in 1.0, together with the lookups whose cache it clears: they need operations Znuny does not ship.');

        if ($this->option('all')) {
            $manager->flushAll();
            $this->info('Znuny lookup caches cleared for all connections.');

            return self::SUCCESS;
        }

        $connection = (string) ($this->argument('connection') ?? config('znuny.default', 'default'));
        $resource = $this->option('resource');
        $resource = is_string($resource) && $resource !== '' ? $resource : null;

        $manager->connection($connection)->flush($resource);
        $label = $resource !== null ? "{$resource} " : '';
        $this->info("Znuny {$label}cache cleared for connection [{$connection}].");

        return self::SUCCESS;
    }
}
