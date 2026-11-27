<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit;

use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
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

    /**
     * Public members of public classes that the promise leaves out. Every other public
     * member of a public class is covered.
     */
    private const INTERNAL_MEMBERS = [
        'Builders\TicketBuilder' => ['__construct()'],
        'Builders\TicketCreateBuilder' => ['__construct()'],
        'Config\ConnectionConfig' => ['__construct()', 'fromArray()', 'identityKey()', 'loginKey()', 'options()', 'password()', 'timezoneFor()'],
        'Resources\TicketResource' => ['fetchOptions()'],
        'Routing\Route' => ['__construct()', 'parse()'],
        'Routing\RouteMap' => ['__construct()', 'fromConfig()'],
        'Routing\RoutePresets' => ['get()', 'names()'],
        'Testing\FakeResponseSequence' => ['__construct()', 'isEmpty()', 'next()'],
        'Testing\FakeZnunyManager' => ['__construct()'],
        'ZnunyClient' => ['__construct()', 'cachedSessionId()', 'expecting()', 'forgetSession()', 'identityKey()', 'warn()'],
        'ZnunyManager' => ['__construct()'],
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

    /**
     * Marking a covered member @internal takes it out of the promise, which is a breaking
     * change; taking the marker off one adds it to the promise. Neither should happen in passing.
     */
    public function test_the_internal_members_of_the_public_classes_are_the_listed_ones(): void
    {
        $internal = [];

        foreach (self::PUBLIC_CLASSES as $name) {
            $class = new ReflectionClass(self::NAMESPACE.$name);
            $members = [];

            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $members[$method->getName().'()'] = $method;
            }

            foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $members['$'.$property->getName()] = $property;
            }

            foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
                $members[$constant->getName()] = $constant;
            }

            $marked = array_keys(array_filter(
                $members,
                fn (ReflectionMethod|ReflectionProperty|ReflectionClassConstant $member): bool => $member->getDeclaringClass()->getName() === $class->getName()
                    && str_contains((string) $member->getDocComment(), '@internal'),
            ));

            if ($marked !== []) {
                sort($marked);
                $internal[$name] = $marked;
            }
        }

        $describe = fn (array $list): string => implode(', ', array_map(
            fn (string $class, array $members): string => $class.': '.implode(' ', $members),
            array_keys($list),
            $list,
        )) ?: 'none';

        $added = [];
        $removed = [];

        foreach (array_keys($internal + self::INTERNAL_MEMBERS) as $name) {
            $now = $internal[$name] ?? [];
            $listed = self::INTERNAL_MEMBERS[$name] ?? [];

            if ($diff = array_values(array_diff($now, $listed))) {
                $added[$name] = $diff;
            }

            if ($diff = array_values(array_diff($listed, $now))) {
                $removed[$name] = $diff;
            }
        }

        $this->assertSame(
            self::INTERNAL_MEMBERS,
            $internal,
            "The members marked @internal changed.\n"
            .'Newly @internal (taken out of the stability promise): '.$describe($added)."\n"
            .'No longer @internal, or gone (now covered by the promise): '.$describe($removed)."\n"
            .'If that is meant, update INTERNAL_MEMBERS; taking a member out of the promise needs a major version.',
        );
    }
}
