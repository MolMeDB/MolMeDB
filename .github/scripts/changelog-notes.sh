#!/usr/bin/env bash
# Prints the section of a release from CHANGELOG.md, without its heading.
#
#   changelog-notes.sh 1.4.0 [CHANGELOG.md]
#
# The Release workflow refuses a tag whose version has no section, and
# puts the section at the top of the GitHub release.
set -euo pipefail

version="${1:?usage: changelog-notes.sh VERSION [CHANGELOG]}"
changelog="${2:-CHANGELOG.md}"

if [[ ! -f "$changelog" ]]; then
  echo "$changelog does not exist." >&2
  exit 1
fi

notes="$(awk -v heading="## [${version}]" '
  index($0, heading) == 1 { found = 1; next }
  found && /^## \[/ { exit }
  found && /^\[[^]]+\]: / { exit }
  found { print }
' "$changelog" | sed -e '/./,$!d')"

if [[ -z "${notes//[[:space:]]/}" ]]; then
  echo "$changelog has no section for ${version} (a heading \"## [${version}] - YYYY-MM-DD\")." >&2
  exit 1
fi

printf '%s\n' "$notes"
