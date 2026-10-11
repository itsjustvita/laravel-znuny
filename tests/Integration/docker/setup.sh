#!/usr/bin/env bash
# Starts Znuny + MariaDB, installs the database, creates an API agent and a customer user,
# and imports the stock GenericTicketConnectorREST web service plus a webhook requester.
set -euo pipefail

cd "$(dirname "$0")"

export ZNUNY_IMAGE="${ZNUNY_IMAGE:-cygnusnetworks/znuny:7.3.7}"
export ZNUNY_PLATFORM="${ZNUNY_PLATFORM:-linux/amd64}"
compose() { docker compose -p laravel-znuny-integration "$@"; }

compose up -d db

echo "Waiting for MariaDB..."
until compose exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do
  sleep 2
done

if ! compose exec -T db mariadb -uznuny -pznuny znuny -e 'SELECT 1 FROM valid LIMIT 1' >/dev/null 2>&1; then
  echo "Installing the Znuny database..."
  for file in schema.mysql.sql initial_insert.mysql.sql schema-post.mysql.sql; do
    docker run --rm --platform "$ZNUNY_PLATFORM" --entrypoint cat "$ZNUNY_IMAGE" "/opt/otrs/scripts/database/$file" \
      | compose exec -T db mariadb -uznuny -pznuny znuny
  done
fi

compose create znuny
compose cp ./Config.pm znuny:/opt/otrs/Kernel/Config.pm
compose start znuny

echo "Waiting for Znuny..."
for attempt in $(seq 1 200); do
  curl -fsS http://localhost:8080/otrs/index.pl >/dev/null 2>&1 && break
  if [ "$(docker inspect -f '{{.State.Running}}' "$(compose ps -aq znuny)")" != "true" ]; then
    echo "The Znuny container stopped:" >&2
    compose logs --tail 30 znuny >&2
    exit 1
  fi
  sleep 3
done
curl -fsS http://localhost:8080/otrs/index.pl >/dev/null || { echo "Znuny did not answer in time." >&2; exit 1; }

console() { compose exec -T -u otrs znuny /opt/otrs/bin/otrs.Console.pl "$@"; }

console Admin::User::Add --user-name api-agent --first-name API --last-name Agent \
  --email-address api-agent@example.com --password api-secret || true
console Admin::Group::UserLink --user-name api-agent --group-name users --permission rw || true
console Admin::CustomerUser::Add --user-name jane --first-name Jane --last-name Doe \
  --email-address jane@example.com --customer-id acme --password jane-secret || true
console Admin::WebService::Add --name GenericTicketConnectorREST \
  --source-path /opt/otrs/var/webservices/examples/GenericTicketConnectorREST.yml || true

# The classic example (development/webservices) is not part of the release; fetch it from GitHub.
classic=$(mktemp)
curl -fsSL "https://raw.githubusercontent.com/znuny/Znuny/${ZNUNY_GIT_REF:-rel-7_3_7}/development/webservices/GenericTicketConnectorREST.yml" -o "$classic"
chmod 644 "$classic"
compose cp "$classic" znuny:/tmp/GenericTicketConnectorRESTClassic.yml
rm -f "$classic"
console Admin::WebService::Add --name GenericTicketConnectorRESTClassic \
  --source-path /tmp/GenericTicketConnectorRESTClassic.yml || true

compose cp ./webhook-requester.yml znuny:/tmp/webhook-requester.yml
console Admin::WebService::Add --name LaravelWebhook --source-path /tmp/webhook-requester.yml || true

echo "Checking the API login..."
curl -fsS -X POST -H 'Content-Type: application/json' \
  -d '{"UserLogin":"api-agent","Password":"api-secret"}' \
  http://localhost:8080/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorREST/Session \
  | grep -q SessionID || { echo "The API agent cannot log in; see the output above." >&2; exit 1; }

cat <<'TEXT'

Znuny is ready:

  ZNUNY_INTEGRATION_URL=http://localhost:8080/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorREST
  ZNUNY_INTEGRATION_CLASSIC_URL=http://localhost:8080/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorRESTClassic
  ZNUNY_INTEGRATION_USERNAME=api-agent
  ZNUNY_INTEGRATION_PASSWORD=api-secret
  ZNUNY_INTEGRATION_CUSTOMER_USER=jane
  ZNUNY_INTEGRATION_CUSTOMER_PASSWORD=jane-secret

TEXT
