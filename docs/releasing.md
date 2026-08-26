# Releasing

How a new version of amplifi.plugins is built, tagged, and published.

> **A release is a fleet-wide deploy.** Every site running amplifi.plugins polls
> `releases/latest` and offers the new version in **Dashboard &rarr; Updates**
> within six hours. There is no staged rollout and no per-site version pinning.
> Do not publish a release you are not prepared to have on every site.

## The release script

```bash
./scripts/release.sh <version>      # e.g. ./scripts/release.sh 3.3.8
```

Version must be semver: `3.3.8`, or a prerelease like `3.4.0-beta.1`. Anything
with a hyphen is automatically flagged `--prerelease` on GitHub.

### What it does, in order

1. **Bumps the bootstrap.** Rewrites the `Version:` header and the
   `AMPLIFI_PLUGINS_VERSION` define in `plugins/amplifi-plugins/amplifi-plugins.php`.
2. **Bumps every feature.** Walks `features/*/` and rewrites the
   `define( '<PREFIX>_VERSION', '...' )` line in each feature's entry file, so all
   eleven version constants move together.
3. **Commits** the bump as `Bump version to <version>`.
4. **Rebuilds `dist/`** from scratch.
5. **Stages and packages.** Copies `plugins/amplifi-plugins/` to a temp dir, strips
   dev files (`docker-compose.yml`, `.git`, `.env`, `node_modules/`, `vendor/`,
   `tests/`, `composer.*`, `phpunit.xml.dist`), copies in `LICENSE`, and zips it to
   `dist/amplifi-plugins-v<version>.zip`.
6. **Copies the manifest** to `dist/plugins-manifest.json`.
7. **Generates a changelog** from `git log <last-tag>..HEAD`, excluding merges.
8. **Tags and pushes** `v<version>`.
9. **Creates the GitHub release** with the zip and the manifest attached.

Both assets matter. The zip is what the updater installs; `plugins-manifest.json`
is what the hub reads to build its feature catalog.

## Before you run it

- [ ] Working tree is clean and on `main`, with `main` pushed.
- [ ] `gh auth status` shows an authenticated account with `repo` scope.
- [ ] Every changed feature's version constant is expected to move (the script
      bumps all of them, by design).
- [ ] If a feature gained or lost a DB table, its installer and its stored
      DB-version option were updated so existing sites upgrade on load. The
      activation hook does **not** re-run on a plugin update.
- [ ] If features changed, `plugins-manifest.json` is current.
- [ ] Docs under `docs/features/` reflect the change.

### Verify the built zip before publishing

The script publishes at the end of the same run, so inspect the previous build or
do a dry check on a scratch copy:

```bash
unzip -l dist/amplifi-plugins-v3.3.7.zip | head
unzip -p dist/amplifi-plugins-v3.3.7.zip amplifi-plugins/amplifi-plugins.php | grep -m2 -i version
```

To diff a built zip against what a site is actually running:

```bash
mkdir -p /tmp/zipcheck && cd /tmp/zipcheck
unzip -q ~/gits/amplifi.plugins/dist/amplifi-plugins-v3.3.7.zip
cd amplifi-plugins
find . -type f ! -name '.DS_Store' -print0 | xargs -0 md5 -r | sort -k2 > /tmp/zip.md5
# then the same md5 pass on the server and diff the two lists
```

## Cautions in the current script

These are real characteristics of `scripts/release.sh` as it stands. Know them
before you run it.

**It runs `git add -A` before committing the bump.** Anything untracked or
modified in your working tree is swept into the version-bump commit. Confirm
`git status` is clean first.

**It rebuilds `dist/` with `rm -rf`.** Any local artifact in `dist/` is destroyed.

**It tags and publishes in one pass.** There is no confirmation prompt between
building the zip and creating the public GitHub release. The point of no return is
the moment the script starts, not the moment it finishes.

**It pushes the tag but not the branch.** `release.sh` runs `git push origin
"$TAG"` and never pushes `main`, so the version-bump commit stays local. Push it
yourself afterwards or the tag will point at a commit nobody else has:

```bash
git push origin main
```

**The changelog is raw commit subjects** since the last tag. Write commit subjects
that read as release notes, or edit the release body afterwards:

```bash
gh release edit v3.3.8 --notes-file better-notes.md
```

## Version scheme

All eleven features share the suite version. A feature-only fix still bumps every
constant. That is intentional: it makes "what is this site running?" a single
question with a single answer.

- **Patch** (`3.3.7` &rarr; `3.3.8`): bug fix in one or more features.
- **Minor** (`3.3.x` &rarr; `3.4.0`): new feature, or a meaningful capability added
  to an existing one.
- **Major**: breaking change to the data model, the framework contract, or the
  minimum WordPress/PHP requirement.

## How sites receive the update

| Step | Detail |
|---|---|
| Poll | `api.github.com/repos/abchiaravalle/amplifi.plugins/releases/latest` |
| Cache | `amplifi_latest_release` transient, 6 hours (1 hour after a failure) |
| Match | release asset named `amplifi-plugins-v<version>.zip` |
| Surface | **Dashboard &rarr; Updates**, standard WordPress update UI |
| Force | hub page &rarr; **Check Now** (clears transients, requires `update_plugins`) |

## After publishing

1. Confirm the release has both assets:
   ```bash
   gh release view v3.3.8 --json assets -q '.assets[].name'
   ```
2. On a canary site, hub &rarr; **Check Now**, then update and confirm the version
   in **Plugins**.
3. Verify enabled features still render on the front end. For a feature that
   outputs markup, prefer a server-side probe over a plain URL fetch, since a CDN
   or WAF in front of the site can return a cached page or block the request and
   make a working deploy look broken:
   ```bash
   wp eval 'ob_start(); do_action("wp_footer"); $o=ob_get_clean(); echo substr_count($o,"acconsent");'
   ```
4. Only then roll to the rest of the fleet.

## Rolling back

Re-releasing an older version is not enough on its own, because the updater keys
off `releases/latest`. To roll back:

```bash
gh release delete v3.3.8 --yes          # or mark it prerelease
git push --delete origin v3.3.8
```

Marking the bad release as a prerelease is the gentler option: `releases/latest`
skips prereleases, so sites fall back to the last stable release without the tag
disappearing from history.

For an individual site, install the known-good zip from its release page manually.

## Hotfix flow

```bash
git checkout -b fix/short-description
# ... make the fix, commit with a subject that reads as a release note
git push -u origin fix/short-description
gh pr create --fill
# merge, then:
git checkout main && git pull
./scripts/release.sh 3.3.8
```
