# E-C4-RVW-01 — C4 independent delta review (leader, 2026-09-11)

Inputs: GOV_TARGET C4 text (scope/acceptance/invariants), scoped diff of the C4
delta, run evidence e-c4-verify-01/02, and the files/contracts needed to
understand the diff. No re-execution, no tests re-run by the reviewer.

## Delta under review (existing, not authored by this session)
- includes/Integrations/UltimateMember.php: two new methods,
  render_profile_photo(target,viewer,form,persona) and
  render_profile_photo_edit_action(args,is_owner). Photo display flows ONLY
  through Policy::can_view_header_element('avatar') typed pipeline (TYPE_IMAGE
  + esc_url http/https + esc_attr alt); edit action = UM's own captured
  um_pre_header_editprofile hook, owner-only (is_owner gate before capture);
  every failure path returns '' (safe absence, no guessed image, no HAL
  substitute control).
- includes/Elementor/Widgets/ProfileActions.php: comment-only change (+5 lines
  doc note on the shared delegated source). No behavior change — verified by
  direct read: render() body untouched.
- NEW tests/Unit/ProfilePhotoDelegationTest.php (7 tests: owner photo through
  Policy pipeline; visitor public photo privacy-gated; missing form = safe
  absence; edit action owner-only incl. UM-side callback pass-through; literal
  forbidden-write-hooks list assertion; UM-absent collapse to ''; private avatar
  gated through Policy).

## Acceptance criteria check (C4 text, one by one)
1. "المالك وحده يرى edit action" — PASS: is_owner gate precedes hook capture;
   other-user/guest get '' (test_edit_action_is_owner_only).
2. "الزائر يرى الصورة أو fallback حسب الخصوصية" — PASS: visitor path through
   Policy verdict only; private → '' (test_visitor_public_photo... +
   test_private_avatar_privacy_gates_display).
3. "لا HAL write hook أو مخزن جديد" — PASS: forbidden-hooks assertion covers
   profile_update/personal_options/upload/attachment/um update hooks; direct
   read of both methods confirms no write, no parallel storage.
4. "غياب UM يخفي edit action بأمان" — PASS: UM absent → context unresolvable →
   '' (test_um_absence_hides_everything_safely); no fatal.

## Scope check
All delta files inside C4's allowed scope (UltimateMember.php,
ProfileActions.php + the test). NativeHeader.php and
ProfileHeaderCompatibility.php (also in C4's allowed scope) are UNTOUCHED —
git diff --stat empty for both; live public-profile photo rendering continues
through UM's native line, and the two new methods stand as the delegation API
for C7's surface. No consumer wiring was added elsewhere (grep: no live callers
outside the test) — consistent with delegation-not-replacement.

## Evidence-limits check
- e-c4-verify-01: ProfilePhotoDelegationTest exit 0 (7 tests, 27 assertions).
- e-c4-verify-02: lint exit 0 ×3 C4-scope files.
- Disclosed secondary write: .phpunit.result.cache rewritten by the run.
- Card's own Not-Verifiable note stands: real UM edit-UI behavior in the
  installed UM version is not provable from stubs/mocks; runtime pending.

## Verdict
NO BLOCKING FINDING. The existing C4 delta satisfies every C4 acceptance
criterion with preserved exit-code evidence. Recommended §12 verdict:
PASS C4 LOCAL — RUNTIME PENDING (append-only; the EXECUTED — TEST EVIDENCE
MISSING row stays as history).
