#!/usr/bin/env bash
#
# Deploys a published MolMeDB release on this server: checks that all its
# images exist in the registry, pulls them, records the version in .env and
# restarts the application. Nothing is built here. The application container
# runs the database migrations itself when it starts.
#
# Usage: deploy.sh <version>      e.g. 1.4.0 or v1.4.0
#
# The script works in two places:
#   - in a deployment directory that holds nothing but compose.yaml, .env,
#     frontend/.env, storage/ and this script. The compose file of the
#     release is then downloaded from the release on GitHub, so the services
#     always match the images;
#   - in a checkout of the repository, as docker/production/deploy.sh. The
#     checkout's docker-compose-production.yaml is used as it is.
#
# A rollback is the same command with the previous version (printed at the
# end of every deployment). Migrations are not rolled back.
#
# Only the images of the release are pulled. The databases, Redis and CDK
# Depict keep the image they run; update those deliberately with
# `docker compose pull <service>`.
#
# Environment:
#   COMPOSE_FILE          compose file to use (default: compose.yaml in a
#                         deployment directory, docker-compose-production.yaml
#                         in a checkout)
#   DOCKER                docker command (default: docker; e.g. DOCKER="sudo docker")
#   MOLMEDB_KEEP_COMPOSE  1 = do not download the compose file of the release,
#                         use the one that is there
#   RELEASE_URL           where the release files are downloaded from
#                         (default: https://github.com/MolMeDB/MolMeDB/releases/download)
#   DEPLOY_MARKER         a file that is created right before the containers
#                         are replaced; until it exists, a failed deployment
#                         has changed nothing that runs
set -euo pipefail

script_dir=$(cd "$(dirname "$0")" && pwd)

if [[ -f $script_dir/../../docker-compose-production.yaml && ! -f $script_dir/compose.yaml ]]; then
  standalone=0
  cd "$script_dir/../.."
  compose_file=${COMPOSE_FILE:-docker-compose-production.yaml}
else
  standalone=1
  cd "$script_dir"
  compose_file=${COMPOSE_FILE:-compose.yaml}
fi

version=${1:-}
version=${version#v}

if [[ ! $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Usage: $0 <version>, e.g. $0 1.4.0" >&2
  exit 2
fi

read -r -a docker <<< "${DOCKER:-docker}"
release_url=${RELEASE_URL:-https://github.com/MolMeDB/MolMeDB/releases/download}
images=(backend nginx frontend rdkit)
# The services that run those images.
release_services=(web storage-init app app-scheduler-worker app-queue-worker app-predictions-queue-worker frontend rdkit)
app_container=molmedb-backend
backup=.env.deploy-backup
compose_backup=$compose_file.deploy-backup
new_compose=

download_compose=0
if (( standalone )) && [[ ${MOLMEDB_KEEP_COMPOSE:-0} != 1 ]]; then
  download_compose=1
fi

if (( ! download_compose )) && [[ ! -f $compose_file ]]; then
  echo "Compose file not found: $(pwd)/$compose_file" >&2
  exit 1
fi

if [[ ! -f .env ]]; then
  echo ".env not found in $(pwd)." >&2
  exit 1
fi

# A deployment that was killed leaves these behind. Putting them back later
# would bring in settings from another day, so a person decides.
for leftover in "$backup" "$compose_backup"; do
  if [[ -e $leftover ]]; then
    echo "Found $(pwd)/$leftover: an earlier deployment did not finish." >&2
    echo "Compare it with the file it is a copy of, keep the right one and remove the copy. Then run this again." >&2
    exit 1
  fi
done

# 1 from the moment .env or the compose file may differ from what runs.
changing=0

# Whatever ends the script, the settings must not point at a release that
# did not come up.
on_exit() {
  local status=$?
  set +e
  trap '' INT TERM HUP PIPE
  trap - EXIT

  if [[ -n $new_compose ]]; then
    rm -f "$new_compose"
  fi

  if (( changing )); then
    echo >&2
    echo "Deployment of $version did not finish; .env points back to ${previous:-no version}." >&2
    echo "Containers that were already replaced keep running $version: check \`docker compose ps\` and deploy a working version." >&2
    if [[ -f $backup ]]; then
      cat "$backup" > .env && rm -f "$backup"
    fi
    if [[ -f $compose_backup ]]; then
      cat "$compose_backup" > "$compose_file" && rm -f "$compose_backup"
    fi
    (( status )) || status=1
  fi

  exit "$status"
}
trap on_exit EXIT
trap 'exit 130' INT TERM HUP
# Output piped into a program that is gone (`| tee`, a closed pager).
trap 'exit 141' PIPE

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

# The compose file belongs to the release like the images do: a release may
# add a service or change how one is started. It is downloaded next to the
# current one (relative paths in it are read from its directory) and only
# takes its place once the images are here.
pull_file=$compose_file
if (( download_compose )); then
  echo "Downloading the compose file of $version…"
  new_compose=$(mktemp .compose.deploy.XXXXXX)
  chmod 600 "$new_compose"

  if ! curl --fail --silent --show-error --location --retry 3 \
        --output "$new_compose" "$release_url/v$version/compose.yaml"; then
    echo "Could not download $release_url/v$version/compose.yaml." >&2
    echo "Nothing was changed. To deploy with the compose file that is here: MOLMEDB_KEEP_COMPOSE=1 $0 $version" >&2
    exit 1
  fi

  if ! MOLMEDB_VERSION=$version "${docker[@]}" compose -f "$new_compose" config --quiet; then
    echo "The downloaded compose file is not valid; nothing was changed." >&2
    exit 1
  fi

  pull_file=$new_compose
fi

# Pulled before anything changes: an interrupted or failed download leaves
# the server exactly as it was.
MOLMEDB_VERSION=$version "${docker[@]}" compose -f "$pull_file" pull "${release_services[@]}"

compose=("${docker[@]}" compose -f "$compose_file")

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

cp -p .env "$backup"
if (( download_compose )) && [[ -f $compose_file ]]; then
  cp -p "$compose_file" "$compose_backup"
fi
changing=1

if (( download_compose )); then
  # Compared after writing: on a full disk the file comes out short, and the
  # previous one is then put back like after any other failure.
  cat "$new_compose" > "$compose_file"
  cmp --silent "$new_compose" "$compose_file"
fi

write_version "$version"

if [[ -n ${DEPLOY_MARKER:-} ]]; then
  : > "$DEPLOY_MARKER"
fi
# The time is for a database that this release replaces (its image or its
# settings changed): without it the old container is killed after the ten
# seconds it was created with.
"${compose[@]}" up -d --timeout 300

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

changing=0
rm -f "$backup" "$compose_backup"

echo
"${compose[@]}" ps
echo
echo "Deployed $version."
if [[ -n $previous && $previous != "$version" ]]; then
  echo "To go back: $0 $previous"
fi
