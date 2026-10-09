<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\Routing\Route;
use ItsJustVita\LaravelZnuny\ZnunyManager;

class RoutesCommand extends Command
{
    protected $signature = 'znuny:routes {connection? : Connection name (defaults to the default connection)}';

    protected $description = 'Show the REST routes a Znuny connection uses';

    public function handle(ZnunyManager $manager): int
    {
        $name = (string) ($this->argument('connection') ?? config('znuny.default', 'default'));

        try {
            $routes = $manager->connection($name)->client()->routes();
        } catch (ZnunyException $e) {
            $this->error("Znuny connection [{$name}] FAILED: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line("Connection [{$name}] uses preset ".($routes->preset() ?? 'none (custom routes)').'.');

        $this->table(
            ['Operation', 'Method', 'Path'],
            collect($routes->all())
                ->map(fn (Route $route, string $operation): array => [$operation, $route->method, $route->path])
                ->values()
                ->all(),
        );

        $this->line('Operations without a mapping are sent to POST /<Operation>.');

        return self::SUCCESS;
    }
}
