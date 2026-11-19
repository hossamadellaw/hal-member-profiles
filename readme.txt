=== HAL Member Profiles ===
Contributors: (site owner)
Tags: ultimate-member, elementor, membership, profile
Requires at least: 6.5
Requires PHP: 8.0
Requires Plugins: ultimate-member, elementor
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An Elementor design layer for Ultimate Member's public Profile page — built on top of
Ultimate Member's own logic, never a replacement for it. The member Account page renders
through Ultimate Member's own Account logic.

**Baseline (v1.1.5):** the runtime baseline is the ORIGINAL Ultimate Member and Amelia
plugins together with this plugin's standard bridge. The former site-specific `custom UM`
integration files are deleted and are NOT a runtime source for anything; where this
README's history mentions them, that is history only.

== Description ==

HAL Member Profiles lets you design the Ultimate Member public Profile page visually in
Elementor; the member Account page renders through Ultimate Member's own Account logic.
Ultimate Member itself keeps full ownership of identity, privacy, roles, field
validation, saving, nonces, and tabs.

**Two explicit modes, never mixed on the same request:**

* **Full Dynamic Layout (Elementor Pro):** drag ordinary Elementor elements (Heading,
  Image, Text Editor, Button, Container) and bind them to a Profile/Account field or a
  core element (Name, Avatar, Cover, Bio, Profile URL) via the "HAL Member Profiles"
  Dynamic Tags group.
* **Widgets + Native Fallback (Elementor free, no Pro):** the HAL Widgets registered with
  Elementor in this release (`Native Header`, `Profile Header Compatibility`,
  `Profile Navigation`, `Profile Body`, `Profile Actions`, `Profile Field`, and
  `Account Field`, plus `Amelia Booking` when Amelia is active) render Ultimate Member's
  own native output inside your Elementor layout. The Account widget family was
  retired in 1.3.0: its two never-registered widget classes were deleted from the
  plugin source, only `Account Field` remains registered (as the field helper), and
  the member Account page renders through Ultimate Member's own native pipeline.

**Privacy enforcement:** every field decision is made server-side by this plugin's own
Policy layer, which reads Ultimate Member's documented per-field Privacy values
(Everyone / Members / Owner+editors / specific roles / Owner+specific roles). Unknown or
unrecognized values FAIL CLOSED (the field stays hidden), administrators can always view
their own site's data, and a target member who cannot be resolved never falls back to
the visitor. Parity with YOUR installed Ultimate Member version is verified during the
compatibility QA recorded in this plugin's internal matrix before any public layout is
enabled.

