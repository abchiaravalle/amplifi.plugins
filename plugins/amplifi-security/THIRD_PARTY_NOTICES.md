# Third-party notices

amplifi.security bundles or derives logic from the following FOSS sources. Each is included on terms compatible with GPL v2.

## php-malware-finder (NBS System) — Apache 2.0

Some shell/backdoor signature *logic* in `includes/signatures/signatures.php` is ported from the YARA rules in php-malware-finder. The rules themselves are not bundled; the equivalent semantics are re-implemented in native PHP using `preg_match`, `token_get_all`, and Shannon-entropy checks so we don't require YARA on the host.

Upstream: <https://github.com/nbs-system/php-malware-finder>
License: Apache 2.0
NOTICE: Apache 2.0 attribution preserved here as required.

```
Copyright (c) NBS System
Licensed under the Apache License, Version 2.0
http://www.apache.org/licenses/LICENSE-2.0
```

## Pressidium YARA Rules — MIT

WordPress-tuned malware-pattern logic in `includes/signatures/signatures.php` is also informed by the Pressidium YARA Rules.

Upstream: <https://github.com/Pressidium/yara-rules>
License: MIT

```
MIT License — Copyright (c) Pressidium
```

## DB-IP Lite — Creative Commons Attribution 4.0

Country-level IPv4 + IPv6 geolocation database, intended location `data/dbip-country-lite.mmdb` (not committed; pulled at build time).

Upstream: <https://db-ip.com/db/lite.php>
License: <https://creativecommons.org/licenses/by/4.0/>

Required attribution (rendered in Settings → About in the plugin UI):

> IP geolocation by DB-IP

## MITRE — vulnerability records via Wordfence Intelligence

Vulnerability records pulled from Wordfence Intelligence v3 may include MITRE-sourced CVE data. The plugin displays the required MITRE attribution on each finding card per the Wordfence Terms of Service:

> CVE data © MITRE Corporation

## Wordfence Intelligence

Free for personal AND commercial use. We display attribution per their integration terms:

> Vulnerability data via Wordfence Intelligence

## WordPress core checksums API

Public WordPress.org endpoint, no auth, no bundled redistribution.
