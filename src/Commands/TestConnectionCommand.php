<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\ZnunyManager;

class TestConnectionCommand extends Command
{
    protected $signature = 'znuny:test-connection {connection?}';

    protected $description = 'Verify Znuny REST credentials by calling SessionCreate';

    public function handle(ZnunyManager $manager): int
    {
        $connection = (string) ($this->argument('connection') ?? config('znuny.default', 'default'));

        try {
            $manager->connection($connection)->ping();
            $this->info("Znuny connection [{$connection}] OK");

            return self::SUCCESS;
        } catch (ZnunyException $e) {
            $this->error("Znuny connection [{$connection}] FAILED: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
