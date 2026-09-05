# Deployment Notes

## First panel

1. Install normal Flyboard/XBoard.
2. Run `scripts/install-panel-machine-devices.sh` on the panel host.
3. Verify route:

```bash
docker exec flyboard-xboard-1 sh -lc 'cd /www && php artisan route:list --path=api/v2/server/devices'
```

## Every node

1. Add node in panel.
2. Install normal xboard-node and configure `/etc/xboard-node/config.yml`.
3. Run `scripts/install-xboard-node-globaldevices2.sh` on the node host.
4. Verify version and logs.

Recommended installer:

```bash
curl -fsSL https://raw.githubusercontent.com/sohefan5118-cmd/flyboard-globaldevices/main/scripts/install-xboard-node-globaldevices2.sh | bash
```

## Redis keys

- `user_devices:*` = authorized whitelist, clipped by `device_limit`.
- `user_reported_devices:*` = reported devices, for real online count/debugging.

These keys are TTL based and may disappear when clients are offline.
