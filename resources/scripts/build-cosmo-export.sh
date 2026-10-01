#!/usr/bin/env bash
#
# Builds the weekly COSMO export on the backup server. Uploaded and started by
# `php artisan predictions:export-cosmo`, which prepares the work directory:
#
#   jobs.tsv       archive<TAB>source file<TAB>name in the archive (rows grouped by archive)
#   molecules.zip  molecule.json files referenced from jobs.tsv as molecules/<n>.json
#   delete.txt     archives (relative to archives/) that are no longer exported
#   <manifest>     the new manifest
#
# Only the export root is written. Source files (prediction results) are only read.
#
# Usage: build-cosmo-export.sh <export root> <work directory> <manifest file name>
set -euo pipefail

export_root=${1:?export root is required}
work=${2:?work directory is required}
manifest=${3:?manifest file name is required}

case "$work" in
  "$export_root"/.work/*) ;;
  *) echo "Work directory must be inside ${export_root}/.work" >&2; exit 2 ;;
esac

for required in python3 unzip; do
  command -v "$required" >/dev/null || { echo "Required command '${required}' not found." >&2; exit 127; }
done

cd "$export_root"
mkdir -p archives manifests

# Leftovers of earlier interrupted runs.
find "${export_root}/.work" -mindepth 1 -maxdepth 1 ! -path "$work" -exec rm -rf -- {} +

# zip is not installed on the backup server, archives are written by python3's zipfile.
# make_zip <archive> <deflate|store> <base directory> <entry>... where entry is a path
# relative to the base directory, optionally "path=name in archive". Directories are added recursively.
make_zip() {
  python3 - "$@" <<'PY'
import os
import sys
import zipfile

archive, mode, base, *entries = sys.argv[1:]
compression = zipfile.ZIP_DEFLATED if mode == "deflate" else zipfile.ZIP_STORED

with zipfile.ZipFile(archive, "w", compression=compression, allowZip64=True) as target:
    for entry in entries:
        path, _, name = entry.partition("=")
        source = os.path.join(base, path)
        if os.path.isdir(source):
            for root, directories, files in os.walk(source):
                directories.sort()
                for file_name in sorted(files):
                    if not file_name.endswith(".tmp"):
                        file_path = os.path.join(root, file_name)
                        target.write(file_path, os.path.relpath(file_path, base))
        else:
            target.write(source, name or path)
PY
}

archive_pattern='^[0-9]+/[0-9]+_[A-Za-z0-9_.,-]+\.zip$'
stage="${work}/stage"
built=0
deleted=0
current=""

if [[ -s "${work}/molecules.zip" ]]; then
  unzip -q -o "${work}/molecules.zip" -d "${work}/molecules"
fi

flush_archive() {
  [[ -n "$current" ]] || return 0
  local target="archives/${current}"
  mkdir -p "$(dirname "$target")"
  rm -f -- "${target}.tmp"
  make_zip "${export_root}/${target}.tmp" deflate "$stage" $(cd "$stage" && ls)
  mv -f -- "${target}.tmp" "$target"
  rm -rf -- "$stage"
  built=$((built + 1))
}

if [[ -f "${work}/jobs.tsv" ]]; then
  while IFS=$'\t' read -r archive source name; do
    [[ "$archive" =~ $archive_pattern ]] || { echo "Invalid archive path: ${archive}" >&2; exit 3; }
    [[ "$name" =~ ^[A-Za-z0-9_.,-]+$ ]] || { echo "Invalid file name: ${name}" >&2; exit 3; }

    if [[ "$archive" != "$current" ]]; then
      flush_archive
      current="$archive"
      rm -rf -- "$stage"
      mkdir -p "$stage"
    fi

    cp -- "$source" "${stage}/${name}"
  done < "${work}/jobs.tsv"
  flush_archive
fi

if [[ -f "${work}/delete.txt" ]]; then
  while IFS= read -r archive; do
    [[ -n "$archive" ]] || continue
    [[ "$archive" =~ $archive_pattern ]] || { echo "Invalid archive path: ${archive}" >&2; exit 3; }
    rm -f -- "archives/${archive}"
    deleted=$((deleted + 1))
  done < "${work}/delete.txt"
  find archives -mindepth 1 -type d -empty -delete
fi

cp -- "${work}/${manifest}" "manifests/${manifest}"

# Inner archives are already compressed, the dump only stores them.
rm -f -- cosmo_export.zip.tmp
make_zip cosmo_export.zip.tmp store "$export_root" "manifests/${manifest}=${manifest}" archives
mv -f -- cosmo_export.zip.tmp cosmo_export.zip

# The work directory (it holds this script) is removed by the command after the script exits.
echo "built=${built}"
echo "deleted=${deleted}"
echo "archives=$(find archives -type f -name '*.zip' | wc -l | tr -d ' ')"
echo "size=$(stat -c %s cosmo_export.zip)"
