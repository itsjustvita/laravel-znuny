<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\ZnunyManager;

class TestConnectionCommand extends Command
{
    protected $signature = 'znuny:test-connection {connection? : Connection name (defaults to the default connection)}';

    protected $description = 'Check credentials, routing and permissions of a Znuny connection';

    public function handle(ZnunyManager $manager): int
    {
        $name = (string) ($this->argument('connection') ?? config('znuny.default', 'default'));

        try {
            $client = $manager->connection($name)->client();
            $settings = $client->settings();

            $this->components->twoColumnDetail('Base URL', $settings->baseUrl);
            $this->components->twoColumnDetail('Routes', $settings->routes->preset() ?? 'custom');
            $this->components->twoColumnDetail('Auth', "{$settings->auth}, {$settings->userType}, transport {$settings->authTransport}");

            if ($settings->usesSession()) {
                $client->ping();
                $this->components->twoColumnDetail('SessionCreate', '<fg=green;options=bold>OK</>');
            }

            $client->call('TicketSearch', ['Limit' => 1]);
            $this->components->twoColumnDetail('TicketSearch', '<fg=green;options=bold>OK</>');
        } catch (ZnunyException $e) {
            $this->error("Znuny connection [{$name}] FAILED: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Znuny connection [{$name}] OK");

        return self::SUCCESS;
    }
}
