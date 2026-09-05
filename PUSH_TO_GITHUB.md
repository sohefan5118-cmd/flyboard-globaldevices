# Push to GitHub

This local repository is ready to push.

Recommended GitHub repository name:

```text
flyboard-globaldevices
```

## Option A: GitHub CLI

```bash
gh auth login
gh repo create sohefan5118/flyboard-globaldevices --private --source=. --remote=origin --push
```

Use `--public` instead of `--private` if you want a public repo.

## Option B: create repo on GitHub web, then push

Create an empty repo at:

```text
https://github.com/new
```

Then run:

```bash
git remote add origin git@github.com:sohefan5118/flyboard-globaldevices.git
git push -u origin main
```

If you use HTTPS/token instead of SSH:

```bash
git remote add origin https://github.com/sohefan5118/flyboard-globaldevices.git
git push -u origin main
```

## After push, node install command

```bash
curl -fsSL https://raw.githubusercontent.com/sohefan5118-cmd/flyboard-globaldevices/main/scripts/install-xboard-node-globaldevices2.sh | bash
```

Panel install command for a new panel:

```bash
curl -fsSL https://raw.githubusercontent.com/sohefan5118-cmd/flyboard-globaldevices/main/scripts/install-panel-machine-devices.sh | bash
```
