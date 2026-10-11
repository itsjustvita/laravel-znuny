# Integration tests

The integration suite runs the package against a real Znuny with the stock `GenericTicketConnectorREST` web service. It is skipped unless `ZNUNY_INTEGRATION_URL` is set.

## Start Znuny in Docker

Requires Docker with Compose v2. The setup uses the community image [`cygnusnetworks/znuny`](https://hub.docker.com/r/cygnusnetworks/znuny), which is not an official Znuny image.

```bash
tests/Integration/docker/setup.sh                                          # Znuny 7.3.7 on http://localhost:8080
ZNUNY_IMAGE=cygnusnetworks/znuny:6.5.22 tests/Integration/docker/setup.sh  # another version
```

## Run the suite

```bash
ZNUNY_INTEGRATION_URL=http://localhost:8080/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorREST \
ZNUNY_INTEGRATION_USERNAME=api-agent \
ZNUNY_INTEGRATION_PASSWORD=api-secret \
ZNUNY_INTEGRATION_CUSTOMER_USER=jane \
ZNUNY_INTEGRATION_CUSTOMER_PASSWORD=jane-secret \
vendor/bin/phpunit --testsuite Integration
```

## Capture a webhook payload

The setup also creates the web service `LaravelWebhook`. Its `Ticket::Generic` invoker posts every `TicketCreate` event to `http://host.docker.internal:8099/znuny/webhooks/TicketCreate` with the header `X-Znuny-Token: integration-token`.

```bash
php -S 0.0.0.0:8099 tests/Integration/docker/capture.php
# create a ticket, e.g. by running the integration suite, then inspect tests/Integration/docker/captured/
```

## Stop

```bash
docker compose -p laravel-znuny-integration -f tests/Integration/docker/docker-compose.yml down -v
```
