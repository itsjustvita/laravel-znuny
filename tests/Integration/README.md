# Integration tests

The integration suite runs the package against a real Znuny. It is skipped unless `ZNUNY_INTEGRATION_URL` is set.

## Start Znuny in Docker

Requires Docker with Compose v2. The setup uses the community image [`cygnusnetworks/znuny`](https://hub.docker.com/r/cygnusnetworks/znuny), which is not an official Znuny image.

```bash
tests/Integration/docker/setup.sh                                          # Znuny 7.3.7 on http://localhost:8080
ZNUNY_IMAGE=cygnusnetworks/znuny:6.5.22 tests/Integration/docker/setup.sh  # another version
```

The setup creates:

- the agent `api-agent` (password `api-secret`) and the customer user `jane` (password `jane-secret`, customer `acme`);
- the ticket dynamic fields `OrderId` (text) and `Tags` (multiselect with `alpha`, `beta`, `gamma`);
- the web services `GenericTicketConnectorREST` (the Ready2Adopt example), `GenericTicketConnectorRESTClassic` (the example from `development/webservices/`, downloaded from GitHub for the image's version) and `OperationNameREST` (routes named after the operations, as the `operation-name` preset expects);
- the web service `LaravelWebhook`. Its `Ticket::Generic` invoker posts every `TicketCreate` event to `http://host.docker.internal:8099/znuny/webhooks/TicketCreate` with the header `X-Znuny-Token: integration-token`.

## Run the suite

```bash
ZNUNY_INTEGRATION_URL=http://localhost:8080/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorREST \
ZNUNY_INTEGRATION_CLASSIC_URL=http://localhost:8080/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorRESTClassic \
ZNUNY_INTEGRATION_OPERATION_NAME_URL=http://localhost:8080/otrs/nph-genericinterface.pl/Webservice/OperationNameREST \
ZNUNY_INTEGRATION_USERNAME=api-agent \
ZNUNY_INTEGRATION_PASSWORD=api-secret \
ZNUNY_INTEGRATION_CUSTOMER_USER=jane \
ZNUNY_INTEGRATION_CUSTOMER_PASSWORD=jane-secret \
ZNUNY_INTEGRATION_WEBHOOK_LISTEN=0.0.0.0:8099 \
vendor/bin/phpunit --testsuite Integration
```

Only `ZNUNY_INTEGRATION_URL`, `ZNUNY_INTEGRATION_USERNAME` and `ZNUNY_INTEGRATION_PASSWORD` are required; tests that need another variable are skipped without it. Against your own Znuny the tests expect the queue `Raw`, the states `new` and `open`, the priority `3 normal` and the dynamic fields above. Set `ZNUNY_INTEGRATION_TIMEZONE` if its `OTRSTimeZone` is not UTC.

With `ZNUNY_INTEGRATION_WEBHOOK_LISTEN`, the webhook test serves `tests/Integration/webhook-server.php` (a Laravel application with the package's webhook endpoint) on that address with `php -S`, creates a ticket and waits for the `ZnunyWebhookReceived` event. Znuny must reach the address as `host.docker.internal:8099`.

## Capture a webhook payload

To inspect the raw requests of the `LaravelWebhook` web service instead:

```bash
php -S 0.0.0.0:8099 tests/Integration/docker/capture.php
# create a ticket, e.g. by running the suite without ZNUNY_INTEGRATION_WEBHOOK_LISTEN, then inspect tests/Integration/docker/captured/
```

## Stop

```bash
docker compose -p laravel-znuny-integration -f tests/Integration/docker/docker-compose.yml down -v
```
