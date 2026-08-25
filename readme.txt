=== HAL Member Profiles ===
Contributors: (site owner)
Tags: ultimate-member, elementor, membership, profile
Requires at least: 6.5
Requires PHP: 8.0
Requires Plugins: ultimate-member, elementor
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An Elementor design layer for Ultimate Member's public Profile and member Account pages —
built on top of Ultimate Member's own logic, never a replacement for it.

== Description ==

HAL Member Profiles lets you design the Ultimate Member public Profile page and the
member Account page visually in Elementor, while Ultimate Member itself keeps full
ownership of identity, privacy, roles, field validation, saving, nonces, and tabs.

**Two explicit modes, never mixed on the same request:**

* **Full Dynamic Layout (Elementor Pro):** drag ordinary Elementor elements (Heading,
  Image, Text Editor, Button, Container) and bind them to a Profile/Account field or a
  core element (Name, Avatar, Cover, Bio, Profile URL) via the "HAL Member Profiles"
  Dynamic Tags group.
* **Widgets + Native Fallback (Elementor free, no Pro):** compatible HAL Widgets
  (`Native Header`, `Profile Navigation`, `Profile Body`, `Profile Actions`,
  `Account Navigation`, `Account Body`, and related) render Ultimate Member's own native
  output inside your Elementor layout.

**Privacy enforcement:** every field decision is made server-side by this plugin's own
Policy layer, which reads Ultimate Member's documented per-field Privacy values
(Everyone / Members / Owner+editors / specific roles / Owner+specific roles). Unknown or
unrecognized values FAIL CLOSED (the field stays hidden), administrators can always view
their own site's data, and a target member who cannot be resolved never falls back to
the visitor. Parity with YOUR installed Ultimate Member version is verified during the
compatibility QA recorded in this plugin's internal matrix before any public layout is
enabled.

**Fallback contract:** if the Elementor Library Template for a Profile or Account is
deleted, left as a Draft, fails the layout contract (each required Widget rendered
exactly once inside valid context), throws during render, or the runtime compatibility
gate has not passed for this site's component versions — the page automatically completes
through Ultimate Member's FULL native pipeline inside the original wrappers. Never a
blank, partial, or duplicated page.

= What this plugin does not do =

* It does not modify Ultimate Member, Elementor, or Amelia core files.
* It does not add a new REST/AJAX endpoint, a database table, or a cache of UM/Amelia data.
* It does not rebuild Ultimate Member's edit-profile form as separate Elementor controls.
* It does not call any booking API directly; Amelia's own shortcode/form remains the
  final authority on availability and booking acceptance.
* It contains NO Amelia synchronization/API features in this release — only the manual,
  administrator-maintained allowlist described below.

== Installation ==

Everything below is doable from the Release ZIP alone.

1. Ensure Ultimate Member and Elementor (free) are active first — this plugin declares
   both as hard requirements via `Requires Plugins` and will not activate without them.
2. Upload and activate HAL Member Profiles like any other plugin.
3. From the ZIP/package, copy the TWO bridge templates
   (`profile-hal-member-profiles.php` and `account.php`) from
   `Child Theme/ultimate-member/templates/` into your CHILD THEME's own
   `ultimate-member/templates/` directory. Without them the bridge has no attachment
   points and pages stay 100% native (safe, just unstyled-by-HAL).
4. Go to **Settings → HAL Member Profiles**: both layout modes start at **Observe**
   (zero visual change) and the Elementor route stays locked there until the built-in
   compatibility gate passes for your site's exact plugin versions.
5. Build one Profile and one Account Elementor Library Template containing the required
   widgets exactly once each — Profile: one Header widget (Native OR Compatibility) +
   Navigation + Body; Account: Navigation + Body.
6. After staging QA passes and your composition is recorded, select the matching
   Library Template IDs in Settings, then switch the relevant mode to **Public layout**
   (it will refuse to save while the compatibility gate has not passed), and finally
   select "HAL Member Profiles" as the Profile Form template under Ultimate Member →
   Forms.

== Frequently Asked Questions ==

= Does this replace Ultimate Member's profile/account templates? =

No. Both original templates remain untouched and selectable at any time. This plugin
adds an additional, optional template override that you select manually from
Ultimate Member → Forms once you are ready.

= What happens if Elementor Pro is deactivated later? =

Dynamic Tags stop registering and an admin notice explains why. Widgets and the native
Ultimate Member fallback keep working — nothing breaks, and nothing shows a
half-configured layout.

= What happens if I deactivate HAL Member Profiles entirely? =

Both Child Theme template overrides detect this and delegate straight to Ultimate
Member's ORIGINAL account/profile rendering, exactly as if this plugin were never
installed.

= Does this integrate with Amelia? =

Only through an administrator-maintained allowlist mapping a UM member to an Amelia
employee ID and their allowed service ID(s), enforced server-side at profile-save time
(anything outside the member's own allowlist is stored as empty). No live sync, no
booking API calls, and no deeper Amelia features are part of this release.

== Known limitations at this release ==

* **Public layout is locked until compatibility QA passes.** The runtime gate keeps
  both modes on Observe for every composition that has not been live-tested and signed
  off internally — including a brand-new install. This is deliberate.
* Custom Header (fully Elementor-designed header via `Profile Header Compatibility`) is
  only as capable as the UM extensions registered and tested internally — currently
  none. Native Header is the safe default.
* Account field selectors (`Account Field` Widget/Tags) are empty until a verified
  Account-tab field source is confirmed.
* The legacy Photo/Dashboard Account tabs are not yet migrated; they remain served by
  the legacy custom account renderer until tested.
* `FieldSchema`'s field-type classification list should be checked against this site's
  actual Ultimate Member field types during the same QA pass.

== Changelog ==

= Unreleased (remediation release) =
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
