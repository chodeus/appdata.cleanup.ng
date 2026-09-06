#!/bin/bash
set -euo pipefail

PLUGIN="appdata.cleanup.ng"

if [[ "$(uname)" == "Darwin" ]]; then
    SED_I=(sed -i'')
    MD5CMD() { md5 -q "$1"; }
    CP_TREE() { rsync -aR --from0 --files-from=<(find . -type f ! -iname "pkg_build.sh" -print0) . "$1/"; }
    # stamp root:root into the archive (can't chown to root unprivileged on macOS)
    MAKE_TAR() { COPYFILE_DISABLE=1 tar --uid 0 --gid 0 --uname root --gname root -cJf "$1" -- *; }
else
    SED_I=(sed -i)
    MD5CMD() { md5sum "$1" | awk '{print $1}'; }
    CP_TREE() { find . -type f ! -iname "pkg_build.sh" -exec cp --parents -f -t "$1/" {} +; }
    MAKE_TAR() { tar --owner=0 --group=0 --no-xattrs -cJf "$1" -- *; }
fi

CWD="$(pwd)"
SRC="$CWD/source/$PLUGIN"
PLG="$CWD/plugins/$PLUGIN.plg"
OUT="$CWD/dist"
tmpdir="$CWD/tmp/tmp.$((RANDOM % 1000000))"

[ -d "$SRC" ] || { echo "ERROR: source dir not found: $SRC"; exit 1; }
[ -f "$PLG" ] || { echo "ERROR: manifest not found: $PLG"; exit 1; }

# Usage: pkg_build.sh --version YYYY.MM.DD[.N] [--branch main] [--out DIR]
# The release workflow owns version numbering; local test builds pass any version explicitly.
version=""
branch="main"
while [ $# -gt 0 ]; do
    case "$1" in
        --version) version="$2"; shift 2 ;;
        --branch)  branch="$2";  shift 2 ;;
        --out)     OUT="$2";     shift 2 ;;
        *) echo "ERROR: unknown option '$1'"; exit 1 ;;
    esac
done
[ -n "$version" ] || { echo "ERROR: --version is required (e.g. --version 0000.00.00 for a test build)"; exit 1; }
[ "$branch" = "main" ] || { echo "ERROR: this plugin only publishes from main (got '$branch')"; exit 1; }
filename="$OUT/$PLUGIN-$version-x86_64-1.txz"

mkdir -p "$tmpdir" "$OUT"

cd "$SRC"
CP_TREE "$tmpdir"

filecount=$(find "$tmpdir" -type f | wc -l | tr -d ' ')
if [ "$filecount" -lt 5 ]; then
    echo "ERROR: only $filecount files staged (expected the plugin tree). Aborting."
    rm -rf "$CWD/tmp"; exit 1
fi

# Unraid expects root-owned 0755; strip macOS xattrs so installpkg is clean
chmod -R 0755 "$tmpdir"
xattr -cr "$tmpdir" 2>/dev/null || true
find "$tmpdir" -type f -exec touch {} +

cd "$tmpdir"
MAKE_TAR "$filename"
cd "$CWD"

pkgsize=$(wc -c < "$filename" | tr -d ' ')
if [ "$pkgsize" -lt 1000 ]; then
    echo "ERROR: package is only ${pkgsize} bytes. Aborting."
    rm -f "$filename"; rm -rf "$CWD/tmp"; exit 1
fi

md5=$(MD5CMD "$filename")

"${SED_I[@]}" "s/<!ENTITY version.*>/<!ENTITY version   \"$version\">/" "$PLG"
"${SED_I[@]}" "s/<!ENTITY md5.*>/<!ENTITY md5       \"$md5\">/" "$PLG"

plg_version=$(grep 'ENTITY version' "$PLG" | grep -o '"[^"]*"' | tr -d '"')
plg_md5=$(grep 'ENTITY md5' "$PLG" | grep -o '"[^"]*"' | tr -d '"')
[ "$plg_version" = "$version" ] || { echo "ERROR: manifest version is '$plg_version', expected '$version'."; exit 1; }
[ "$plg_md5" = "$md5" ] || { echo "ERROR: manifest md5 mismatch."; exit 1; }

rm -rf "$CWD/tmp"

echo "Package : $filename"
echo "Version : $version"
echo "MD5     : $md5"
echo "Files   : $filecount   Size: ${pkgsize} bytes"
echo "Manifest updated and verified."
