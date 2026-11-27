<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit;

use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * The README's "Stability" section promises that these classes only change in a breaking
 * way with a new major version. Every other class says so itself with @internal.
 */
final class PublicApiTest extends TestCase
{
    private const NAMESPACE = 'ItsJustVita\\LaravelZnuny\\';

    private const PUBLIC_CLASSES = [
        'Builders\TicketBuilder',
        'Builders\TicketCreateBuilder',
        'Config\ConnectionConfig',
        'Data\Article',
        'Data\Attachment',
        'Data\DynamicField',
        'Data\HistoryEntry',
        'Data\SessionUser',
        'Data\Ticket',
        'Data\TimeAccountingEntry',
        'Events\ZnunyArticleAdded',
        'Events\ZnunyRequestFailed',
        'Events\ZnunyRequestSent',
        'Events\ZnunyResponseReceived',
        'Events\ZnunySessionRefreshed',
        'Events\ZnunyTicketCreated',
        'Events\ZnunyTicketUpdated',
        'Events\ZnunyWebhookReceived',
        'Exceptions\GenericZnunyException',
        'Exceptions\InvalidDynamicFieldException',
        'Exceptions\InvalidPriorityException',
        'Exceptions\InvalidQueueException',
        'Exceptions\InvalidStateException',
        'Exceptions\TicketNotFoundException',
        'Exceptions\ZnunyAccessDeniedException',
        'Exceptions\ZnunyAuthenticationException',
        'Exceptions\ZnunyConfigurationException',
        'Exceptions\ZnunyConnectionException',
        'Exceptions\ZnunyException',
        'Exceptions\ZnunyNotFoundException',
        'Exceptions\ZnunyRateLimitException',
        'Exceptions\ZnunyRouteNotFoundException',
        'Exceptions\ZnunySearchLimitExceededException',
        'Exceptions\ZnunyServerException',
        'Exceptions\ZnunySessionExpiredException',
        'Exceptions\ZnunyValidationException',
        'Facades\Znuny',
        'Resources\SessionResource',
        'Resources\TicketResource',
        'Resources\TimeAccountingResource',
        'Resources\UserResource',
        'Routing\Route',
        'Routing\RouteMap',
        'Routing\RoutePresets',
        'Testing\FakeResponseSequence',
        'Testing\FakeZnunyManager',
        'Testing\StrayZnunyCallException',
        'Webhooks\WebhookController',
        'ZnunyClient',
        'ZnunyManager',
        'ZnunyServiceProvider',
    ];

    public function test_every_class_is_public_or_internal(): void
    {
        $public = [];

        foreach (Finder::create()->files()->name('*.php')->in(__DIR__.'/../../src') as $file) {
            $name = str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
            $class = self::NAMESPACE.$name;

            $this->assertTrue(
                class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class),
                "src/{$file->getRelativePathname()} does not declare {$class}.",
            );

            if (! str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal')) {
                $public[] = $name;
            }
        }

        sort($public);
        $expected = self::PUBLIC_CLASSES;
        sort($expected);

        $this->assertSame(
            $expected,
            $public,
            "The public classes changed.\n"
            .'Neither listed nor @internal: '.implode(', ', array_diff($public, $expected) ?: ['none'])."\n"
            .'Listed, but gone or @internal: '.implode(', ', array_diff($expected, $public) ?: ['none'])."\n"
            ."Add a new class to PUBLIC_CLASSES and to the README's Stability section, or mark it @internal.",
        );
    }
}
