# Changelog

ALWAYS VERIFY THE FOLDERS THE PLUGIN OFFERS BEFORE DELETING

## 2026.07.09

- "In use by mount" badge now shows which containers reach the folder and via which mounts
- Readable hover tooltip (one block per container) replaces the browser-default tooltip
- Tooltip can be opened with Tab and closed with Escape; clicking the badge no longer ticks the folder's checkbox
- In-use messages clarified: stopped containers count too

## 2026.07.02

- ZFS: destroy re-checks the resolved target is inside appdata
- Delete refuses when Docker is stopped or unreachable
- Folder scan offers nothing when Docker is stopped, not just unreachable

## 2026.07.01 - Safety and security hardening

- Minimum Unraid raised to 6.10 (required for CSRF enforcement)
- Deletes resolve symlinks and re-check the target is inside appdata
- ZFS: recursive destroy only when the dataset has children or snapshots
- Path and mount checks now fail safe when they can't be verified
- Folders are sent individually, so odd names can't hit the wrong one
- Docker unreachable now offers nothing, avoiding false orphans
- Unresolved Compose variables or whole-share mounts disable the filesystem scan
- Folders under a broad bind-mount are flagged "in use by mount"
- Stale-template delete re-checks the live container list
- Hardened dialog escaping and input handling

## 2026.06.20 - First release (BETA)

- Beta: verified on current Unraid; pools, ZFS and Compose still need real-world testing
- Forked from Squid's CA Cleanup Appdata and modernised for current Unraid
- Works on Unraid 6.10+/7.x (sends the csrf_token modern Unraid needs)
- Deletions confined to the appdata share, never crossing a mount boundary
- Path matching handles cache/user, trailing slashes and custom pools
- Skips "borrowed" appdata (owned by one app, mounted by another)
- Shows each folder's size
- Protects appdata used by Compose stacks, including down stacks
- ZFS datasets removed with a gated zfs destroy and snapshot-loss warning
- Ignore list for folders that aren't container appdata
- Optional filesystem scan for template-less folders
- Stale-template cleaner
- Capture Diagnostics button and system-log output
- Renamed to appdata.cleanup.ng; original by Andrew Zawadzki (Squid)
