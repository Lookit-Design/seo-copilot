#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
build_root="${1:-"$root/build"}"
package="$build_root/bulk-keyphrase-manager"

rm -rf "$package"
mkdir -p "$package"
rsync -a --exclude-from="$root/.distignore" "$root/" "$package/"

printf '%s\n' "$package"
