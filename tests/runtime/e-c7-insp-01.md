# E-C7-INSP-01 — Owner-commissioned independent inspection of card C7 delivery (2026-09-13, leader personally, zero agents)

## Method
Leader re-performed verification personally, no agents: containment/scope re-confirmation, protected-artifact mtimes, seam caller tracing, full read of the decisive test matrix and final code states (closure point, validation gate, persona selection, adapter diff vs HEAD), stray-artifact sweep, then FRESH re-execution of the complete verification matrix (inspection evidence e-c7-insp-01..09.txt, distinct from the card's e-c7-verify-* acceptance evidence).

## Containment / scope (re-verified)
Root real dir on D:\, no stray tree, 0 staged, M=47 / untracked=11 unchanged since delivery. C7's own edit set re-confirmed by mtimes as exactly the 7 claimed files. PROTECTED ARTIFACTS PROVEN UNTOUCHED: manifest.json (09-11 22:29), resources template (09-11 22:27), Child Theme template (09-08 14:47), Lifecycle.php / Elementor/Register.php (09-07), OptionalIntegrations (08-17) — all pre-date today's C7 run entirely. The UM profile template invokes the adapter seam (render_or_fallback) both in resources and Child Theme copies — the design still routes through the plugin, zero template writes needed.

## Fresh reproduction (all by the leader's own hands — every EXIT=0)
| Verification | Delivered claim | Leader re-run | Match |
|---|---|---|---|
| Lint ×7 | EXIT 0 | EXIT 0 (insp-01) | ✓ |
| ManagedTemplatesTest | 19/91 | 19/91 (insp-02) | ✓ |
| LifecycleReconciliationTest | 7/24 | 7/24 (insp-03) | ✓ |
| WiringTest | 12/119 | 12/119 (insp-04) | ✓ |
| SettingsTest | 8/21 | 8/21 (insp-05) | ✓ |
| ProfileContextTest | 8/29 | 8/29 (insp-06) | ✓ |
| PolicyTest | 17/63 | 17/63 (insp-07) | ✓ |
| ProfileOverviewTest | 4/50 | 4/50 (insp-08) | ✓ |
| FULL unit suite | 202/885/2 skips | 202/885/2 skips (insp-09) | ✓ |

Zero number drift. No new stray artifacts (tests/tmp-d17 contains 590 EMPTY directory husks from 2026-08-26 — pre-existing pattern from the committed D-17 sandbox helper (unlink-files-only), zero files, pre-dating C7 and out of its hands).

## Substantive code findings (final states read by the leader)
1. provision() closure point re-read: fail-closed AFTER capability+manifest diagnosable verdicts, BEFORE immutable/child-theme/filesystem/lock/state — exactly as claimed; tests prove scandir byte-identical, no lock transient armed, no state option, no .hal-tmp even with fs_fail_put hostile.
2. Adapter diff vs HEAD personally read: C7's delta is ONLY (a) capture resolved context once, (b) select template via get_public_profile_template_id_for_persona(owner persona). All prior F-13 gates preserved verbatim; null ⇒ Native exactly once; no output path changed.
3. Legacy matrix read in full: per-type valid wins (77); unselected type reads legacy 55 (key never deleted/migrated); configured-but-invalid (999) ⇒ null ⇒ Native (never silently substituted); deleted legacy (555) ⇒ Native for that persona while other personas' valid selections still resolve; non-admin cannot change any selection (fails closed to stored values).
4. Validation gate matrix read in full: deleted (999) / wrong-type (page 41) / unpublished draft (42) / published-but-non-builder (43) all ⇒ null ⇒ Native; read-only get_post/get_post_meta only, zero writes.
5. Judgment call (superseding D-04/D-05 write-contract tests) re-examined as delivered: the rewritten contract is STRICTER (zero-write even against hostile filesystem; conflicted operator file survives untouched via read-only inspect; retired upgrade path writes nothing even on manifest drift). Lifecycle.php production code confirmed untouched — its containment path records writes_disabled_v1_2 and keeps the pending marker. Consistent with the card's Legacy Conflict Removal mandate and rollback clause.
6. Render test read: valid per-type template renders data-template="77" with owner persona + allowed fields only; native counter 0; visitor test (delivered claim) gives the OWNER's template, not the viewer's.

## VERDICT
PASS C7 LOCAL — RUNTIME PENDING **CONFIRMED**. All delivered claims and evidence fully reproduce under the leader's own hands; no scope violation; no protected artifact touched. Evidence limits unchanged: unit proofs with stubs; visual matching inside Elementor/site NOT VERIFIED (authorized acceptance env later); no live/network. Secondary writes disclosed: .phpunit.result.cache + 9 inspection evidence files + this report.
