#!/usr/bin/env bash
#
# Deploys a published MolMeDB release on this server: checks that all its
# images exist in the registry, records the version in .env, pulls the images
# and restarts the stack. Nothing is built here. The application container
# runs the database migrations itself when it starts.
#
# Usage: docker/production/deploy.sh <version>      e.g. 1.4.0 or v1.4.0
#
# A rollback is the same command with the previous version (printed at the
# end of every deployment). Migrations are not rolled back.
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
  if ! "${docker[@]}" manifest inspect "ghcr.io/molmedb/molmedb-$image:$version" > /dev/null 2>&1; then
    echo "Image ghcr.io/molmedb/molmedb-$image:$version is not in the registry (or this server is not logged in to ghcr.io)." >&2
    exit 1
  fi
done

previous=$(grep -E '^MOLMEDB_VERSION=' .env | tail -n 1 | cut -d= -f2- || true)

# The version lives in .env, which Compose reads on its own: plain
# `docker compose up -d` later starts the same release again.
write_version() {
  local value=$1 tmp
  tmp=$(mktemp .env.deploy.XXXXXX)
  # Keeps the owner-only permissions .env should have.
  chmod --reference=.env "$tmp" 2>/dev/null || chmod 600 "$tmp"
  grep -vE '^MOLMEDB_VERSION=' .env > "$tmp" || true
  if [[ -n $value ]]; then
    echo "MOLMEDB_VERSION=$value" >> "$tmp"
  fi
  # .env is mounted into the containers as a file, so it is rewritten in
  # place: replacing it would leave them looking at the old one.
  cat "$tmp" > .env
  rm -f "$tmp"
}

restore_previous() {
  echo "Deployment of $version failed; .env points back to ${previous:-no version}." >&2
  echo "Containers that were already replaced keep running $version: check \`docker compose ps\` and deploy a working version." >&2
  write_version "$previous"
}

write_version "$version"
trap restore_previous ERR

compose=("${docker[@]}" compose -f "$compose_file")

"${compose[@]}" pull
"${compose[@]}" up -d

trap - ERR

echo
"${compose[@]}" ps
echo
echo "Deployed $version."
if [[ -n $previous && $previous != "$version" ]]; then
  echo "To go back: $0 $previous"
fi
