#!/bin/sh
# Downloads the engine build for one target from the staging repo's Test Build
# run for a pinned commit, unpacks it, and prints the binary's path. Needs
# GH_TOKEN with read access to that repo's Actions, and gh and php on PATH.
# Usage: fetch-engine-build.sh <repo> <commit> <target> <directory>
set -eu
repo=$1 commit=$2 target=$3 dir=$4

run=$(gh run list -R "$repo" --workflow test-build.yml --status success --limit 50 \
  --json databaseId,headSha -q "[.[] | select(.headSha == \"$commit\")][0].databaseId")
if [ -z "$run" ] || [ "$run" = "null" ]; then
  echo "No successful Test Build in $repo for $commit." >&2
  exit 1
fi

mkdir -p "$dir"
gh run download "$run" -R "$repo" -n "dynoxide-$target" -D "$dir" >&2
case "$target" in
  *windows*)
    php -r '$z = new ZipArchive(); if ($z->open($argv[1]) !== true || !$z->extractTo($argv[2])) { exit(1); }' \
      "$dir/dynoxide-$target.zip" "$dir"
    binary="$dir/dynoxide.exe"
    ;;
  *)
    tar -xzf "$dir/dynoxide-$target.tar.gz" -C "$dir"
    binary="$dir/dynoxide"
    chmod +x "$binary"
    ;;
esac
"$binary" --version >&2
echo "$binary"