**Managed templates:** the canonical Profile bridge template ships INSIDE this package
under `resources/ultimate-member/` together with a manifest (per-asset version +
SHA-256; the manifest lists exactly one asset, `profile-hal-member-profiles.php`, sha256
`4505034e70093d3d8922e7ddb7f846ead6645ad54cfe863a1aeb687dce5633d0`). From 1.3.0 the
dashboard write action is OPEN again: the release-version gate that closed every 1.2.x
release self-opened at 1.3.0, so provisioning runs from the Sync form on the Diagnostics
page. A MANUAL copy into the active CHILD theme's `ultimate-member/templates/` directory
(Ultimate Member's documented theme override location) remains the documented
alternative — see Installation. A legacy Account override left in the child theme is
only observed read-only, never managed. A locally modified copy is always reported as a
conflict and never overwritten automatically, and nothing ever installs or deletes
itself on its own.

**Deployment modes (honest matrix):**

* **This release (1.3.0):** managed-template writes are ACTIVE — the release-version
  gate that closed every 1.2.x release self-opened at 1.3.0, the Sync form on the
  Diagnostics page is back, and provisioning writes normally (a locally modified copy
  is always reported as a conflict and never overwritten automatically). On a 1.2.x
  rollback the same gate closes the writes again before any filesystem work —
  provisioning returns the machine reason `writes_disabled_v1_2` and the dashboard
  shows a passive informational row instead of the Sync form. Read-only inspection
  keeps working either way.
* **Direct filesystem:** provisioning writes normally.
* **Other hosts, FTP/SSH:** WordPress reports its standard credentials request state;
  HAL never asks for or stores passwords.
* **Immutable / CI:** defining `HAL_MEMBER_PROFILES_IMMUTABLE_DEPLOYMENT` now takes
  effect: every managed-template write becomes a reported no-op (machine reason
  `immutable`) for pipelines where your deployer owns file placement — HAL writes
  nothing, and read-only inspection keeps working.

**Compatibility humility:** features are gated at runtime by the built-in compatibility
gate against THIS site's actual component versions. The gate's registry now carries one
signed, pinned `profile` row — recorded in the internal QA matrix and mirrored into the
code in this release (WordPress 7.1.4, PHP 8.3.36, Ultimate Member 2.14.2, Elementor
4.4.0, Elementor Pro 4.4.0) — so the Profile public layout is approved exactly on that
tuple; on any other component version the verdict is `composition_mismatch` and
rendering fails closed back to Ultimate Member's native pipeline. Every other
capability still has no signed row in the registry and stays closed until its own QA
row is recorded (Diagnostics reports those as awaiting matrix sign-off). The registry
can be extended two ways: through a documented filter, or with the new WP-CLI commands
(`wp hal-member-profiles sign-composition` / `list-compositions` / `revoke-composition`,
run by an administrator with `--user=<admin>`), whose stored rows reach the gate
through the same documented filter — both accept signed rows: exact
"pinned" rows, or per-component "floor" minimum rows that survive patch-level component
updates. Every externally supplied row must carry a valid HMAC signature
(`wp_hash(..., 'auth', 'sha256')`) that is verified AFTER the filter; unsigned or
tampered rows are dropped before matching. No universal compatibility is claimed for
any host, cache layer, or future plugin version.

**Fallback contract:** if the Elementor Library Template for a Profile or Account is
deleted, left as a Draft, fails the layout contract (each required Widget rendered
exactly once inside valid context), throws during render, or the runtime compatibility
gate has not passed for this site's component versions — the page automatically completes
through Ultimate Member's FULL native pipeline inside the original wrappers. Never a
blank, partial, or duplicated page.

**Translations:** the `hal-member-profiles` text domain is loaded on WordPress' `init`
hook from the plugin's `languages/` directory, so an installed translation pack applies
to the plugin's own strings.

= What this plugin does not do =

* It does not modify Ultimate Member, Elementor, or Amelia core files.
* It does not add a new REST/AJAX endpoint, a database table, or a cache of UM/Amelia data.
* It does not rebuild Ultimate Member's edit-profile form as separate Elementor controls.
* It does not call any booking API directly; Amelia's own shortcode/form remains the
  final authority on availability and booking acceptance.
* It contains no automatic Amelia synchronization in this build's default state: the
  optional Elite sync feature ships switched OFF (`Amelia sync mode: Off`) and must be
  enabled explicitly by an administrator. See the Amelia section below.

== Installation ==

Everything below is doable from the Release ZIP alone.

1. Ensure Ultimate Member and Elementor (free) are active first — this plugin declares
   both as hard requirements via `Requires Plugins` and will not activate without them.
2. Upload and activate HAL Member Profiles like any other plugin.
3. The managed Profile bridge template ships INSIDE this package under
   `resources/ultimate-member/` (with its manifest — exactly one asset). From 1.3.0 the
   dashboard Sync action is back: open **HAL Member Profiles → Diagnostics**, review the
   manifest table, and run the Sync form there — provisioning writes the template into
   your active CHILD theme's `ultimate-member/templates/` directory (Ultimate Member's
   documented theme override location). The action is admin-only and nonce-guarded; the
   "Managed templates consent" checkbox on the Settings page governs the automated
   reconciliation runs, while this manual Sync button is the explicit admin action
   itself. The official alternative is the MANUAL copy of
   `resources/ultimate-member/profile-hal-member-profiles.php` into that same directory
   (canonical sha256
   `4505034e70093d3d8922e7ddb7f846ead6645ad54cfe863a1aeb687dce5633d0`, as recorded in the
   manifest). Without the template in place, pages stay 100% native (safe, just
   unstyled-by-HAL).
