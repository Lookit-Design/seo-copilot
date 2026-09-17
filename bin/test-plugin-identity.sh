#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
build_root="$(mktemp -d)"
trap 'rm -rf "$build_root"' EXIT

package="$("$root/bin/build-plugin.sh" "$build_root")"
main_file="$package/bulk-keyphrase-manager.php"
plugin_basename="${main_file#"$build_root/"}"
workflow="$root/.github/workflows/plugin-check.yml"

test -d "$package"
test -f "$main_file"
test ! -e "$package/lookit-seo-copilot.php"
test "$plugin_basename" = "bulk-keyphrase-manager/bulk-keyphrase-manager.php"
grep -q '^[[:space:]]*\* Plugin Name:[[:space:]]*Lookit SEO Copilot$' "$main_file"
grep -q '^[[:space:]]*\* Text Domain:[[:space:]]*bulk-keyphrase-manager$' "$main_file"
! grep -R -q 'lookit-seo-copilot' "$package"
test "$(find "$build_root" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')" = "1"
grep -q 'run: bin/build-plugin.sh' "$workflow"
grep -q 'build/bulk-keyphrase-manager wp/wp-content/plugins/bulk-keyphrase-manager' "$workflow"
grep -q 'wp plugin activate bulk-keyphrase-manager' "$workflow"
grep -q 'wp plugin check bulk-keyphrase-manager' "$workflow"
! grep -Eq 'build/lookit-seo-copilot|plugins/lookit-seo-copilot|plugin (activate|check) lookit-seo-copilot' "$workflow"

printf '%s\n' "Plugin identity package test passed."
