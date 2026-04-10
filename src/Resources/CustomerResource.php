<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Customer;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Exceptions\CustomerNotFoundException;

class CustomerResource extends Resource
{
    public function find(string $customerId): Customer
    {
        $response = $this->client->request('CustomerUserGet', ['CustomerUserID' => $customerId]);

        if (empty($response['CustomerUser'][0])) {
            $ex = new CustomerNotFoundException("Customer {$customerId} not found");
            $ex->setOperation('CustomerUserGet')
                ->setConnection($this->client->connection())
                ->setRequestPayload(['CustomerUserID' => $customerId]);

            throw $ex;
        }

        return Customer::fromZnunyResponse($response['CustomerUser'][0]);
    }

    /** @return Collection<int, Ticket> */
    public function tickets(string $customerId): Collection
    {
        return (new TicketResource($this->client))
            ->query()
            ->whereCustomerId($customerId)
            ->get();
    }
}