4. Go to **HAL Member Profiles → Settings** (the Settings page inside the HAL Member
   Profiles admin menu): both layout modes start at **Observe**
   (zero visual change) and the Elementor route stays locked there until the built-in
   compatibility gate passes for your site's exact plugin versions. The Diagnostics page
   carries a read-only Readiness card — managed-file status, the gate verdict with its
   machine reason, UM mapping, persona template validity, the current Ultimate Member
   Forms selection, and a template-contract pre-check. It performs zero writes.
5. Build ONE Profile Elementor Library Template containing the required widgets exactly
   once each — one Header widget (Native OR Compatibility) + Navigation + Body. The
   Account page uses no Elementor template (the Account widget family was retired in
   1.3.0 — see the widget list above) and the Account surface renders through
   Ultimate Member's own native pipeline. The Readiness card's contract pre-check
   verifies your Profile template against this contract, fail-closed.
6. On the signed composition above (the QA row is recorded in the internal matrix and
   mirrored into the gate's registry, so the profile gate is OPEN at 1.3.0 on exactly
   that tuple), select the matching Profile Library Template ID in Settings, then switch
   the Profile mode to **Public layout**: on the signed tuple the save goes through; on
   any drifted component version the save is refused, the mode stays "Observe", and the
   refusal message shows the gate's machine reason. Finally, select "HAL Member
   Profiles" as the Profile Form template under Ultimate Member → Forms. HAL never
   modifies Ultimate Member data: switching a UM form's profile template before HAL
   readiness is complete raises a dismissible admin warning only — never a block —
   naming the form's previous design as the rollback anchor and the mandatory
   "Purge All" cache step.

== Frequently Asked Questions ==

= Does this replace Ultimate Member's profile/account templates? =

No. Both original templates remain untouched and selectable at any time. This plugin
adds an additional, optional PROFILE template override that you select manually from
Ultimate Member → Forms once you are ready; the Account page keeps rendering through
Ultimate Member's own templates in this release.

= What happens if Elementor Pro is deactivated later? =

Dynamic Tags stop registering and an admin notice explains why. Widgets and the native
Ultimate Member fallback keep working — nothing breaks, and nothing shows a
half-configured layout.

= What happens if I deactivate HAL Member Profiles entirely? =

The bridge template (however it was placed — provisioned by the dashboard Sync action,
or manually copied) detects this and delegates straight to Ultimate Member's ORIGINAL
account/profile rendering, exactly as if this plugin were never installed.

= Does this integrate with Amelia? =

Amelia integration is OPTIONAL and OFF by default. Two layers exist:

* **Allowlist bridge (always available, no API):** an administrator maps a UM member to
  an Amelia employee ID plus allowed service ID(s). The server-side save filter
  (`Amelia::filter_profile_services_before_save()`, with its fail-closed F-16 stored-value
  contract intact inside the method) is NOT auto-wired in v1.1.5: it no longer registers
  itself on `um_user_pre_updating_profile_array` until a documented Field Schema ownership
  exists, so profile-save enforcement applies only if an integration explicitly wires the
  documented callback.
* **Optional Elite sync (switched Off in Settings):** when enabled by an administrator,
  HAL reads a read-only REST snapshot of services/employees/custom fields through the
  WordPress HTTP API into an internal, PII-free catalog (employees appear as bare numeric
  IDs — never names or emails). Amelia itself always remains the sole owner of
  availability and booking; HAL never books anything.

**How your Amelia API key is protected:** it is entered once by an administrator and is
either kept in `wp-config.php`/environment as a constant (never touching the database) or
stored encrypted at rest with authenticated encryption (libsodium secretbox, key derived
from this site's own WordPress salts) in a non-autoloading option — the database never
contains the plaintext. The key is masked afterwards (at most its last four characters)
and is never displayed again, logged, or exported. If site salts rotate, stored keys fail
closed and must simply be re-entered.

== Known limitations at this release ==

* **Public layout opens only on the signed composition.** The runtime gate approves the
  Profile public layout on exactly the signed, pinned tuple recorded in the internal QA
  matrix and mirrored into the gate's registry in this release (WordPress 7.1.4, PHP
  8.3.36, Ultimate Member 2.14.2, Elementor 4.4.0, Elementor Pro 4.4.0); on any other
  component version the verdict fails closed to native rendering, and every other
  capability stays closed until its own signed QA row exists. This is deliberate.
