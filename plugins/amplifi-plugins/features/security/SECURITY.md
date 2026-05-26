# Security policy

amplifi.security is a security plugin. We hold our own code to the standards we recommend: capability checks on every endpoint, nonces on every form, prepared statements on every query, escape on every output, secrets encrypted at rest with WP's `AUTH_KEY` family, no eval/create_function, no bundled Composer libs.

## Reporting a vulnerability

Please email **security@amplifi.studio** with:

- A clear description of the issue and the impact (privilege escalation? data leak? RCE?).
- Reproduction steps. A minimal proof-of-concept is welcome.
- The plugin version (`Settings → amplifi.security → Health`).
- Your WordPress and PHP versions.

If you'd like to encrypt your report, our PGP key is at <https://amplifi.studio/security.txt>.

## Response SLA

- **Acknowledge** within 48 hours.
- **Initial assessment** within 5 business days.
- **Fix or mitigation** within 14 days for critical issues, 30 days for high, best-effort for lower-severity issues.

## Scope

In scope:
- The plugin's PHP code, JS, CSS, bundled signatures, and any data file shipped in the release zip.
- The WP-CLI command surface.
- Privilege boundaries between the WordPress admin and Anthropic / SMTP2Go / Textbelt / AbuseIPDB / Wordfence Intelligence.

Out of scope:
- Issues in WordPress core itself (report to WP core).
- Issues in third-party plugins or themes installed alongside (report to those vendors).
- Issues in the bundled FOSS rule sources (report upstream and we'll pull patches).
- Server-level concerns (file permissions, SSH access, etc.).

## Disclosure

We follow coordinated disclosure: please give us a reasonable window to ship a patch before public disclosure. We'll credit you in the changelog (or anonymously on request).

## Bug bounty

We don't currently run a paid bounty program. If finances allow we'll register on Patchstack mVDP (free for plugin authors).

## Our own security practices

- Git commits are signed.
- Releases are tagged with signed tags.
- Every release zip ships `INTEGRITY.md` listing SHA-256 of every shipped file.
- The plugin self-validates against that manifest on startup.
- Reproducible builds: see `tools/build.sh`.
- Pre-release pentest checklist (SQLi sweep, capability audit, nonce audit, escape audit, secret-leak grep) before WP.org submission.
