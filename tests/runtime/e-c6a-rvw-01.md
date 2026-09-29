# E-C6A-RVW-01 — C6a independent delta review (leader, 2026-09-11)

Inputs: GOV_TARGET C6a text (mode table, scope, acceptance, invariants,
stop conditions), the scoped diff of this card's patch, run evidence
e-c6a-verify-01..09 + e-c6a-ab-iso.txt, and the files/contracts needed to
understand the diff. Reviewer re-ran nothing (the runs above are the
implementer-side verification of this same authorized cycle).

## Patch under review (this card's own work — first fresh implementation card)
- includes/SchemaRegistry.php: TWO new semantically distinct gates,
  mode_allows_create() (both managed modes) and mode_allows_update()
  (managed_sync ONLY — managed_additions is create-missing-only per the
  owner-approved table). mode_allows_write() retained unchanged as the
  legacy "any write" compatibility view consumed by AmeliaFieldsWriter
  (C6b's surface — NOT modified by this card) and RuntimeEvidenceReporter.
  All gates fail-closed via current_sync_mode()'s normalization (unknown
  mode => off).
- includes/Settings.php: UI option text corrected to truth —
  managed_additions now reads "create missing HAL-owned fields only, never
  update"; managed_sync reads "create missing and update HAL-owned fields".
  No value/sanitize/default change (verified: sanitize list and defaults
  untouched).
- includes/AdminDashboard.php: one new capability row fed EXCLUSIVELY from
  the server-side gates (read/diff ride mode_allows_read, create missing =
  mode_allows_create, update = mode_allows_update, delete: never literal).
  The UI cannot claim an ability the mode lacks.
- tests/Unit/SchemaRegistryTest.php: two new cases — the full 4-mode table
  asserted across every gate, and an unknown-mode fail-closed case.

## Acceptance criteria check (C6a text, one by one)
1. "الأوضاع مختلفة دلاليًا في policy والplan والواجهة" — PASS: policy =
   the two distinct server-side gates (single source, server-side);
   plan semantics are exposed for C6b's consumption through those gates
   (build_plan itself is C6b scope and untouched); UI = corrected option
   text + dashboard capability row. Mode transition table (delivery item)
   = the 4×5 table asserted literally in test_c6a_mode_table_gates_*.
2. "الوضع غير الصحيح fail-closed" — PASS: unknown mode normalizes to off
   and every gate returns false (asserted).
3. "off/discover_only لا يتيحان apply" — PASS: write/create/update all
   false for both (asserted per-mode); the existing Writer gate path
   (mode_allows_write) keeps refusing them — unchanged and still green in
   the governance-matrix test.

## Invariants check
- "discovery لا يساوي apply": read/diff remain read-only through
  mode_allows_read; apply capability lives only behind create/update/write
  gates. PASS.
- "mode change لا ينفذ كتابة": gates are pure predicates; no mode change
  triggers any write (patch adds no write path anywhere). PASS.
- "UI لا يدعي قدرة لا يملكها الوضع": both UI surfaces now derive their
  claims from the gates / corrected literal text. PASS.

## Scope check
Patch confined to C6a's allowed files: SchemaRegistry.php, Settings.php,
AdminDashboard.php, SchemaRegistryTest.php. AmeliaFieldsWriter.php NOT
touched (C6b); no services/employees/bookings code; no delete verb
introduced anywhere; no auto-apply.

## Verification record
- e-c6a-verify-01: lint exit 0 ×4.
- e-c6a-verify-02: SchemaRegistryTest exit 0 (10 tests, 55 assertions)
  — includes the 2 new C6a cases.
- e-c6a-verify-03: SettingsTest exit 0 (8 tests, 21 assertions).
- e-c6a-verify-04: AdminDashboardPermissionsTest exit 0 (9 tests, 50).
- e-c6a-verify-06: RuntimeEvidenceReporterTest exit 0 (6 tests, 68) —
  legacy any-write consumer intact.
- e-c6a-verify-08: FULL unit suite exit 0 (189 tests, 755 assertions,
  2 skipped real-WP cases) — greener than pre-card (187/730) by the new cases.
- e-c6a-verify-05/09 + e-c6a-ab-iso.txt: AmeliaFieldsWriterTest shows 2/7
  failures — PROVEN PRE-EXISTING by A/B stash isolation (identical failures
  with the C6a patch removed); root cause: sodium extension absent in this
  local PHP (is_crypto_available() false => set_amelia_api_key refuses
  'crypto_unavailable' => transport 'no_key'). Environment-conditioned,
  outside C6a files/contracts, NOT fixed here (adjacent-fix prohibition);
  escalated as a pre-existing inherited item for the owner/acceptance-env
  stage. Full-suite ordering masks it (constant defined by an earlier test
  file in the same process).
- Disclosed secondary write: .phpunit.result.cache rewritten.

## Not-Verifiable / limits
- The card's own note: local proof covers policy/UI semantics only; real
  apply against a live Amelia API belongs to C6b and its authorized gate.
- The 2 writer-test failures do not lower C6a acceptance (they fail in the
  transport-provisioning layer of a DIFFERENT card's suite, reproducibly
  without C6a present).

## Verdict
NO BLOCKING FINDING. The patch satisfies every C6a acceptance criterion
with preserved exit-code evidence, stays inside scope, and honors all three
invariants. Recommended §12 verdict: PASS C6A LOCAL — RUNTIME PENDING
(the inherited sodium/no_key writer-test failures recorded separately, not
erased).
