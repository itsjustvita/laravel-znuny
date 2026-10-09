<?php

namespace ItsJustVita\LaravelZnuny\Routing;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;

final class RouteMap
{
    /**
     * @param  array<string, Route>  $routes
     */
    public function __construct(
        private readonly array $routes,
        private readonly ?string $preset = null,
    ) {}

    /**
     * Build the map from a connection's "routes" value: a preset name, or an array of
     * "Operation" => "METHOD /Path/:Param" with an optional "extends" preset.
     *
     * @param  string|array<string, mixed>|null  $config
     */
    public static function fromConfig(string|array|null $config): self
    {
        if ($config === null || $config === '') {
            $config = RoutePresets::GENERIC_TICKET_CONNECTOR;
        }

        if (is_string($config)) {
            return new self(self::parseAll(RoutePresets::get($config)), $config);
        }

        $preset = null;
        $definitions = [];

        if (array_key_exists('extends', $config)) {
            $preset = (string) $config['extends'];
            $definitions = RoutePresets::get($preset);
            unset($config['extends']);
        }

        foreach ($config as $operation => $definition) {
            if (! is_string($operation) || ! is_string($definition)) {
                throw new ZnunyConfigurationException(
                    'Znuny routes must map operation names to definitions like "GET /Ticket/:TicketID".'
                );
            }

            $definitions[$operation] = $definition;
        }

        return new self(self::parseAll($definitions), $preset);
    }

    /**
     * The route for an operation; unmapped operations are sent to "POST /<Operation>".
     */
    public function route(string $operation): Route
    {
        return $this->routes[$operation] ?? new Route('POST', '/'.$operation);
    }

    public function has(string $operation): bool
    {
        return isset($this->routes[$operation]);
    }

    /** @return array<string, Route> */
    public function all(): array
    {
        return $this->routes;
    }

    public function preset(): ?string
    {
        return $this->preset;
    }

    /**
     * @param  array<string, string>  $definitions
     * @return array<string, Route>
     */
    private static function parseAll(array $definitions): array
    {
        return array_map(fn (string $definition): Route => Route::parse($definition), $definitions);
    }
}
