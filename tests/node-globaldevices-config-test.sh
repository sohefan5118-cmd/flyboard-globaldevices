#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$ROOT_DIR/scripts/install-xboard-node-globaldevices2.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

mkdir -p "$TMP_DIR/bin"
cat > "$TMP_DIR/bin/xboard-node" <<'EOF'
#!/usr/bin/env bash
echo 'xboard-node test'
EOF
cat > "$TMP_DIR/bin/systemctl" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
cat > "$TMP_DIR/bin/curl" <<'EOF'
#!/usr/bin/env bash
for arg in "$@"; do
  case "$arg" in
    *'/api/v2/server/devices?machine_id=42&node_id=42&token=machine-secret-do-not-print'|\
    *'/api/v2/server/devices?machine_id=7&node_id=7&token=node-secret-do-not-print'|\
    *'/api/v2/server/devices?machine_id=11&node_id=11&token=legacy-secret-do-not-print')
      exit 0
      ;;
    *'/dist/xboard-node-global-device-linux-amd64.gz')
      echo 'Reached binary download after config parsing' >&2
      exit 22
      ;;
  esac
done
exit 23
EOF
chmod +x "$TMP_DIR/bin/"*

run_case() {
  local name="$1"
  local secret="$2"
  local config="$TMP_DIR/$name.yml"
  local credentials="$TMP_DIR/$name.env"
  local output rc

  cat > "$config"
  printf '%s\n' "${3:-}" > "$credentials"
  chmod 600 "$credentials"

  set +e
  output="$(PATH="$TMP_DIR/bin:$PATH" CONFIG="$config" CREDENTIALS="$credentials" BIN="$TMP_DIR/bin/xboard-node" \
    bash "$SCRIPT" 2>&1)"
  rc=$?
  set -e
  [[ "$rc" -ne 0 ]]
  [[ "$output" == *'Reached binary download after config parsing'* ]]
  [[ "$output" != *'unable to parse panel URL'* ]]
  [[ "$output" != *"$secret"* ]]
}

run_case machine machine-secret-do-not-print 'INSTANCE_MACHINE_TOKEN=machine-secret-do-not-print' <<'EOF'
instances:
  - panel:
      url: "http://panel.example.test"
    machine:
      machine_id: 42
      token_env: INSTANCE_MACHINE_TOKEN
EOF

run_case node node-secret-do-not-print 'INSTANCE_NODE_API_KEY=node-secret-do-not-print' <<'EOF'
instances:
  - panel:
      url: "http://panel.example.test"
      token_env: INSTANCE_NODE_API_KEY
      node_id: 7
EOF

run_case legacy legacy-secret-do-not-print <<'EOF'
panel:
  url: "http://panel.example.test"
  token: "legacy-secret-do-not-print"
  node_id: 11
EOF

echo 'node globaldevices config parsing test passed'
