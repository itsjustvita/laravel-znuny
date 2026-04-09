<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Type;

class TypeResource extends Resource
{
    /** @return Collection<int, Type> */
    public function all(): Collection
    {
        $response = $this->client->request('TypeList', []);

        return collect($response['Type'] ?? [])
            ->map(fn (array $row) => Type::fromZnunyResponse($row))
            ->values();
    }
}
