# Security Policy

## Supported versions

Only the latest published release tag of this plugin receives security fixes.

## Reporting a vulnerability

Please use **GitHub Private Vulnerability Reporting** on this repository
(Security tab → Report a vulnerability). Do not open public issues for
security reports.

## Scope notes

- Update checks go through this public repository's GitHub Releases only
  (plugin-update-checker, release assets). No third-party update sources.
- The distributed package is built exclusively by `.github/workflows/release.yml`
  from a tag pushed to this repository. Release tags are currently ANNOTATED but NOT
  cryptographically signed (`git verify-tag vX.Y.Z` reports "no signature found");
  enforcing signed tags is a pending owner decision. Until then, verify integrity by
  confirming the release was produced by this repository's own workflow run for the
  tag and by comparing the asset's SHA-256 digest. Never install ZIPs built outside
  that pipeline.
- Site data (member profiles, account fields) remains owned by Ultimate Member;
  the bridge stores a single non-sensitive settings option.
- **Optional Amelia API key storage (development phase):** an administrator may supply
  an Amelia Elite key either as a wp-config/environment constant (never stored in the
  database) or through the plugin, in which case it is encrypted at rest with
  authenticated encryption (libsodium secretbox; key derived from this site's own
  WordPress salts) and kept in a NON-autoloaded option that contains ciphertext only
  (`hmpv1:` blob). The plaintext is never re-displayed (masked to at most its last four
  characters), never logged, and never exported. Rotating site salts invalidates stored
  keys fail-closed and requires re-entry. Tampering with the stored blob makes it
  undecryptable rather than recoverable.
- **Managed templates:** provisioned copies in the active Child Theme are never deleted
  silently; user-modified copies are reported as conflicts. Uninstall purges only this
  plugin's own settings option, and only when an administrator explicitly enabled
  `Purge on uninstall` (default: off).
