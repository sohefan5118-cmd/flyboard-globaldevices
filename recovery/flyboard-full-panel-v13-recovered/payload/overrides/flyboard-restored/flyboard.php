<?php

// Default-off rollout guard. Enabling requires explicit operator confirmation:
// historical saved enabled=true must not silently start blocking live users.
return [
    'subscription_antishare_rollout' => true,
    'trusted_ip_gate' => true,
    'trusted_ip_strict' => true,
    'trusted_ip_window' => 86400,
    'ip_migration_grace' => 300,
    // Enabled only after isolated queue, transport, import and concurrency tests.
    'upstream_async_enabled' => true,
];
