# Runtime verification — 2026-10-27

The recovered package was installed as a disposable, isolated Compose project using:

- project: `flyboard-v13-verify`
- port: `17001`
- separate SQLite database and Redis volume
- pinned image digest from the package

Verified before destroying the disposable project:

- fresh install and installer integrity gates passed
- original public page and original admin SPA returned HTTP 200
- generated administrator login returned HTTP 200/success with a session cookie
- Flyboard route set registered (25 routes)
- six Flyboard tables existed
- anti-share enabled, device limit 1, release window 120
- Horizon running, Redis PONG, scheduler running with `cleanup:online-status`
- PHP lint passed for all v13/owner-lock critical files
- IP-window regression passed for 120, 180 and 300 second windows
- package and payload SHA-256 verification passed

After testing, the isolated containers, network, volume, credentials and test directory were removed. The existing production `xboard-xboard-1` container on port 7001 was not restarted or modified.
