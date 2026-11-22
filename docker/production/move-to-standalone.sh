#!/usr/bin/env bash
#
# Moves a MolMeDB installation that runs from a checkout of the repository
# into a directory of its own, which holds only compose.yaml, deploy.sh,
# .env, frontend/.env and storage/, and runs images pulled from the registry.
# Run once, on the server, as root, inside tmux or screen: the site is down
# while it works, and it must not depend on the connection to the server.
#
# Usage: move-to-standalone.sh <version> <old directory> <new directory>
#   e.g. move-to-standalone.sh 1.4.0 /var/www/MolMeDB-api /srv/app/MolMeDB
#
# What it does, in this order:
#   1. does everything that can be done while the site still runs: checks
#      the release, the names of the database volumes, the database images
#      and free space, and pulls all images;
#   2. stops the application (the site is down from here on);
#   3. backs up both databases twice (a pg_dump and, with the databases
#      stopped for a moment, an exact archive of their volumes), Redis,
#      storage/ and the configuration;
#   4. proves the backups: checksums, archive listings, and a restore of both
#      dumps into throw-away databases whose row counts must match;
#   5. copies storage/ and the configuration into the new directory;
#   6. deploys the release from the new directory and looks at the result.
#
# The databases are not moved: they stay in the Docker volumes they are in,
# and the new compose file is checked to use exactly those volumes. Their
# containers are replaced, though: the image built on this server gives way
# to the published one (ghcr.io/molmedb/molmedb-db), which step 1 compares
# with the old one.
#
# Until the new containers are started in step 6, any failure starts the
# old installation again and changes nothing. The old directory is never
# modified, and how to go back afterwards is written to ROLLBACK.txt next to
# the backups.
#
# Environment:
#   BACKUP_DIR         where the backups go (default: <new directory>-backup-<time>)
#   BACKUP_ONLY        1 = a rehearsal: steps 1-4, then the old installation
#                      is started again and nothing is moved
#   SKIP_RESTORE_TEST  1 = do not restore the dumps in step 4 (it can take
#                      long on a large database)
#   RESTORE_TEST_ONLY  a directory of backups from an earlier run: only the
#                      restore of step 4 is done on them, with the site running
#   DOCKER             docker command (default: docker)
#   RELEASE_URL        where the release files are downloaded from
#                      (default: https://github.com/MolMeDB/MolMeDB/releases/download)
#   MOVE_ALLOW_NON_ROOT  1 = run without being root (owners of the files in
#                      storage/ are then not kept)
#   MOVE_ALLOW_LIBC_CHANGE  1 = go on although the published database image
#                      has another C library than the one in use (text
#                      indexes must then be rebuilt with REINDEX)
set -euo pipefail

# The backups hold passwords and the data itself.
umask 077

