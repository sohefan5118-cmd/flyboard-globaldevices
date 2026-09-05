# Flyboard private repo reinstall guide

This private repository contains:

- `src/panel/` - current Flyboard/XBoard panel source snapshot with Flyboard changes.
- `src/node/` - current xboard-node source snapshot with global device-limit changes.
- `dist/`, `offline/`, `scripts/`, `panel/`, `node/patches/` - release binaries, offline package, install scripts, and patch records.

## New server clone

Because this repository is private, clone it with a GitHub token or SSH key that has access:

```bash
git clone https://github.com/sohefan5118-cmd/flyboard-globaldevices.git
cd flyboard-globaldevices
```

If GitHub asks for credentials:

- Username: your GitHub username
- Password: a GitHub fine-grained token with repository `Contents: Read-only` or `Read and write`

## Install/use options

### Option A: use the included release scripts

Panel patch:

```bash
bash scripts/install-panel-machine-devices.sh
```

Node binary patch:

```bash
bash scripts/install-xboard-node-globaldevices2.sh
```

### Option B: rebuild/deploy from source

Panel source is in:

```text
src/panel/
```

Node source is in:

```text
src/node/
```

Use these if you want to rebuild images/binaries instead of applying the release patch package.

## Security note

Do not commit real `.env`, database passwords, API keys, SSH private keys, or production secrets. This repo intentionally keeps `.env.example` only.
