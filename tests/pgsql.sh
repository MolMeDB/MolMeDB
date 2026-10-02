#!/usr/bin/env bash
#
# Runs the test suite against PostgreSQL with the Bingo extension, the same way
# the Backend CI job does, using the local development database container.
#
# The test database (molmedb_testing) is created next to the development
# database on first run; the development database itself is never touched.
#
# Usage: tests/pgsql.sh [php artisan test arguments], e.g. tests/pgsql.sh --filter=PublicApi
set -euo pipefail

cd "$(dirname "$0")/.."

container=${TEST_DB_CONTAINER:-molmedb-dev-db}
database=${TEST_DB_DATABASE:-molmedb_testing}

env_value() {
  grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

db_username=$(env_value DB_USERNAME)

if ! docker exec "$container" true 2>/dev/null; then
  echo "Database container '${container}' is not running." >&2
  exit 1
fi

if [[ -z $(docker exec "$container" psql -U "$db_username" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '${database}'") ]]; then
  echo "Creating test database ${database}…"
  docker exec "$container" psql -q -U "$db_username" -d postgres -c "CREATE DATABASE ${database}"
  docker exec -e PGOPTIONS="-c client_min_messages=warning" "$container" psql -q -v ON_ERROR_STOP=1 -U "$db_username" -d "$database" -f /opt/bingo/bingo_install.sql >/dev/null
fi

export DB_CONNECTION=pgsql
export DB_HOST=$(env_value DB_HOST)
export DB_PORT=$(env_value DB_PORT)
export DB_DATABASE=$database
export DB_USERNAME=$db_username
export DB_PASSWORD=$(env_value DB_PASSWORD)
export DB_PREDICTIONS_DRIVER=sqlite
export DB_PREDICTIONS_DATABASE=:memory:

php artisan test "$@"