version=${1:-}
version=${version#v}
old_dir=${2:-}
new_dir=${3:-}

if [[ ! $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ || -z $old_dir || -z $new_dir ]]; then
  echo "Usage: $0 <version> <old directory> <new directory>" >&2
  echo "  e.g. $0 1.4.0 /var/www/MolMeDB-api /srv/app/MolMeDB" >&2
  exit 2
fi

read -r -a docker <<< "${DOCKER:-docker}"
release_url=${RELEASE_URL:-https://github.com/MolMeDB/MolMeDB/releases/download}
default_project=molmedb-api
images=(backend nginx frontend rdkit)
main_db=molmedb-dev-db
predictions_db=molmedb-predictions-db
redis=molmedb-redis
# Everything that writes to the databases or to storage/, the entry point first.
app_containers=(molmedb-nginx molmedb-frontend molmedb-scheduler-worker molmedb-queue-worker molmedb-predictions-queue-worker molmedb-backend)
restore_container=molmedb-restore-test

step() { printf '\n== %s\n' "$*"; }
fail() { echo "$*" >&2; exit 1; }

if [[ $EUID -ne 0 && ${MOVE_ALLOW_NON_ROOT:-0} != 1 ]]; then
  fail "Run this as root: the files in storage/ belong to the user of the containers and must keep their owner."
fi

for tool in curl tar cp du df find cmp awk; do
  command -v "$tool" > /dev/null || fail "Missing command: $tool"
done

if command -v sha256sum > /dev/null; then
  sha256=(sha256sum)
else
  sha256=(shasum -a 256)
fi

# ---------------------------------------------------------------- 1. checks

step "Checking the old installation"

[[ -d $old_dir ]] || fail "Not a directory: $old_dir"
old_dir=$(cd "$old_dir" && pwd -P)

for required in .env frontend/.env storage; do
  [[ -e $old_dir/$required ]] || fail "Not found: $old_dir/$required — is $old_dir the directory the site runs from?"
done
# A link would be archived and copied as a link, not as the files behind it.
[[ -d $old_dir/storage && ! -L $old_dir/storage ]] || fail "$old_dir/storage must be a real directory, not a link."

if [[ -e $new_dir && ! -d $new_dir ]]; then
  fail "Not a directory: $new_dir"
fi
if [[ -d $new_dir && -n $(ls -A "$new_dir") ]]; then
  fail "$new_dir is not empty. The move needs a new or empty directory."
fi
mkdir -p "$new_dir"
new_dir=$(cd "$new_dir" && pwd -P)

case $new_dir/ in "$old_dir"/*) fail "The new directory must not be inside the old one." ;; esac
case $old_dir/ in "$new_dir"/*) fail "The old directory must not be inside the new one." ;; esac

backup_dir=${BACKUP_DIR:-$new_dir-backup-$(date +%Y%m%d-%H%M%S)}
mkdir -p "$backup_dir"
backup_dir=$(cd "$backup_dir" && pwd -P)
case $backup_dir/ in "$new_dir"/*) fail "The backups must not be inside the new directory." ;; esac
case $backup_dir/ in "$old_dir"/*) fail "The backups must not be inside the old directory." ;; esac
[[ -z $(ls -A "$backup_dir") ]] || fail "$backup_dir is not empty."
chmod 700 "$backup_dir"

"${docker[@]}" info > /dev/null 2>&1 || fail "\`${docker[*]} info\` fails: is docker running, and may this user use it?"

# Read through a pipe, not a command substitution: this also runs after the
# script's own output broke, when text that could not be written would end
# up in the substituted value.
running() {
  "${docker[@]}" inspect -f '{{.State.Running}}' "$1" 2> /dev/null | grep -qx true
}

for container in "$main_db" "$predictions_db"; do
  running "$container" || fail "The database container $container is not running. Start the old installation first."
done

# Compose named the volumes <project>_<volume> when it created them. The new
# compose file must resolve to the same names, or it would start empty
# databases next to the real ones.
project=$("${docker[@]}" inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$main_db")
[[ -n $project && $project != "<no value>" ]] || fail "Cannot read the Compose project of $main_db."

data_volume() {
  "${docker[@]}" inspect -f '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Name}}{{end}}{{end}}' "$1"
}

# The directory given must be the one the containers were started from, or
# the storage and settings of another copy would be moved to these databases.
working_dir=$("${docker[@]}" inspect -f '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$main_db")
if [[ ! -d $working_dir || $(cd "$working_dir" && pwd -P) != "$old_dir" ]]; then
  fail "The running installation was started from '$working_dir', not from $old_dir."
fi
# The compose file(s) it was started with, for the way back.
old_compose_files=$("${docker[@]}" inspect -f '{{index .Config.Labels "com.docker.compose.project.config_files"}}' "$main_db")

main_volume=$(data_volume "$main_db")
predictions_volume=$(data_volume "$predictions_db")
redis_volume=${project}_molmedb-redis

if [[ $main_volume != "${project}_molmedb-db" || $predictions_volume != "${project}_molmedb-predictions-db" ]]; then
  fail "Unexpected database volumes: '$main_volume' and '$predictions_volume' (project '$project'). The new compose file expects ${project}_molmedb-db and ${project}_molmedb-predictions-db; nothing was changed."
fi

echo "Compose project: $project"
echo "Database volumes: $main_volume, $predictions_volume"

step "Checking release $version"

for image in "${images[@]}"; do
  if ! error=$("${docker[@]}" manifest inspect "ghcr.io/molmedb/molmedb-$image:$version" 2>&1 > /dev/null); then
    echo "Cannot find ghcr.io/molmedb/molmedb-$image:$version in the registry:" >&2
    echo "  ${error:-no answer}" >&2
    fail "Is the release published, and is this docker command logged in to ghcr.io?"
  fi
done

staging=$(mktemp -d)
chmod 700 "$staging"
# What this script put into the new directory; taken away again when the
# move is given up, so that it can be run once more.
created=()
# deploy.sh creates this file right before it replaces the containers. Until
# then the old ones are only stopped and can simply be started again; once
# it exists they are gone, and the way back is ROLLBACK.txt.
marker=$staging/containers-replaced

# 1 while the application is stopped and still belongs to the old
# installation: whatever ends the script then, the old installation is
# started again.
site_down=0
redis_stopped=0
stopped=()

# Brings the old installation back as it was. The databases first: the
# application waits for them.
resume() {
  local index path attempt container down order=()
  echo >&2
  echo "The move did not finish. Starting the old installation again…" >&2

  order=("$main_db" "$predictions_db")
  if (( redis_stopped )); then
    order+=("$redis")
  fi
  # In reverse: the application before the workers and the entry point.
  for (( index = ${#stopped[@]} - 1; index >= 0; index-- )); do
    order+=("${stopped[index]}")
  done

  # A container that was still being stopped when the move was interrupted
  # cannot be started at once, so this is tried until all of them run.
  for attempt in {1..30}; do
    down=()
    for container in "${order[@]}"; do
      if ! running "$container"; then
        "${docker[@]}" start "$container" > /dev/null 2>&1
        running "$container" || down+=("$container")
      fi
    done
    (( ${#down[@]} )) || break
    sleep "${MOVE_WAIT_INTERVAL:-2}"
  done

  for path in ${created[@]+"${created[@]}"}; do
    rm -rf "$path"
  done
  # It describes the way back from a move that did not happen, and its
  # commands would put back databases older than what runs now.
  if [[ -f $backup_dir/ROLLBACK.txt ]]; then
    rm -f "$backup_dir/ROLLBACK.txt"
    grep -v ' \./ROLLBACK\.txt$' "$backup_dir/SHA256SUMS" > "$backup_dir/SHA256SUMS.new" 2> /dev/null \
      && mv "$backup_dir/SHA256SUMS.new" "$backup_dir/SHA256SUMS"
  fi

  if (( ${#down[@]} )); then
    echo "STILL DOWN: ${down[*]}" >&2
    echo "Start them by hand: ${docker[*]} start ${down[*]}" >&2
  fi
  echo "Nothing was moved; $old_dir is untouched. Backups made so far: $backup_dir" >&2
}

# Runs whatever ends the script. Nothing in it may stop it: a message that
# cannot be written (the terminal is gone) must not keep the site down, and
# a second Ctrl-C must not interrupt the restart.
on_exit() {
  local status=$?
  set +e
  trap '' INT TERM HUP PIPE
  trap - EXIT

  # Looked at before the directory it lives in is removed: once the
  # containers were replaced, starting the old ones or emptying the new
  # directory would destroy what runs.
  if (( site_down )) && [[ -e $marker ]]; then
    site_down=0
    echo >&2
    echo "The move was cut short after the containers were replaced by the new ones." >&2
    echo "To finish it:  cd $new_dir && ./deploy.sh $version" >&2
    echo "To go back:    follow $backup_dir/ROLLBACK.txt" >&2
    (( status )) || status=1
  fi

  rm -rf "$staging"
  "${docker[@]}" rm -fv "$restore_container" > /dev/null 2>&1
  if (( site_down )); then
    resume
    (( status )) || status=1
  fi
  exit "$status"
}
trap on_exit EXIT
trap 'exit 130' INT TERM HUP
# Output piped into a program that is gone (`| tee`, a closed pager).
trap 'exit 141' PIPE

for file in compose.yaml deploy.sh; do
  curl --fail --silent --show-error --location --retry 3 \
       --output "$staging/$file" "$release_url/v$version/$file" \
    || fail "Could not download $release_url/v$version/$file."
done

# The line the new .env needs when the volumes carry another prefix than the
# one written in the compose file.
project_line=
if [[ $project != "$default_project" ]]; then
  project_line="COMPOSE_PROJECT_NAME=$project"
fi

# Resolved with the real settings, before anything is stopped.
mkdir "$staging/frontend"
cp "$old_dir/.env" "$staging/.env"
cp "$old_dir/frontend/.env" "$staging/frontend/.env"
if [[ -n $project_line ]]; then
  grep -vE '^COMPOSE_PROJECT_NAME=' "$old_dir/.env" > "$staging/.env" || true
  printf '\n%s\n' "$project_line" >> "$staging/.env"
fi

resolved=$(MOLMEDB_VERSION=$version "${docker[@]}" compose -f "$staging/compose.yaml" config) \
  || fail "The compose file of $version does not resolve with the settings of $old_dir/.env."

for volume in "$main_volume" "$predictions_volume"; do
  grep -qE "^[[:space:]]+name: $volume\$" <<< "$resolved" \
    || fail "The compose file of $version would not use the volume $volume. Nothing was changed."
done
echo "The new compose file uses the existing database volumes."

step "Pulling the images (the site still runs)"

# The images of the release and of the databases, so that the downloads do
# not happen with the site down (deploy.sh still asks the registry whether
# the release is complete). Redis and CDK Depict keep the image they run.
MOLMEDB_VERSION=$version "${docker[@]}" compose -f "$staging/compose.yaml" pull \
  web storage-init app app-scheduler-worker app-queue-worker app-predictions-queue-worker frontend rdkit db db-predictions

step "Comparing the database images"

# By id: the name of an image built here moves to the next build.
main_image=$("${docker[@]}" inspect -f '{{.Image}}' "$main_db")
predictions_image=$("${docker[@]}" inspect -f '{{.Image}}' "$predictions_db")
new_db_image=$(sed -n 's/^[[:space:]]*image: \(ghcr\.io\/molmedb\/molmedb-db:.*\)$/\1/p' <<< "$resolved" | head -n 1)
[[ -n $new_db_image ]] || fail "Cannot find the database image in the compose file of $version."

# The new image takes over the data directory of the old one as it is.
# The first line of what a program of an image answers.
image_answer() {
  local image=$1 output
  shift
  output=$("${docker[@]}" run --rm --network none --entrypoint "$1" "$image" "${@:2}" 2>&1) \
    || fail "Cannot run \`$*\` in the image $image: $output"
  awk 'NR == 1' <<< "$output"
}

# "postgres (PostgreSQL) 15.8 (Debian …)" -> 15
postgres_major() { sed -n 's/^postgres (PostgreSQL) \([0-9][0-9]*\).*/\1/p' <<< "$1"; }
# "ldd (Debian GLIBC 2.36-9+deb12u7) 2.36" -> 2.36: the version of the
# library itself; the revision of the package changes with every fix.
libc_version() { awk '{print $NF}' <<< "$1"; }

new_postgres=$(image_answer "$new_db_image" postgres --version)
new_libc=$(image_answer "$new_db_image" ldd --version)
echo "published: $new_postgres; $new_libc ($new_db_image)"
[[ -n $(postgres_major "$new_postgres") && -n $(libc_version "$new_libc") ]] \
  || fail "Cannot read the PostgreSQL and C library versions of $new_db_image."

old_images=("$main_image")
if [[ $predictions_image != "$main_image" ]]; then
  old_images+=("$predictions_image")
fi
for old_image in "${old_images[@]}"; do
  old_postgres=$(image_answer "$old_image" postgres --version)
  old_libc=$(image_answer "$old_image" ldd --version)
  echo "in use:    $old_postgres; $old_libc"

  if [[ $(postgres_major "$old_postgres") != "$(postgres_major "$new_postgres")" ]]; then
    fail "The published database image is another PostgreSQL major version; it cannot open this data directory."
  fi
  if [[ $(libc_version "$old_libc") != "$(libc_version "$new_libc")" && ${MOVE_ALLOW_LIBC_CHANGE:-0} != 1 ]]; then
    fail "The published database image has another C library ($(libc_version "$new_libc"), in use: $(libc_version "$old_libc")). Text is sorted by it, so indexes on text can become wrong without notice. Rebuild $new_db_image on the same base, or run again with MOVE_ALLOW_LIBC_CHANGE=1 and REINDEX both databases after the move."
  fi
done

# The archive of a volume is made by a container of the old image that
# writes into the backup directory. Tried now, on a file that every data
# directory has, so that it cannot fail for the first time with the site down.
"${docker[@]}" run --rm --network none --entrypoint tar \
  -v "$main_volume:/data:ro" -v "$backup_dir:/backup" \
  "$main_image" -C /data -cf /backup/probe.tar PG_VERSION \
  || fail "A container cannot write into $backup_dir (SELinux? user namespaces?). Nothing was changed."
[[ -s $backup_dir/probe.tar ]] || fail "A container wrote nothing into $backup_dir. Nothing was changed."
rm -f "$backup_dir/probe.tar"

step "Checking free space"

# In kilobytes.
psql_value() {
  "${docker[@]}" exec "$1" sh -c 'psql -X -At -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "$0"' "$2"
}

storage_kb=$(du -sk "$old_dir/storage" | awk '{print $1}')
main_kb=$(( $(psql_value "$main_db" 'SELECT pg_database_size(current_database())') / 1024 ))
predictions_kb=$(( $(psql_value "$predictions_db" 'SELECT pg_database_size(current_database())') / 1024 ))

free_kb() { df -Pk "$1" | awk 'NR == 2 {print $4}'; }
filesystem() { df -Pk "$1" | awk 'NR == 2 {print $1}'; }

# A dump and an archive of the volume for each database, and storage/ once;
# a tenth on top, for what a volume holds besides the database itself.
backup_need=$(( (storage_kb + 2 * (main_kb + predictions_kb)) * 11 / 10 ))
new_need=$(( storage_kb * 11 / 10 ))
if [[ ${BACKUP_ONLY:-0} == 1 ]]; then
  new_need=0
fi
if [[ -n ${RESTORE_TEST_ONLY:-} ]]; then
  backup_need=0
  new_need=0
fi

echo "storage/: $(( storage_kb / 1024 )) MB, databases: $(( (main_kb + predictions_kb) / 1024 )) MB"

if [[ $(filesystem "$backup_dir") == "$(filesystem "$new_dir")" ]]; then
  (( $(free_kb "$backup_dir") > backup_need + new_need )) \
    || fail "Not enough space in $backup_dir: $(( (backup_need + new_need) / 1024 )) MB needed, $(( $(free_kb "$backup_dir") / 1024 )) MB free. Set BACKUP_DIR to another disk."
else
  (( $(free_kb "$backup_dir") > backup_need )) \
    || fail "Not enough space in $backup_dir: $(( backup_need / 1024 )) MB needed, $(( $(free_kb "$backup_dir") / 1024 )) MB free."
  (( $(free_kb "$new_dir") > new_need )) \
    || fail "Not enough space in $new_dir: $(( new_need / 1024 )) MB needed, $(( $(free_kb "$new_dir") / 1024 )) MB free."
fi

# The restore test fills a database of the same size inside Docker, on the
# disk the real databases live on.
if [[ ${SKIP_RESTORE_TEST:-0} != 1 ]]; then
  docker_root=$("${docker[@]}" info -f '{{.DockerRootDir}}')
  restore_need=$(( (main_kb > predictions_kb ? main_kb : predictions_kb) * 2 ))
  if [[ -d $docker_root ]] && (( $(free_kb "$docker_root") <= restore_need )); then
    fail "Not enough space in $docker_root for the restore test: $(( restore_need / 1024 )) MB needed, $(( $(free_kb "$docker_root") / 1024 )) MB free. Free some, or run with SKIP_RESTORE_TEST=1."
  fi
fi

# One line per table: its name and the number of rows. Bingo keeps its own
# tables in the schema "bingo" and names them after internal ids, which a
# restore creates anew, so they cannot be compared by name.
count_sql=$(cat << 'SQL'
SELECT format('SELECT %L, count(*) FROM %I.%I', schemaname || '.' || tablename, schemaname, tablename)
FROM pg_tables
WHERE schemaname NOT IN ('pg_catalog', 'information_schema', 'bingo')
ORDER BY schemaname, tablename \gexec
SQL
)

# table_counts <container> <shell command that runs psql>
table_counts() {
  "${docker[@]}" exec -i "$1" sh -c "$2" <<< "$count_sql"
}

# Restores a dump into a database of a throw-away container that runs the
# image of the real one, and compares the number of rows in every table.
restore_test() {
  local name=$1 image=$2 attempt post_data bingo_statements bingo_indexes index pattern
  local dump=$backup_dir/$name.dump list=$backup_dir/$name.restore-test.list log=$backup_dir/$name.restore-test.log

  echo "$name: restoring the dump into a temporary database (this can take a while)"
  "${docker[@]}" rm -fv "$restore_container" > /dev/null 2>&1 || true
  # Without a network: a copy of the real data, reachable by nobody.
  "${docker[@]}" run -d --name "$restore_container" --network none --shm-size 1g \
    -e POSTGRES_PASSWORD="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')" "$image" > /dev/null

  # Over TCP: the server that only prepares the data directory listens on
  # the socket alone, the final one on both.
  for attempt in {1..60}; do
    if "${docker[@]}" exec "$restore_container" pg_isready -q -h 127.0.0.1 -U postgres; then
      break
    fi
    sleep "${MOVE_WAIT_INTERVAL:-2}"
  done
  "${docker[@]}" exec "$restore_container" pg_isready -q -h 127.0.0.1 -U postgres

  # A Bingo index cannot be created by pg_restore: Bingo builds it with
  # queries that need "public" on the search path, and pg_restore empties
  # the path. So the dump is restored without those indexes and the tables
  # Bingo keeps for them (it makes them anew), and the indexes are created
  # afterwards in an ordinary session, with the statements from the dump.
  post_data=$("${docker[@]}" exec -i "$restore_container" pg_restore -f - --section=post-data < "$dump")
  bingo_statements=$(grep -E '^CREATE INDEX [^ ]+ ON .* USING bingo_idx ' <<< "$post_data" || true)
  bingo_indexes=$(sed -n 's/^CREATE INDEX \([^ ]*\) ON .* USING bingo_idx .*/\1/p' <<< "$post_data")

  "${docker[@]}" exec -i "$restore_container" pg_restore --list < "$dump" > "$list.all"
  pattern=
  for index in $bingo_indexes; do
    pattern+="${pattern:+|} $index( |\$)|${index}_shadow"
  done
  if [[ -n $pattern ]]; then
    grep -vE "$pattern" "$list.all" > "$list"
  else
    cp "$list.all" "$list"
  fi
  rm -f "$list.all"
  "${docker[@]}" exec -i "$restore_container" sh -c 'cat > /tmp/restore.list' < "$list"

  # template0: nothing in it yet, everything comes from the dump.
  "${docker[@]}" exec "$restore_container" createdb -U postgres -T template0 restore_test
  if ! "${docker[@]}" exec -i "$restore_container" \
        pg_restore -U postgres -d restore_test --no-owner --no-privileges --use-list=/tmp/restore.list \
        < "$dump" 2> "$log"; then
    echo "pg_restore reported errors for $name; see $log:" >&2
    tail -n 20 "$log" >&2
    false
  fi

  if [[ -n $bingo_statements ]]; then
    echo "$name: creating $(wc -l <<< "$bingo_statements" | tr -d ' ') Bingo index(es)"
    if ! "${docker[@]}" exec -i "$restore_container" \
          psql -X -q -v ON_ERROR_STOP=1 -U postgres -d restore_test <<< "$bingo_statements" 2>> "$log"; then
      echo "A Bingo index could not be created in the restored $name; see $log:" >&2
      tail -n 20 "$log" >&2
      false
    fi
  fi

  table_counts "$restore_container" 'psql -X -At -v ON_ERROR_STOP=1 -U postgres -d restore_test' \
    > "$backup_dir/$name.restored.counts"

  if ! cmp --silent "$backup_dir/$name.counts" "$backup_dir/$name.restored.counts"; then
    echo "$name: the restored database differs from the real one:" >&2
    diff "$backup_dir/$name.counts" "$backup_dir/$name.restored.counts" | head -n 20 >&2 || true
    false
  fi

  echo "$name: $(wc -l < "$backup_dir/$name.counts" | tr -d ' ') tables restored, all row counts match"
  "${docker[@]}" rm -fv "$restore_container" > /dev/null
}

# Only the restore test, on backups that a run of this script already made.
# The site keeps running: nothing is stopped and nothing is written but the
# results of the test.
if [[ -n ${RESTORE_TEST_ONLY:-} ]]; then
  [[ -s $RESTORE_TEST_ONLY/main-db.dump && -s $RESTORE_TEST_ONLY/main-db.counts ]] \
    || fail "No backups of this script in $RESTORE_TEST_ONLY."
  rmdir "$backup_dir" "$new_dir" 2> /dev/null || true
  backup_dir=$(cd "$RESTORE_TEST_ONLY" && pwd -P)

  step "Restoring the dumps in $backup_dir (the site keeps running)"
  restore_test main-db "$main_image"
  restore_test predictions-db "$predictions_image"
  echo "Both dumps restore into databases with the row counts of the real ones."
  exit 0
fi

# ------------------------------------------------------------ 2. stopping

step "Stopping the application (the site is down from now on)"

site_down=1

for container in "${app_containers[@]}"; do
  if running "$container"; then
    # Noted first: an interrupted stop is then undone as well.
    stopped+=("$container")
    "${docker[@]}" stop "$container" > /dev/null
    echo "stopped $container"
  fi
done
if (( ${#stopped[@]} == 0 )); then
  echo "None of the application containers was running."
fi

# ------------------------------------------------------------- 3. backups

source_psql='psql -X -At -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'

dump_database() {
  local container=$1 name=$2

  echo "$name: counting rows"
  table_counts "$container" "$source_psql" > "$backup_dir/$name.counts"
  [[ -s $backup_dir/$name.counts ]] || { echo "$name has no tables?" >&2; false; }

  echo "$name: pg_dump"
  "${docker[@]}" exec "$container" sh -c 'pg_dump --format=custom -U "$POSTGRES_USER" -d "$POSTGRES_DB"' > "$backup_dir/$name.dump"
  "${docker[@]}" exec "$container" sh -c 'pg_dumpall --globals-only -U "$POSTGRES_USER"' > "$backup_dir/$name.globals.sql"
  [[ -s $backup_dir/$name.dump ]]
}

# An exact copy of a volume, taken while nothing uses it. Putting it back
# does not depend on pg_restore, the extension or the PostgreSQL version of
# a later image. tar comes from the image the database itself runs.
archive_volume() {
  local volume=$1 image=$2 name=$3

  echo "$name: archiving the volume $volume"
  "${docker[@]}" run --rm --entrypoint tar \
    -v "$volume:/data:ro" -v "$backup_dir:/backup" \
    "$image" -C /data -cf "/backup/$name.volume.tar" .
  [[ -s $backup_dir/$name.volume.tar ]]
  # Written by the container, which does not know the umask of this script.
  chmod 600 "$backup_dir/$name.volume.tar"
}

step "Backing up the databases"

dump_database "$main_db" main-db
dump_database "$predictions_db" predictions-db

if running "$redis"; then
  redis_stopped=1
  "${docker[@]}" stop "$redis" > /dev/null
fi
# Time to finish the last checkpoint: the archive is to be of a database
# that was shut down, not of one that was killed.
"${docker[@]}" stop -t 300 "$main_db" "$predictions_db" > /dev/null

archive_volume "$main_volume" "$main_image" main-db
archive_volume "$predictions_volume" "$predictions_image" predictions-db
if "${docker[@]}" volume inspect "$redis_volume" > /dev/null 2>&1; then
  archive_volume "$redis_volume" "$main_image" redis
else
  echo "No volume $redis_volume: the queue of Redis is not backed up."
fi

"${docker[@]}" start "$main_db" "$predictions_db" > /dev/null
if (( redis_stopped )); then
  "${docker[@]}" start "$redis" > /dev/null
  redis_stopped=0
fi

step "Backing up storage/ and the configuration"

tar -C "$old_dir" -cf "$backup_dir/storage.tar" storage

mkdir "$backup_dir/config"
cp -p "$old_dir/.env" "$backup_dir/config/env"
cp -p "$old_dir/frontend/.env" "$backup_dir/config/frontend.env"
for file in docker-compose.yaml docker-compose.yml docker-compose-production.yaml; do
  if [[ -f $old_dir/$file ]]; then
    cp -p "$old_dir/$file" "$backup_dir/config/$file"
  fi
done
chmod -R go-rwx "$backup_dir/config"

# ---------------------------------------------------------- 4. the proof

step "Checking the backups"

archived=$(tar -tf "$backup_dir/storage.tar" | wc -l | tr -d ' ')
present=$(cd "$old_dir" && find storage | wc -l | tr -d ' ')
if [[ $archived != "$present" ]]; then
  echo "storage.tar holds $archived entries, storage/ has $present." >&2
  false
fi
echo "storage.tar: $archived entries, as in storage/"

for name in main-db predictions-db redis; do
  if [[ -f $backup_dir/$name.volume.tar ]]; then
    tar -tf "$backup_dir/$name.volume.tar" > /dev/null
    echo "$name.volume.tar: readable"
  fi
done

# The server the dump came from must be able to read its table of contents.
"${docker[@]}" exec -i "$main_db" pg_restore --list < "$backup_dir/main-db.dump" > /dev/null
"${docker[@]}" exec -i "$predictions_db" pg_restore --list < "$backup_dir/predictions-db.dump" > /dev/null
echo "both dumps: readable"

if [[ ${SKIP_RESTORE_TEST:-0} == 1 ]]; then
  echo "SKIP_RESTORE_TEST=1: the dumps were not restored."
else
  restore_test main-db "$main_image"
  restore_test predictions-db "$predictions_image"
fi

# The checksums come last and cover everything written above, so a copy of
# the backups taken elsewhere can be checked as a whole.
write_checksums() {
  (
    cd "$backup_dir"
    find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 "${sha256[@]}" > SHA256SUMS
    "${sha256[@]}" -c SHA256SUMS > /dev/null
  )
  echo "checksums written and read back: $backup_dir/SHA256SUMS"
}

if [[ ${BACKUP_ONLY:-0} == 1 ]]; then
  write_checksums
  step "Rehearsal finished"
  for (( index = ${#stopped[@]} - 1; index >= 0; index-- )); do
    "${docker[@]}" start "${stopped[index]}" > /dev/null
  done
  site_down=0
  rmdir "$new_dir" 2> /dev/null || true
  if [[ ${SKIP_RESTORE_TEST:-0} == 1 ]]; then
    echo "The backups are complete, but the dumps were not restored: $backup_dir"
  else
    echo "The backups are complete and checked: $backup_dir"
  fi
  echo "The old installation runs again; nothing was moved."
  exit 0
fi

# The old installation is started the way it was started: with its project
# name and its compose file(s).
old_compose_options="-p $(printf '%q' "$project")"
IFS=, read -r -a old_compose_list <<< "$old_compose_files"
for file in ${old_compose_list[@]+"${old_compose_list[@]}"}; do
  old_compose_options+=" -f $(printf '%q' "$file")"
done

q_old=$(printf '%q' "$old_dir")
q_new=$(printf '%q' "$new_dir")
q_backup=$(printf '%q' "$backup_dir")

# Each command reads the archive before it empties the volume: a backup
# directory that was moved or mistyped must not cost the data that is there.
restore_volume_command() {
  local volume=$1 image=$2 archive=$3
  cat << COMMAND
     [ -z "\$(${docker[*]} ps -aq --filter volume=$volume)" ] && \\
     ${docker[*]} run --rm --network none --entrypoint sh -v $volume:/data -v $q_backup:/backup:ro $image \\
       -c 'tar -tf /backup/$archive > /dev/null && find /data -mindepth 1 -delete && tar -C /data -xf /backup/$archive'
COMMAND
}

cat > "$backup_dir/ROLLBACK.txt" << TEXT
Backups of the MolMeDB installation in $old_dir, taken $(date) before the
move to $new_dir (release $version).

  main-db.dump, predictions-db.dump   pg_dump --format=custom of both databases
                                      (pg_restore cannot create a Bingo index:
                                      leave it out and create it afterwards,
                                      as *.restore-test.list and the restore
                                      test of this script do)
  *.globals.sql                       the roles of both database servers
  *.volume.tar                        exact copies of the Docker volumes
                                      ($main_volume,
                                       $predictions_volume, $redis_volume)
  storage.tar                         $old_dir/storage
  config/                             .env, frontend/.env, the compose files
  *.counts                            rows per table when the backup was made
  SHA256SUMS                          checksums of all of the above

Going back to the old installation
----------------------------------
The new release migrated the databases; the old code needs them as they
were. $old_dir was not modified and its images are still on this server
(do not run \`docker image prune -a\` before you are sure). The image ids
below are those the old database containers ran.

0. Check that the backups are complete:
     cd $q_backup && ${sha256[*]} -c SHA256SUMS

1. Remove the containers of the new installation (the volumes are kept).
   By name: it works whatever state the new directory is in.
     ${docker[*]} rm -f ${app_containers[*]} molmedb-storage-init rdkit cdkdepict $main_db $predictions_db $redis

2. Put the volumes back exactly as they were. Each command does nothing
   while a container still uses the volume, and reads the archive before it
   empties the volume:
$(restore_volume_command "$main_volume" "$main_image" main-db.volume.tar)
$(restore_volume_command "$predictions_volume" "$predictions_image" predictions-db.volume.tar)
$(if [[ -f $backup_dir/redis.volume.tar ]]; then restore_volume_command "$redis_volume" "$main_image" redis.volume.tar; fi)

3. Start the old installation:
     cd $q_old && ${docker[*]} compose $old_compose_options up -d --no-build

Files uploaded after the move live in $new_dir/storage; the old
installation uses $old_dir/storage.
TEXT

write_checksums

# ------------------------------------------------------ 5. the new directory

step "Preparing $new_dir"

created=("$new_dir/storage" "$new_dir/frontend" "$new_dir/.env" "$new_dir/compose.yaml" "$new_dir/deploy.sh" "$new_dir/.env.deploy-backup")

cp -a "$old_dir/storage" "$new_dir/storage"
copied=$(cd "$new_dir" && find storage | wc -l | tr -d ' ')
if [[ $copied != "$present" ]]; then
  echo "$new_dir/storage has $copied entries, $old_dir/storage has $present." >&2
  false
fi
echo "storage/: $copied entries copied"

mkdir "$new_dir/frontend"
cp -p "$old_dir/.env" "$new_dir/.env"
cp -p "$old_dir/frontend/.env" "$new_dir/frontend/.env"
if [[ -n $project_line ]]; then
  # In place, so the file keeps its owner and permissions.
  cat "$staging/.env" > "$new_dir/.env"
  echo "$project_line written to the new .env"
fi

cp "$staging/compose.yaml" "$new_dir/compose.yaml"
cp "$staging/deploy.sh" "$new_dir/deploy.sh"
chmod 644 "$new_dir/compose.yaml"
chmod 755 "$new_dir/deploy.sh"

# ----------------------------------------------------------- 6. deployment

step "Deploying $version from $new_dir"

# The compose file is already there, checked against the real volumes.
deployed=1
DOCKER="${docker[*]}" MOLMEDB_KEEP_COMPOSE=1 DEPLOY_MARKER=$marker "$new_dir/deploy.sh" "$version" || deployed=0

if [[ ! -e $marker ]]; then
  echo >&2
  echo "The deployment stopped before any container was replaced." >&2
  exit 1
fi

# From here on the containers belong to the new installation.
site_down=0

if (( ! deployed )); then
  echo >&2
  echo "The deployment from $new_dir failed after the containers were replaced. The databases may already be migrated." >&2
  echo "To go back to the old installation follow $backup_dir/ROLLBACK.txt." >&2
  exit 1
fi

step "Looking at the result"

setting() {
  local value
  value=$(sed -n "s/^$1=//p" "$new_dir/.env" | tail -n 1 | tr -d '\r"'"'")
  echo "${value:-$2}"
}

warnings=0
for container in "${app_containers[@]}" "$main_db" "$predictions_db"; do
  if ! running "$container"; then
    echo "NOT RUNNING: $container" >&2
    warnings=$(( warnings + 1 ))
  fi
done

# nginx answers on 127.0.0.1:8443 and picks the site by the Host header.
check_url() {
  local host=$1 path=$2 attempt
  for attempt in {1..10}; do
    if curl --fail --silent --output /dev/null --max-time 20 \
         -H "Host: $host" -H "X-Forwarded-Proto: https" "http://127.0.0.1:8443$path"; then
      echo "ok: https://$host$path"
      return 0
    fi
    sleep "${MOVE_WAIT_INTERVAL:-2}"
  done
  echo "NO ANSWER: https://$host$path" >&2
  warnings=$(( warnings + 1 ))
}

check_url "$(setting MOLMEDB_ADMIN_HOST admin.molmedb.upol.cz)" /up
check_url "$(setting MOLMEDB_SITE_HOST molmedb.upol.cz)" /

echo
if (( warnings )); then
  echo "MolMeDB $version was deployed from $new_dir, but $warnings check(s) above failed. Look at them before you rely on it."
else
  echo "MolMeDB $version runs from $new_dir."
fi
echo "Backups and the way back: $backup_dir (ROLLBACK.txt)"
echo "Keep $old_dir and the backups until you are sure, and do not run docker compose in $old_dir unless you go back."
echo "Next releases: $new_dir/deploy.sh <version>"

(( warnings == 0 ))
