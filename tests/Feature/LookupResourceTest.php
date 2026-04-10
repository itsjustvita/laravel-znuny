<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\Priority;
use ItsJustVita\LaravelZnuny\Data\State;
use ItsJustVita\LaravelZnuny\Data\Type;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class LookupResourceTest extends TestCase
{
    public function test_states_all(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*StateList*' => Http::response(['State' => [
                ['StateID' => '1', 'Name' => 'new',  'TypeName' => 'new',  'ValidID' => '1'],
                ['StateID' => '4', 'Name' => 'open', 'TypeName' => 'open', 'ValidID' => '1'],
            ]]),
        ]);

        $states = Znuny::states()->all();

        $this->assertCount(2, $states);
        $this->assertInstanceOf(State::class, $states->first());
    }

    public function test_priorities_all(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*PriorityList*' => Http::response(['Priority' => [
                ['PriorityID' => '3', 'Name' => '3 normal', 'ValidID' => '1'],
            ]]),
        ]);

        $priorities = Znuny::priorities()->all();

        $this->assertCount(1, $priorities);
        $this->assertInstanceOf(Priority::class, $priorities->first());
    }

    public function test_types_all(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TypeList*' => Http::response(['Type' => [
                ['TypeID' => '1', 'Name' => 'Unclassified', 'ValidID' => '1'],
                ['TypeID' => '2', 'Name' => 'Incident',     'ValidID' => '1'],
            ]]),
        ]);

        $types = Znuny::types()->all();

        $this->assertCount(2, $types);
        $this->assertInstanceOf(Type::class, $types->first());
    }
}