* Custom Header (fully Elementor-designed header via `Profile Header Compatibility`) is
  only as capable as the UM extensions registered and tested internally — currently
  none. Native Header is the safe default.
* Account field selectors (`Account Field` Widget/Tags) are empty until a verified
  Account-tab field source is confirmed.
* The legacy Photo/Dashboard Account tabs are not implemented inside HAL; on the native
  UM/Amelia baseline these tabs are served by Ultimate Member's own full native account
  rendering (they are not served by any legacy custom renderer — that code is deleted).
* `FieldSchema`'s field-type classification list should be checked against this site's
  actual Ultimate Member field types during the same QA pass.
* Managed-template provisioning RUNS at 1.3.0: the release-version gate opened
  automatically at 1.3.0 (it closes the writes again only on a 1.2.x rollback, where
  the machine reason `writes_disabled_v1_2` applies — a historical 1.2.x closure
  reason now). The dashboard Sync form is active on the Diagnostics page, and the
  recorded consent flag continues to gate the automated reconciliation runs. Terminal
  provisioning failures (version gate, immutable mode) still close into a final status
  exactly once instead of re-looping as pending on every admin visit (retryable causes
  — missing credentials, busy — still stay pending). The manual copy described in the
  Installation section remains a documented deployment path.
* Amelia write-sync: the HAL-owned fields writer (`AmeliaFieldsWriter`) exists and is
  wired into its governed admin route ("Apply plan now"), but production writes stay
  closed by the runtime compatibility gate (`amelia_fields_write` capability, currently
  not signed off). `discover_only` reading and the snapshot catalog are what operate
  today. Default remains Off.
* The internal runtime evidence report (Diagnostics page) describes HAL's own state with
  machine reasons: read-only, PII-free (counts and booleans only), never sent anywhere,
  and never read by the external Production Verifier. Its `ci_fixture` variant is a
  build-time schema-stability artifact only — it is not live and is never a
  compatibility sign-off.

== Changelog ==

= 1.3.0 =
* New: the profile compatibility gate is OPEN — the internal QA matrix's signed
  composition (WordPress 7.1.4, PHP 8.3.36, Ultimate Member 2.14.2, Elementor 4.4.0,
  Elementor Pro 4.4.0) is mirrored into the gate's registry as a signed pinned row, and
  the Profile public layout is approved exactly on that tuple. Any component drift
  fails closed: the verdict becomes `composition_mismatch` and rendering returns to
  Ultimate Member's native pipeline. The six capabilities without signed rows stay
  closed (awaiting matrix sign-off) until their own QA rows exist.
* New: the managed-template write path self-opened at 1.3.0 (release-version gate):
  the dashboard Sync form RETURNS in place of the passive informational row — on the
  Diagnostics page — provisioning runs from there, and `writes_disabled_v1_2` becomes
  a historical 1.2.x-only closure reason. The recorded consent flag continues to gate
  the automated reconciliation runs. A locally modified copy is still reported as a
  conflict and never overwritten automatically, the manual-copy deployment path stays
  documented, and nothing ever installs or deletes itself on its own.
* New: WP-CLI composition-registry commands (`wp hal-member-profiles sign-composition`,
  `list-compositions`, `revoke-composition`) let an operator sign, list, or revoke a
  site's approved-composition rows without a code change. Every command requires
  `--user=<admin>` with the manage_options capability; rows are stored per site in the
  `hal_member_profiles_composition_rows` option and signed with
  `wp_hash(..., 'auth', 'sha256')`.
