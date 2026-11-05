# Changelog

ALWAYS VERIFY THE FOLDERS THE PLUGIN OFFERS BEFORE DELETING

## Unreleased

- Folders a Compose stack uses are protected even while the stack is stopped. With Compose Manager's compose installed, each stack is read with docker compose itself, so includes, extends, anchors, profiles and variables resolve as they do when the stack runs
- Protected folders now include every bind, named-volume device, env file, build context, secret and config file, on any pool, array disk or /mnt/user0, plus the stack's own folder and the folders of its compose and env files
- Stacks are read the way Compose Manager runs them, including an indirect compose location, extra compose files and a custom env file
- Without docker compose, more bind forms are read: `- source:`, one-line lists and maps, quoted paths, trailing comments and Windows line endings
- When a stack can't be fully read (docker compose fails, a path has an unset variable, or a compose location is missing or unreadable), the scan names the stack and the reason, skips the filesystem check, and deletes are refused
- Stacks left on the flash drive by an uninstalled Compose Manager are ignored
- A container or stack that mounts a whole share no longer turns the filesystem scan off. The scan names them, and each folder they can reach gets the "in use by mount" badge
- A delete can no longer reach data outside the chosen folder: a dataset is refused when one of its children is mounted elsewhere, unmounted or a volume, and so is a folder with another filesystem mounted inside it
- A delete is refused when the child dataset list or the mount table can't be read
- A folder is matched to its dataset only by where that dataset is mounted now, by exact path
- The confirm dialog says that child datasets inside the folder are deleted too
- Every refused delete says what to do next, one block per folder: the path, the problem, any details such as the child datasets in the way, then what to do
- The ignore list changes one request at a time and is never written over when it can't be read. A failed save is reported instead of answered "ok"
- A scan or ignore request that gets no answer now says so
- Folder sizes from two scans at once are both kept
- Each log message stays on one line, so a newline in a path or file name can't add a log record
- The diagnostics report Compose Manager as installed only when the plugin is, and list any stack that can't be read
- Installing a manifest installs the package it names, so a rollback, an older test build or a forced reinstall takes effect
- Support links point at the Unraid forum thread
- fix: list the stacks folder literally and fail closed when it can't be listed or its settings don't parse, and show an unknown folder in diagnostics
- fix: read Compose stacks from the folder Compose Manager is set to use, and protect that folder

## 2026.09.06

- When a folder can't be deleted, the message now says which folders were refused and why
- Deletion is re-checked when you confirm: a folder a container has started using, or one that belongs to a Compose stack, is refused
- A folder that is a link to another folder can no longer be used to delete something the plugin protects
- Compose stacks on a custom pool are recognised; their folders were previously offered for deletion
- The "Appdata location" on the page is now the one the plugin actually uses
- Fixed a security hole where the folder-size cache could be abused to overwrite other files on your server
- The package build no longer drops files with spaces in their names
- The credits panel's support link now points at this project

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
