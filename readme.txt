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

Every field decision — visible or hidden — is made by `Policy.php`, which mirrors
Ultimate Member's own Field Privacy settings. This plugin never reads raw user meta
directly in a Widget or Dynamic Tag, never invents a field selector, and never falls
back to showing the current visitor's own data when a target member cannot be resolved.

If the Elementor Library Template for a Profile/Account is deleted, left as a Draft, or
fails Elementor's own `elementor/query/...`-based validation, the page falls back to
Ultimate Member's complete native output automatically — never a blank or partial page.

= What this plugin does not do =

* It does not modify Ultimate Member, Elementor, or Amelia core files.
* It does not add a new REST/AJAX endpoint, a database table, or a cache of UM/Amelia data.
* It does not rebuild Ultimate Member's edit-profile form as separate Elementor controls.
* It does not call any booking API directly; Amelia's own shortcode/form remains the
  final authority on availability and booking acceptance.

== Installation ==

1. Ensure Ultimate Member and Elementor (free) are active first — this plugin declares
   both as hard requirements via `Requires Plugins` and will not activate without them.
2. Upload and activate HAL Member Profiles like any other plugin.
3. Go to **Settings → HAL Member Profiles** and leave both Profile and Account layout
   modes on **Observe** until you have built and tested your Elementor Library Templates
   on staging.
4. Build a Profile Library Template and an Account Library Template in Elementor,
   including the required Widgets for each (Header + Navigation + Body for Profile;
   Navigation + Body for Account) — see `docs/compatibility-matrix.md` for the exact
   rules `LayoutContract.php` enforces before a template is considered complete.
5. Select the matching Elementor Library Template ID in Settings, switch the relevant
   layout mode to **Public layout**, and select the "HAL Member Profiles" template
   override for the Profile Form in Ultimate Member → Forms.
6. Copy the two template overrides from this package's `Child Theme/ultimate-member/templates/`
   directory into your Child Theme's own `ultimate-member/templates/` directory
   (`profile-hal-member-profiles.php` and `account.php`).

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

Both Child Theme template overrides detect this and render Ultimate Member's complete
native pipeline directly, exactly as if this plugin were never installed.

= Does this integrate with Amelia? =

Only through an administrator-maintained, manually managed allowlist mapping a UM member
to an Amelia employee ID and their allowed service ID(s) — never a live sync or copy of
Amelia's own data, and never a direct booking API call. See `docs/compatibility-matrix.md`
§4 before enabling this in production.

== Known limitations at 1.0.0 ==

* Custom Header (fully Elementor-designed header via `Profile Header Compatibility`) is
  only as capable as the UM extensions registered and tested in
  `docs/compatibility-matrix.md` §2 — currently none. Native Header is the safe default.
* Account field selectors (`Account Field` Widget/Tags) are empty until a verified
  Account-tab field source is confirmed in `docs/compatibility-matrix.md` §6.
* The legacy Photo/Dashboard Account tabs are not yet migrated; they remain in
  `um-account-custom.php` until tested per `docs/compatibility-matrix.md` §3.
* `FieldSchema`'s field-type classification list should be checked against this site's
  actual Ultimate Member field types in `docs/compatibility-matrix.md` §5.

== Changelog ==

= 1.0.0 =
* Initial build per `hal-um-elementor-execution-plan.md`: core services, Profile and
  Account Widgets, Dynamic Tags (Elementor Pro), Elementor Library Template adapters
  with native fallback, Amelia allowlist bridge, and Child Theme template overrides.
* Requires PHP 8.0 (raised from 7.4 before the first public release).
* Automatic updates via GitHub Releases (plugin-update-checker ~5.7.0, bundled in vendor/).
