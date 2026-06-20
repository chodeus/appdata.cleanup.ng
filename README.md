# Appdata Cleanup NG

Finds appdata folders left behind by removed Docker containers and lets you review
and delete them. A modernized revival of Andrew Zawadzki's (Squid) original
**CA Cleanup Appdata**, brought up to date for current Unraid.

![Appdata Cleanup NG](screenshot/mainpage-full.png)

> **Always review the folders offered before deleting — deletion is permanent.**

## Install

Unraid → **Plugins → Install Plugin**, and paste:

```
https://raw.githubusercontent.com/chodeus/appdata.cleanup.ng/master/plugins/appdata.cleanup.ng.plg
```

Then open **Settings → Cleanup Appdata**.

## Differences from Squid's original

- **Works on modern Unraid (6.4+/7.x).** The original no longer runs on current releases.
- **Safer deletes.** Confined to the appdata share, never crosses a mount
  boundary, and a backstop independent of what the browser submits.
- **ZFS-aware.** Dataset folders are removed with `zfs destroy` (opt-in), not a
  partial `rm`.
- **Custom pools.** Appdata on a non-standard pool (e.g. a dedicated cache pool)
  is matched automatically — no configuration.
- **Docker Compose aware.** Appdata used by Compose Manager stacks (including
  stopped/`down` stacks) is protected.
- **More to work with.** Folder sizes, an ignore list, an optional filesystem
  scan for template-less folders, a stale-template cleaner, and a one-click
  diagnostics export.

---

Original *CA Cleanup Appdata* © 2015–2024 Andrew Zawadzki (Squid). Revived 2026 by chodeus.
