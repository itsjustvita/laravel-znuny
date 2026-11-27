<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAccessDeniedException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Znuny;

final class UserResourceTest extends TestCase
{
    private function fakeZnuny(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*/OutOfOffice' => Http::response(['Success' => 1]),
        ]);
    }

    /**
     * The request to OutOfOffice.
     */
    private function sent(): Request
    {
        return collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])
            ->firstOrFail(fn (Request $request): bool => str_ends_with($request->url(), '/OutOfOffice'));
    }

    public function test_set_out_of_office_sends_entries_and_csv(): void
    {
        $this->fakeZnuny();

        Znuny::users()->setOutOfOffice('jane.agent', CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-14'));

        // Znuny's own web service builds the entries from the CSV string; a web service without that mapping reads the entries.
        $this->assertSame(
            [['UserLogin' => 'jane.agent', 'StartDate' => '2026-07-01', 'EndDate' => '2026-07-14', 'OutOfOffice' => 1]],
            $this->sent()['OutOfOfficeEntries'],
        );
        $this->assertSame(
            "UserLogin,UserSearch,UserEmail,StartDate,EndDate,OutOfOffice\n\"jane.agent\",\"\",\"\",\"2026-07-01\",\"2026-07-14\",\"1\"\n",
            $this->sent()['OutOfOfficeEntriesCSVString'],
        );
    }

    public function test_days_are_the_days_the_caller_named(): void
    {
        $this->fakeZnuny();

        // Half past midnight in Tokyo is the day before in UTC, which is Znuny's time zone here.
        Znuny::users()->setOutOfOffice(
            'jane.agent',
            CarbonImmutable::parse('2026-07-01 00:30', 'Asia/Tokyo'),
            CarbonImmutable::parse('2026-07-14 23:30', 'America/New_York'),
        );

        $this->assertSame(['2026-07-01', '2026-07-14'], [$this->sent()['OutOfOfficeEntries'][0]['StartDate'], $this->sent()['OutOfOfficeEntries'][0]['EndDate']]);
    }

    public function test_user_values_are_trimmed(): void
    {
        $this->fakeZnuny();

        Znuny::users()->updateOutOfOffice([
            ['login' => ' jane.agent ', 'enabled' => false],
            ['email' => "\tbob@example.com ", 'enabled' => false],
            ['search' => ' Miller ', 'enabled' => false],
        ]);

        $this->assertSame(
            [['UserLogin' => 'jane.agent', 'OutOfOffice' => 0], ['UserEmail' => 'bob@example.com', 'OutOfOffice' => 0], ['UserSearch' => 'Miller', 'OutOfOffice' => 0]],
            $this->sent()['OutOfOfficeEntries'],
        );
        $this->assertStringContainsString('"jane.agent","",""', $this->sent()['OutOfOfficeEntriesCSVString']);
    }

    public function test_a_single_day_is_a_valid_range(): void
    {
        $this->fakeZnuny();

        // Also when the time of the start is later than the time of the end on that day.
        Znuny::users()->setOutOfOffice('jane.agent', CarbonImmutable::parse('2026-07-01 18:00'), CarbonImmutable::parse('2026-07-01 08:00'));

        $this->assertSame(['2026-07-01', '2026-07-01'], [$this->sent()['OutOfOfficeEntries'][0]['StartDate'], $this->sent()['OutOfOfficeEntries'][0]['EndDate']]);
    }

    public function test_clear_out_of_office_sends_no_dates(): void
    {
        $this->fakeZnuny();

        Znuny::users()->clearOutOfOffice('jane.agent');

        $this->assertSame([['UserLogin' => 'jane.agent', 'OutOfOffice' => 0]], $this->sent()['OutOfOfficeEntries']);
    }

    public function test_several_users_by_login_email_or_search(): void
    {
        $this->fakeZnuny();

        Znuny::users()->updateOutOfOffice([
            ['login' => 'jane.agent', 'from' => CarbonImmutable::parse('2026-07-01'), 'until' => CarbonImmutable::parse('2026-07-14')],
            ['email' => 'bob@example.com', 'enabled' => true],
            ['search' => 'Miller', 'enabled' => false],
        ]);

        $this->assertSame([
            ['UserLogin' => 'jane.agent', 'StartDate' => '2026-07-01', 'EndDate' => '2026-07-14', 'OutOfOffice' => 1],
            ['UserEmail' => 'bob@example.com', 'OutOfOffice' => 1],
            ['UserSearch' => 'Miller', 'OutOfOffice' => 0],
        ], $this->sent()['OutOfOfficeEntries']);
        $this->assertCount(4, explode("\n", trim($this->sent()['OutOfOfficeEntriesCSVString'])));
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: string}>
     */
    public static function badEntries(): array
    {
        return [
            'no entries' => [[], 'at least one'],
            'no user in the second entry' => [[['login' => 'jane.agent'], ['enabled' => false]], 'entry 2'],
            'two ways to name the user' => [[['login' => 'jane.agent', 'email' => 'jane@example.com']], 'entry 1'],
            'a blank login' => [[['login' => ' ']], 'entry 1'],
            'a date that is no date' => [[['login' => 'jane.agent', 'from' => '2026-07-01']], 'entry 1'],
            'an entry that is no array' => [['jane.agent'], 'entry 1'],
            // Znuny drops an end date that comes without a start date.
            'an end without a start' => [[['login' => 'jane.agent', 'until' => new \DateTimeImmutable('2026-07-14')]], '[from] and [until]'],
            'a start without an end' => [[['login' => 'jane.agent', 'from' => new \DateTimeImmutable('2026-07-01')]], '[from] and [until]'],
            'enabled as text' => [[['login' => 'jane.agent', 'enabled' => 'false']], '[enabled]'],
            'a start after the end' => [[['login' => 'jane.agent', 'from' => new \DateTimeImmutable('2026-07-14'), 'until' => new \DateTimeImmutable('2026-07-01')]], 'is after its [until]'],
            // Znuny's mapping reads the entries from CSV lines and drops lines that start with "#".
            'a line break in the search' => [[['search' => "Miller\n#x"]], 'line break'],
        ];
    }

    /**
     * @param  list<mixed>  $entries
     */
    #[DataProvider('badEntries')]
    public function test_entries_that_cannot_work_are_refused_before_anything_is_sent(array $entries, string $hint): void
    {
        Http::fake();

        try {
            Znuny::users()->updateOutOfOffice($entries);
            $this->fail('The entries must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($hint, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_user_outside_the_admin_group_is_denied_without_a_new_login(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*/OutOfOffice' => Http::response(['Error' => ['ErrorCode' => 'OutOfOffice.AuthFail', 'ErrorMessage' => 'OutOfOffice: User needs to be in group admin.']]),
        ]);

        try {
            Znuny::users()->clearOutOfOffice('jane.agent');
            $this->fail('Znuny refused the call.');
        } catch (ZnunyAccessDeniedException $e) {
            $this->assertSame('OutOfOffice.AuthFail', $e->getErrorCode());
        }

        // An AuthFail usually means an expired session; this one must not cause a new login and a repeat.
        Http::assertSentCount(2);
    }
}
