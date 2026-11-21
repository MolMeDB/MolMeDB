#!/usr/bin/env bash
#
# Deploys a published MolMeDB release on this server: checks that all its
# images exist in the registry, pulls them, records the version in .env and
# restarts the application. Nothing is built here. The application container
# runs the database migrations itself when it starts.
#
# Usage: docker/production/deploy.sh <version>      e.g. 1.4.0 or v1.4.0
#
# A rollback is the same command with the previous version (printed at the
# end of every deployment). Migrations are not rolled back.
#
# Only the images of the release are pulled. The databases, Redis and CDK
# Depict keep the image they run; update those deliberately with
# `docker compose pull <service>`.
#
# Environment:
#   COMPOSE_FILE   compose file to use (default: docker-compose-production.yaml)
#   DOCKER         docker command (default: docker; e.g. DOCKER="sudo docker")
set -euo pipefail

cd "$(dirname "$0")/../.."

version=${1:-}
version=${version#v}

if [[ ! $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Usage: $0 <version>, e.g. $0 1.4.0" >&2
  exit 2
fi

compose_file=${COMPOSE_FILE:-docker-compose-production.yaml}
read -r -a docker <<< "${DOCKER:-docker}"
images=(backend nginx frontend rdkit)
# The services that run those images.
release_services=(web storage-init app app-scheduler-worker app-queue-worker app-predictions-queue-worker frontend rdkit)
app_container=molmedb-backend
backup=.env.deploy-backup

if [[ ! -f $compose_file ]]; then
  echo "Compose file not found: $compose_file" >&2
  exit 1
fi

if [[ ! -f .env ]]; then
  echo ".env not found in $(pwd)." >&2
  exit 1
fi

# A release whose workflow failed half-way has only some of its images; the
# running stack must not be touched in that case.
echo "Checking the images of $version…"
for image in "${images[@]}"; do
  if ! error=$("${docker[@]}" manifest inspect "ghcr.io/molmedb/molmedb-$image:$version" 2>&1 > /dev/null); then
    echo "Cannot find ghcr.io/molmedb/molmedb-$image:$version in the registry:" >&2
    echo "  ${error:-no answer}" >&2
    echo "Is the release published, and is this docker command logged in to ghcr.io?" >&2
    exit 1
  fi
done

previous=$(sed -n 's/^MOLMEDB_VERSION=//p' .env | tail -n 1 | tr -d '\r')

compose=("${docker[@]}" compose -f "$compose_file")

# Pulled before .env changes: an interrupted or failed download leaves the
# server exactly as it was.
MOLMEDB_VERSION=$version "${compose[@]}" pull "${release_services[@]}"

# The version lives in .env, which Compose reads on its own: plain
# `docker compose up -d` later starts the same release again.
write_version() {
  local value=$1 tmp lines status=0
  tmp=$(mktemp .env.deploy.XXXXXX)
  chmod 600 "$tmp"

  grep -vE '^MOLMEDB_VERSION=' .env > "$tmp" || status=$?
  # grep answers 1 when no line is left; anything higher is a real error.
  if (( status > 1 )); then
    rm -f "$tmp"
    echo "Could not read .env; it was not changed." >&2
    return 1
  fi

  if [[ -n $value ]]; then
    # A last line without a line break must not swallow the new one.
    if [[ -s $tmp && -n $(tail -c 1 "$tmp") ]]; then
      echo >> "$tmp"
    fi
    echo "MOLMEDB_VERSION=$value" >> "$tmp"
  fi

  # A full disk leaves the copy short; .env must not be replaced by it.
  lines=$(grep -cvE '^MOLMEDB_VERSION=' .env || true)
  if [[ $(grep -cvE '^MOLMEDB_VERSION=' "$tmp" || true) != "$lines" ]]; then
    rm -f "$tmp"
    echo "The new .env came out incomplete (is the disk full?); it was not changed." >&2
    return 1
  fi

  # Written in place, so the file keeps its owner and permissions.
  cat "$tmp" > .env
  rm -f "$tmp"
}

restore_previous() {
  trap - ERR INT TERM HUP
  echo >&2
  echo "Deployment of $version did not finish; .env points back to ${previous:-no version}." >&2
  echo "Containers that were already replaced keep running $version: check \`docker compose ps\` and deploy a working version." >&2
  cat "$backup" > .env
  rm -f "$backup"
  exit 1
}

cp -p .env "$backup"
trap restore_previous ERR INT TERM HUP

write_version "$version"
"${compose[@]}" up -d

# `up` returns as soon as the containers are started. A failing migration
# makes the application container restart over and over, so it is watched
# for a minute before the deployment counts as done.
echo "Watching the application start…"
for _ in {1..12}; do
  sleep "${DEPLOY_WATCH_INTERVAL:-5}"
  state=$("${docker[@]}" inspect -f '{{.State.Status}} {{.RestartCount}}' "$app_container")
  if [[ $state != "running 0" ]]; then
    echo "The application container is not running steadily (state: $state). Its log:" >&2
    "${docker[@]}" logs --tail 40 "$app_container" >&2 || true
    false
  fi
done

trap - ERR INT TERM HUP
rm -f "$backup"

echo
"${compose[@]}" ps
echo
echo "Deployed $version."
if [[ -n $previous && $previous != "$version" ]]; then
  echo "To go back: $0 $previous"
fi
