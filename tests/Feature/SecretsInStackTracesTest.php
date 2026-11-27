<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use stdClass;
use Symfony\Component\Finder\Finder;
use Throwable;
use Znuny;

/**
 * A stack trace lists the arguments of every call, and error reporters record them. The
 * password, the session ID and the secrets in the request options must not be among the
 * arguments of the package's own calls: PHP replaces a parameter marked
 * #[\SensitiveParameter] with a placeholder.
 */
final class SecretsInStackTracesTest extends TestCase
{
    private const NAMESPACE = 'ItsJustVita\\LaravelZnuny\\';

    private const PASSWORD = 'pw-9f3c-top-secret';

    private const SESSION = 'sess-7a1d-top-secret';

    private const PROXY = 'proxy-5b2e-top-secret';

    private const SECRETS = [self::PASSWORD, self::SESSION, self::PROXY];

    private const BASE_URL = 'https://znuny.test/otrs/nph-genericinterface.pl/Webservice/test';

    private const DENIED = ['Error' => ['ErrorCode' => 'TicketGet.AccessDenied', 'ErrorMessage' => 'TicketGet: User does not have access to the ticket!']];

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        parent::setUp();

        // php.ini-production leaves the arguments out of every trace; many servers do not.
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        config(['znuny.connections.default.password' => self::PASSWORD]);
    }

    protected function tearDown(): void
    {
        if ($this->ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: Closure(): mixed}> what fails, each with secrets in play
     */
    public static function failures(): array
    {
        $session = fn (): array => ['*SessionCreate' => Http::response(['SessionID' => self::SESSION])];
        $credentials = fn () => config(['znuny.connections.default.auth' => 'credentials']);

        return [
            'a connection with an invalid setting' => [function (): void {
                Znuny::usingCredentials(self::BASE_URL, 'agent', self::PASSWORD, options: ['auth' => 'nonsense'])->tickets();
            }],
            'a configured connection with an invalid setting' => [function (): void {
                config(['znuny.connections.default.user_type' => 'nonsense']);
                Znuny::tickets();
            }],
            'the same under Znuny::fake()' => [function (): void {
                config(['znuny.connections.default.user_type' => 'nonsense']);
                Znuny::fake();
                Znuny::tickets();
            }],
            'a search limit that is no number' => [function (): void {
                config(['znuny.connections.default.search_limit' => 'many']);
                Znuny::tickets();
            }],
            'request options with a missing certificate' => [function (): void {
                config(['znuny.http.options' => ['headers' => ['X-Proxy-Token' => self::PROXY]]]);
                Znuny::build([
                    'base_url' => self::BASE_URL,
                    'username' => 'agent',
                    'password' => self::PASSWORD,
                    'options' => ['auth' => ['proxyuser', self::PROXY], 'ssl_key' => ['/nonexistent.key', self::PROXY]],
                ])->tickets();
            }],
            'request options with a header the package sets itself' => [function (): void {
                Znuny::build([
                    'base_url' => self::BASE_URL,
                    'username' => 'agent',
                    'password' => self::PASSWORD,
                    'options' => ['headers' => ['X-Proxy-Token' => self::PROXY, 'Content-Type' => 'text/plain']],
                ])->tickets();
            }],
            'a runtime connection that cannot be named' => [function (): void {
                Znuny::usingCredentials(self::BASE_URL, 'agent', self::PASSWORD, options: ['base_url' => new stdClass]);
            }],
            'an error from Znuny with a session' => [function () use ($session): void {
                Http::fake($session() + ['*' => Http::response(self::DENIED)]);
                Znuny::tickets()->find('1');
            }],
            'a failed login' => [function (): void {
                Http::fake(['*' => Http::response(['Error' => ['ErrorCode' => 'SessionCreate.AuthFail', 'ErrorMessage' => 'SessionCreate: Authorization failing!']])]);
                Znuny::tickets()->find('1');
            }],
            'a login that returns no session' => [function (): void {
                Http::fake(['*' => Http::response('{}')]);
                Znuny::tickets()->find('1');
            }],
            'an error from Znuny with credentials in every request' => [function () use ($credentials): void {
                $credentials();
                Http::fake(['*' => Http::response(self::DENIED)]);
                Znuny::tickets()->find('1');
            }],
            'a server error' => [function () use ($credentials): void {
                $credentials();
                config(['znuny.connections.default.retries' => 0]);
                Http::fake(['*' => Http::response('Bad Gateway', 502)]);
                Znuny::tickets()->find('1');
            }],
            'a connection that fails' => [function () use ($credentials): void {
                $credentials();
                config(['znuny.connections.default.retries' => 0]);
                Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
                Znuny::tickets()->find('1');
            }],
            'a route whose parameter is missing' => [function () use ($credentials): void {
                $credentials();
                Znuny::call('TicketGet');
            }],
            'a route whose parameter is missing, with a session' => [function () use ($session): void {
                Http::fake($session());
                Znuny::call('TicketGet');
            }],
            'an exception of the HTTP client that the package does not catch' => [function () use ($credentials): void {
                $credentials();
                Http::fake(fn () => throw new RuntimeException('The HTTP client is misconfigured.'));
                Znuny::tickets()->find('1');
            }],
            'the same with the session in the request' => [function () use ($session): void {
                Http::fake($session() + ['*' => fn () => throw new RuntimeException('The HTTP client is misconfigured.')]);
                Znuny::tickets()->find('1');
            }],
            'a nested value on a GET route, with the credentials in the query string' => [function () use ($credentials): void {
                $credentials();
                config([
                    'znuny.connections.default.auth_transport' => 'payload',
                    'znuny.connections.default.routes' => ['extends' => 'operation-name', 'TicketSearch' => 'GET /Ticket'],
                ]);
                Znuny::call('TicketSearch', ['DynamicField_Field' => ['Equals' => ['a' => 'b']]]);
            }],
            'a cache that cannot store the session' => [function () use ($session): void {
                $cache = Mockery::mock(Repository::class);
                $cache->shouldReceive('get')->andReturnNull();
                // Built when it is thrown: an exception's trace is that of the place it was created.
                $cache->shouldReceive('put')->andReturnUsing(fn () => throw new RuntimeException('The cache is read-only.'));
                Cache::shouldReceive('store')->andReturn($cache);

                Http::fake($session());
                Znuny::tickets()->find('1');
            }],
        ];
    }

    /**
     * @param  Closure(): mixed  $failure
     */
    #[DataProvider('failures')]
    public function test_the_package_keeps_secrets_out_of_its_stack_frames(Closure $failure): void
    {
        try {
            $failure();
        } catch (Throwable $e) {
            $this->assertSame([], $this->framesWithSecrets($e), $e::class.': '.$e->getMessage());

            return;
        }

        $this->fail('The scenario must fail.');
    }

    /**
     * The names say what the parameter carries, so a new one cannot be forgotten.
     */
    public function test_parameters_named_after_a_secret_are_marked(): void
    {
        $names = ['password', 'passphrase', 'secret', 'token', 'sessionid', 'auth', 'credentials'];
        $unmarked = [];
        $marked = 0;

        foreach (Finder::create()->files()->name('*.php')->in(__DIR__.'/../../src') as $file) {
            $class = new ReflectionClass(self::NAMESPACE.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4)));

            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    if (! in_array(strtolower($parameter->getName()), $names, true)) {
                        continue;
                    }

                    // ConnectionConfig's $auth is the mode, "session" or "credentials".
                    if ($parameter->getName() === 'auth' && (string) $parameter->getType() !== 'array') {
                        continue;
                    }

                    if ($parameter->getAttributes(SensitiveParameter::class) === []) {
                        $unmarked[] = $this->describe($method).' $'.$parameter->getName();
                    } else {
                        $marked++;
                    }
                }
            }
        }

        $this->assertSame([], $unmarked, 'These parameters carry a secret and need #[\SensitiveParameter].');
        $this->assertGreaterThan(0, $marked);
    }

    /**
     * @return list<string> the package's calls whose arguments hold a secret, e.g. "ZnunyClient->send() argument 3"
     */
    private function framesWithSecrets(Throwable $e): array
    {
        $found = [];

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            foreach ($current->getTrace() as $frame) {
                $class = $frame['class'] ?? '';

                if (! str_starts_with($class, self::NAMESPACE) || str_starts_with($class, self::NAMESPACE.'Tests\\')) {
                    continue;
                }

                foreach (array_values($frame['args'] ?? []) as $position => $argument) {
                    if ($this->holdsSecret($argument)) {
                        $found[] = sprintf('%s%s%s() argument %d', substr($class, strlen(self::NAMESPACE)), $frame['type'] ?? '::', $frame['function'], $position + 1);
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Strings, arrays at any depth and the public properties of objects: what an error
     * reporter writes down for an argument.
     */
    private function holdsSecret(mixed $value, int $depth = 0): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof Closure || $depth > 5) {
            return false;
        }

        if (is_string($value)) {
            foreach (self::SECRETS as $secret) {
                if (str_contains($value, $secret)) {
                    return true;
                }
            }

            return false;
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($this->holdsSecret($key, $depth + 1) || $this->holdsSecret($item, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function describe(ReflectionMethod $method): string
    {
        return substr($method->getDeclaringClass()->getName(), strlen(self::NAMESPACE)).'::'.$method->getName().'()';
    }
}