* Removed (deliberate Account retirement): the two dead Account widget classes (never
  registered, rendered nothing), the unmanifested legacy Account template asset under
  `resources/ultimate-member/` (it was never listed in the manifest and had no
  provisioning bridge), and the retired account capability constant (`CAP_ACCOUNT`)
  are deleted from the source. The member Account page renders through Ultimate
  Member's own native pipeline, only the `Account Field` widget remains registered (as
  the field helper), and a legacy Account override left in the child theme is still
  observed read-only.
* Expected Diagnostics fingerprint change from the Account retirement: the runtime
  evidence now reports SEVEN compatibility gates instead of eight (no `gate_account`
  key), and the `um_integration` capability reports `gates_total` of 3.
* Note: with the write gate open, defining `HAL_MEMBER_PROFILES_IMMUTABLE_DEPLOYMENT`
  now takes observable effect — every managed-template write becomes a reported no-op
  (machine reason `immutable`) for pipelines whose deployer owns file placement.

= 1.2.1 =
* Fix: the managed-template write path is gated by the release version itself — closed
  on every 1.2.x (including rollbacks), self-opening at 1.3.0 — and stale per-theme
  state rows are pruned to the current manifest (option state only, never file deletion).
* Fix: terminal provisioning failures (version gate, immutable mode) now close into a
  final status exactly once instead of re-looping as pending on every admin visit;
  retryable reasons and consent gating unchanged.
* New: the compatibility registry supports signed pinned and floor rows with HMAC
  verification run after the extension filter; the registry stays empty (awaiting matrix
  signoff) in this release.
* Fix: gate rejections in the settings screen now surface the machine reason.
* Fix: the Diagnostics "Sync" form is replaced by a passive informational row while
  writes are version-gated, alongside a consolidated read-only Readiness card.
* New: a read-only template contract validator and an early-switch watcher for UM form
  template changes (admin warning only — never blocks, never writes UM data).
* Fix: the stale bundled Child Theme folder was removed and .gitattributes now enforces
  canonical LF bytes so a fresh checkout can only produce canonical bytes.
* Fix: translations now load (text domain hooked on init) and the POT template was
  regenerated with the official tool.
* Fix: uninstall purges every HAL option under the existing purge_on_uninstall guard;
  stale 1.1.5 test version pins replaced.
* Fix: every tracked programmatic file is English-only, enforced by an automated
  language-purity guard in the local build pipeline.

= 1.2.0 =
* UM-native atomic Account ownership: the member Account layout renders through
  Ultimate Member's own Account logic — no duplicate forms, navigation, hooks, or
  nonces, and no raw-template/shortcode re-entry.
* Logged-in Profile Overview surface, and UM-native photo delegation: the profile
  photo keeps rendering through Ultimate Member's own privacy-gated pipeline;
  guests never receive private fields or owner controls.
* Amelia integration behind fail-closed gates: a public service catalog reader, a
  professional service-selection save contract, explicit mode contracts
  (off/discover-only/write), and a HAL-owned Amelia field-definition writer that
  stays closed unless every gate passes. No booking management inside Member
  Profiles; no writes in off/discover-only modes.
* Existing Elementor public-profile template selection: the already-selected
  Ultimate Member profile template is used directly, with zero writes to the
  Child Theme or to Elementor templates.
* HAL Frontend Dashboard link contract with a fail-closed consumer: the Dashboard
  link renders only inside the documented contract context — never for visitors
  or unauthorized users, and never duplicated.
* Compatibility evidence register: per-capability gates and every gate that
  remains deliberately closed are documented and reported, never silently opened.

= 1.1.5 =
* Security separation: the Amelia `selected_services` save filter is no longer
  auto-registered from the integration's constructor — the fail-closed stored-value
  contract (F-16) remains inside the method, available for explicit, documented wiring.
* Admin reorganization: one `HAL Member Profiles` admin menu with six governed pages
  (Overview, Profiles, Account, Amelia, Diagnostics, Settings); the Settings page moved
  from WordPress' general Settings menu into this menu, and action redirects return to
  their owning page.
