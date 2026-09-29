# E-C5B-RVW-01 — C5b independent delta review (leader, 2026-09-11)

Inputs: GOV_TARGET C5b text (scope/acceptance/invariants/save-state table),
scoped diff of the C5b delta, run evidence e-c5b-verify-01..02, and the
files/contracts needed to understand the diff. No re-execution by the reviewer.

## Delta under review (existing, not authored by this session)
- includes/Integrations/Amelia.php: apply_selected_services_save() implementing
  the closed save-state table (professional-persona gate → catalog-availability
  gate → normalize/intersect/merge; orphaned stored selections kept in usermeta,
  merged back on every save); hook_profile_services_save() presence-checked
  wrapper (omitted field = no change); register_selected_services_save_hook()
  static-guarded one-time registrar; route_profile_services_save() resolving the
  shared instance via Bootstrap with fail-safe no-op.
- includes/Bootstrap.php: the ONE production registration call, wiring phase
  only (never the constructor — S-01 intact).
- NEW tests/Unit/SelectedServicesSaveContractTest.php (10 tests).

## Acceptance criteria check (C5b text, one by one)
1. "المهني يعدل خدماته فقط" — PASS: persona gate (C1-owned mapping, role +
   approved status; unresolvable = fail-closed) precedes everything.
2. "العضو العادي لا يرى الحقل ولا يستطيع فرضه بـPOST" — PASS:
   test_ordinary_member_rejected_and_unchanged — POST never widens anything;
   ordinary member saves nothing.
3. "omitted يختلف عن explicit empty" — PASS: hook is presence-checked
   (array_key_exists); omitted returns $to_update untouched; explicit empty
   removes still-eligible stored selections while orphaned survive
   (test_explicit_empty_keeps_orphaned_stored_selections).
4. "unavailable لا يمحو الاختيارات" — PASS: catalog-state gate returns the
   stored value with a safe machine reason (test_unavailable... +
   test_not_configured...).
5. "لا double registration" — PASS: static $registered guard + registered only
   from Bootstrap (grep: single call site); test_single_hook_registration...
   asserts never-via-constructor (S-01 intact).
6. "تقلص allowlist مع حفظ قيمة أخرى لا يحذف orphaned" — PASS:
   test_allowlist_shrink_preserves_orphaned_in_usermeta — orphaned stay stored,
   merged back, hidden from display.
7. "إزالة orphaned ليست ضمن هذه البطاقة" — PASS: no deletion path exists
   anywhere in the delta (direct read confirmed).
8. Save-state table rows 1-7: each row has a literal test (verified one-by-one
   in the C5 audit; test names map to rows).

## Invariants & S-01 check
- Legacy direct-save body filter_profile_services_before_save() byte-review:
  UNTOUCHED contract (3-arg signature with $form_data, empty-catalog → []
   wipe per v1.1.5 cases 1-3) and calls NEITHER apply_selected_services_save
  NOR is_professional_persona — test_direct_save_legacy_body_is_unchanged
  asserts this against the source; my direct read confirms.
- No service IDs/values in Runtime Evidence: machine-readable reason codes only
  ('invalid_user', 'persona_not_professional', 'catalog_<state>', 'saved').

## Scope check
Delta confined to C5b's allowed files (Amelia.php save sections, Bootstrap
registration call, the new test). No AmeliaFieldsWriter involvement, no UM
form creation, no service create/modify, no cross-user saves.

## Evidence-limits check
- e-c5b-verify-01: SelectedServicesSaveContractTest exit 0 (10 tests, 28
  assertions) — clean first run (the test self-defines the API-key constant in
  setUp, immune to the C5 ordering pattern).
- e-c5b-verify-02: lint exit 0 ×3.
- Disclosed secondary write: .phpunit.result.cache rewritten.
- Live UM-form wiring + user-facing blocked-save notification remain
  Not-Verifiable locally (card's own note) — runtime pending by design.

## Verdict
NO BLOCKING FINDING. The existing C5b delta satisfies every C5b acceptance
criterion with preserved exit-code evidence. Recommended §12 verdict:
PASS C5B LOCAL — RUNTIME PENDING (append-only; the EXECUTED — TEST EVIDENCE
MISSING row stays as history).
