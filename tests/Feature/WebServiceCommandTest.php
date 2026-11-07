<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;

final class WebServiceCommandTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, string> operation => route
     */
    private function routes(array $definition): array
    {
        return array_map(fn (array $route): string => $route['Route'], $definition['Provider']['Transport']['Config']['RouteOperationMapping']);
    }

    public function test_it_prints_the_web_service_of_the_default_connection(): void
    {
        $this->assertSame(0, Artisan::call('znuny:webservice'));
        $output = Artisan::output();

        // The test connection uses the operation-name routes; QueueGet is no core operation.
        $this->assertSame('/TicketSearch', $this->routes(SymfonyYaml::parse($output))['TicketSearch']);
        $this->assertMatchesRegularExpression('/^# .*QueueGet/m', $output);
    }

    public function test_routes_can_be_given_without_a_connection(): void
    {
        config(['znuny.connections' => []]);

        $this->assertSame(0, Artisan::call('znuny:webservice', ['--routes' => 'generic-ticket-connector']));
        $this->assertSame('/Ticket/Search', $this->routes(SymfonyYaml::parse(Artisan::output()))['TicketSearch']);
    }

    public function test_an_unknown_connection_without_routes_fails(): void
    {
        $this->assertSame(1, Artisan::call('znuny:webservice', ['connection' => 'nope']));
        $this->assertStringContainsString('--routes', Artisan::output());
    }

    public function test_it_writes_a_file_and_explains_the_import(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'znuny-webservice-');

        try {
            $this->assertSame(0, Artisan::call('znuny:webservice', ['--routes' => 'generic-ticket-connector', '--output' => $file]));

            $this->assertSame('/Ticket/Search', $this->routes(SymfonyYaml::parseFile($file))['TicketSearch']);
            $this->assertStringContainsString('Admin::WebService::Add', Artisan::output());
        } finally {
            @unlink($file);
        }
    }

    public function test_webhooks_use_the_app_url_and_the_webhook_config(): void
    {
        config(['app.url' => 'https://app.example.com', 'znuny.webhooks.auth.token' => 'secret']);

        Artisan::call('znuny:webservice', ['--routes' => 'generic-ticket-connector', '--webhooks' => 'TicketCreate, ArticleCreate', '--with-token' => true]);
        $config = SymfonyYaml::parse(Artisan::output())['Requester']['Transport']['Config'];

        $this->assertSame('https://app.example.com', $config['Host']);
        $this->assertSame(['X-Znuny-Token' => 'secret'], $config['AdditionalHeaders']);

        Artisan::call('znuny:webservice', ['--routes' => 'generic-ticket-connector', '--webhooks' => 'TicketCreate']);
        $output = Artisan::output();

        $this->assertSame(['X-Znuny-Token' => 'CHANGE-ME'], SymfonyYaml::parse($output)['Requester']['Transport']['Config']['AdditionalHeaders']);
        $this->assertMatchesRegularExpression('/^# .*CHANGE-ME/m', $output);
    }

    public function test_webhooks_need_a_url(): void
    {
        config(['app.url' => null]);

        $this->assertSame(1, Artisan::call('znuny:webservice', ['--routes' => 'generic-ticket-connector', '--webhooks' => 'TicketCreate']));
        $this->assertStringContainsString('--webhook-url', Artisan::output());
    }
}
