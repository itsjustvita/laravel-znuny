<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Routing\Route;
use ItsJustVita\LaravelZnuny\Routing\RouteMap;

class RoutesCommand extends Command
{
    protected $signature = 'znuny:routes {connection? : Connection name (defaults to the default connection)}';

    protected $description = 'Show the REST routes a Znuny connection uses';

    public function handle(): int
    {
        $name = (string) ($this->argument('connection') ?? config('znuny.default', 'default'));
        $config = config("znuny.connections.{$name}");

        try {
            if (! is_array($config)) {
                throw new ZnunyConfigurationException("Znuny connection [{$name}] is not configured.");
            }

            // Only the route map is needed, so incomplete credentials do not matter here.
            $routes = RouteMap::fromConfig($config['routes'] ?? null);
        } catch (ZnunyConfigurationException $e) {
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
