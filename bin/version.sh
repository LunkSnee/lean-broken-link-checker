#!/usr/bin/env bash
# Plugin version management. VERSION is the source of truth; the plugin header,
# the LEAN_BLC_VERSION constant and readme.txt's Stable tag must match it.
#
#   bin/version.sh                 print the version
#   bin/version.sh check [TAG]     fail unless every copy matches VERSION, CHANGELOG.md
#                                  has an entry for it and (if given) TAG is v<VERSION>
#   bin/version.sh set X.Y.Z       write X.Y.Z everywhere and add a CHANGELOG.md stub
set -euo pipefail
cd "$(dirname "$0")/.."

MAIN=lean-broken-link-checker.php
version=$(tr -d '[:space:]' < VERSION)

header_version() { sed -n 's/^ \* Version: *//p' "$MAIN"; }
constant_version() { sed -n "s/^define( 'LEAN_BLC_VERSION', '\(.*\)' );$/\1/p" "$MAIN"; }
stable_tag() { sed -n 's/^Stable tag: *//p' readme.txt; }

check() {
	local tag=${1:-} ok=1 name value
	[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "VERSION '$version' is not X.Y.Z" >&2; exit 1; }
	for name in header_version constant_version stable_tag; do
		value=$($name)
		if [[ $value != "$version" ]]; then
			echo "$name is '$value', VERSION is '$version'" >&2
			ok=0
		fi
	done
	if ! grep -q "^## \[$version\] - " CHANGELOG.md; then
		echo "CHANGELOG.md has no '## [$version] - <date>' entry" >&2
		ok=0
	fi
	if [[ -n $tag && $tag != "v$version" ]]; then
		echo "tag '$tag' does not match VERSION (expected v$version)" >&2
		ok=0
	fi
	((ok)) || exit 1
	echo "version $version is consistent"
}

set_version() {
	local new=$1
	[[ $new =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "usage: bin/version.sh set X.Y.Z" >&2; exit 1; }
	printf '%s\n' "$new" > VERSION
	sed -i "s/^ \* Version: .*/ * Version: $new/" "$MAIN"
	sed -i "s/^define( 'LEAN_BLC_VERSION', '.*' );$/define( 'LEAN_BLC_VERSION', '$new' );/" "$MAIN"
	sed -i "s/^Stable tag: .*/Stable tag: $new/" readme.txt
	if ! grep -q "^## \[$new\] - " CHANGELOG.md; then
		# Insert the new entry above the newest one.
		awk -v entry="## [$new] - $(date +%Y-%m-%d)" '
			!done && /^## \[/ { print entry; print "### Changed"; print "- "; print ""; done = 1 }
			{ print }
		' CHANGELOG.md > CHANGELOG.md.tmp && mv CHANGELOG.md.tmp CHANGELOG.md
		echo "added a CHANGELOG.md stub for $new: fill it in"
	fi
	version=$new
	check
}

case ${1:-} in
	'') echo "$version" ;;
	check) check "${2:-}" ;;
	set) set_version "${2:-}" ;;
	*) echo "usage: bin/version.sh [check [TAG] | set X.Y.Z]" >&2; exit 1 ;;
esac
