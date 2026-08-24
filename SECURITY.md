# Security Policy

## Supported versions

Only the latest published release tag of this plugin receives security fixes.

## Reporting a vulnerability

Please use **GitHub Private Vulnerability Reporting** on this repository
(Security tab → Report a vulnerability). Do not open public issues for
security reports.

## Scope notes

- This plugin ships no API keys, tokens, or secrets; update checks go through
  this public repository's GitHub Releases only (plugin-update-checker,
  release assets).
- The distributed package is built exclusively by `.github/workflows/release.yml`
  from signed tags. Never install ZIPs built outside that pipeline.
- Site data (member profiles, account fields) remains owned by Ultimate Member;
  this bridge stores a single non-sensitive settings option.
