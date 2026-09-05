#!/bin/bash
# Run on the Unraid server to copy the live plugin files back into a clone.
# Usage: copy_to_git.sh [destination-repo-root]
set -euo pipefail

src="/usr/local/emhttp/plugins/appdata.cleanup.ng"
dest="${1:-$(mktemp -d "${TMPDIR:-/tmp}/appdata.cleanup.ng.XXXXXX")}"
tree="$dest/source/appdata.cleanup.ng/usr/local/emhttp/plugins/appdata.cleanup.ng"

mkdir -p "$tree"
cp -Rpv "$src/." "$tree/"
# AppleDouble files only, and only under the copy we just made
find "$tree" -type f -name "._*" -exec rm -v {} +
echo "copied to: $dest"
