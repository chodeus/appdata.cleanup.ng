#!/bin/bash
set -euo pipefail

PLUGIN="appdata.cleanup.ng"

if [[ "$(uname)" == "Darwin" ]]; then
    SED_I=(sed -i'')
    MD5CMD() { md5 -q "$1"; }
    CP_TREE() { rsync -aR --files-from=<(find . -type f ! -iname "pkg_build.sh") . "$1/"; }
    # stamp root:root into the archive (can't chown to root unprivileged on macOS)
    MAKE_TAR() { COPYFILE_DISABLE=1 tar --uid 0 --gid 0 --uname root --gname root -cJf "$1" -- *; }
else
    SED_I=(sed -i)
    MD5CMD() { md5sum "$1" | awk '{print $1}'; }
    CP_TREE() { cp --parents -f $(find . -type f ! -iname "pkg_build.sh") "$1/"; }
    MAKE_TAR() { tar --owner=0 --group=0 --no-xattrs -cJf "$1" -- *; }
fi

CWD="$(pwd)"
SRC="$CWD/source/$PLUGIN"
PLG="$CWD/plugins/$PLUGIN.plg"
ARCHIVE="$CWD/archive"
tmpdir="$CWD/tmp/tmp.$((RANDOM % 1000000))"

[ -d "$SRC" ] || { echo "ERROR: source dir not found: $SRC"; exit 1; }
[ -f "$PLG" ] || { echo "ERROR: manifest not found: $PLG"; exit 1; }

version=$(date +"%Y.%m.%d")
existing=$(find "$ARCHIVE" -maxdepth 1 -name "$PLUGIN-$version*-x86_64-1.txz" -type f 2>/dev/null | wc -l | tr -d ' ')
[ "$existing" -gt 0 ] && version="$version.$existing"
filename="$ARCHIVE/$PLUGIN-$version-x86_64-1.txz"

mkdir -p "$tmpdir" "$ARCHIVE"

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