* Admin interface clarity: per-page guidance and next steps, unified translated states
  (Ready/Blocked/Not configured/Pending), grouped settings with explicit field labels
  (including the sensitive API-key field), and corrected heading order — display only,
  no operational change.
* Documentation aligned with the actual native UM/Amelia baseline and its real
  limitations (this entry and the updated sections above/below).
* Plugin header Author corrected to `Hossam Adel Law Firm`.

= 1.1.4 =
* Explicitly loads the Amelia API client and fields writer used by the HAL admin screen,
  with a regression test that renders the dashboard without the test-only autoloader.
* Corrected the verifier's fixed DNS lookup callback for Node's `all: true` mode and
  classifies `ERR_INVALID_IP_ADDRESS` as a specific lookup-contract failure.

= 1.1.3 =
* Fixed the Elementor dependency-detection race by waiting for Elementor's official
  loaded signal and rechecking readiness before displaying a missing-dependency notice.
* Isolated all invocation, watch, and target environment values from Preflight test
  fixtures while preserving the real values for validation and target verification.
* Added a blocking pre-publication Preflight so a Stable GitHub Release is not created
  until its tests and validation succeed. Version 1.1.2 was returned to Draft and was
  not approved after Production Verification failed and the installed build displayed
  a false Elementor dependency notice on the production site.

= 1.1.2 =
* Isolated `PRODUCTION_BASE_URL` from the Preflight test fixtures while preserving it
  for actual target verification. Version 1.1.1 was returned to Draft after Production
  Verification failed and was not approved for deployment to the production site.

= 1.1.1 =
* Isolated the immutable-deployment unit test so its process-wide constant cannot leak
  into the stable release gate. Version 1.1.0 was not published because that gate failed.

= 1.1.0 =
* Managed templates: canonical template assets now ship inside the package under
  `resources/ultimate-member/` with a versioned SHA-256 manifest; provisioning/syncing
  into the active child theme runs from the HAL dashboard by an administrator, with
  conflict detection (user-modified copies are never overwritten silently).
* HAL admin dashboard added: overview/health, layouts & managed templates status with a
  Sync action, compatibility diagnostics explaining every gate verdict, and honest
  placeholders for modules arriving later.
* Secret store: an optional Amelia API key can be stored encrypted at rest
  (libsodium authenticated encryption, salts-derived key, non-autoloaded option) or
  supplied via wp-config constant; keys are masked after entry and fail closed on salt
  rotation.
* Compatibility gate expanded to eight independent capabilities (managed templates,
  Amelia API read / fields write, UM schema, Elementor Dynamic Tags) with per-capability
  component floors — still no general pass and still fail-closed everywhere.
* Optional Amelia Elite read-only sync scaffolding (Off by default) feeding a PII-free
  service catalog consumed by the existing allowlist bridge; Amelia keeps full booking
  ownership.
* Release pipeline: package now ships `resources/` instead of the old manually-copied
  bridge files, verifies manifest hashes pre-build AND inside the produced ZIP, bans any
  residue of that legacy delivery path, and scans every packaged file for credential
  patterns before publishing.

= 1.0.1 =
* Privacy decisions aligned to Ultimate Member's documented field-privacy values with
  strict fail-closed handling of unrecognized values.
* Runtime compatibility gate added: Public layout cannot be saved or served unless the
  site's exact component versions passed internal QA.
* Account bridge rewritten as a thin delegation to Ultimate Member's own account
  rendering (no copied markup); Profile bridge unchanged except its official template
  header, making it selectable in Ultimate Member → Forms.
* Release package now ships the two Child Theme bridge templates; installation works
  from the ZIP alone.
* Automated unit test suite added (73 tests) and wired into the release pipeline.

= 1.0.0 =
* Initial build: core services, Profile and Account Widgets, Dynamic Tags (Elementor
  Pro), Elementor Library Template adapters with native fallback, Amelia allowlist
  bridge, and Child Theme template overrides.
* Requires PHP 8.0 (raised from 7.4 before the first public release).
* Automatic updates via GitHub Releases (plugin-update-checker ~5.7.0, bundled in
  vendor/).
