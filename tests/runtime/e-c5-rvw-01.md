# E-C5-RVW-01 — C5 independent delta review (leader, 2026-09-11)

Inputs: GOV_TARGET C5 text (scope/acceptance/invariants), scoped diff of the C5
delta, run evidence e-c5-verify-01..07, and the files/contracts needed to
understand the diff. Reviewer re-ran nothing; the fix cycle below was executed
by the same authorized run per the one-fix rule, with all outputs preserved.

## Delta under review (existing, not authored by this session)
- includes/SchemaRegistry.php: amelia_catalog_state() five-state taxonomy
  (valid_current / valid_empty / stale_previous_valid / unavailable /
  not_configured) with mode/key gates before snapshot read; stale snapshots
  survive schema bumps explicitly; read-only (no writes during a read).
- includes/Integrations/Amelia.php: catalog_state()/get_service_catalog()
  consuming the taxonomy; row cleaner (id+title validation, size limits);
  PII-free by construction (employees contribute IDs only).
- NEW tests/Unit/AmeliaCatalogStateTest.php (9 tests covering the five states,
  malformed/oversized rows, failed-refresh preservation, no-PII).

## Verification + ONE authorized in-cycle fix (full record)
- e-c5-verify-01.txt: FIRST RUN FAILED — 8/9, exit 1. All snapshot-seeding
  cases returned not_configured/no_key instead of their expected states.
  AmeliaApiClientTest (e-c5-verify-02, exit 0) and SchemaRegistryTest
  (e-c5-verify-03, exit 0) passed.
- Root cause PROVEN by minimal repro (recorded in channel): the card test's
  setUp seeded only the sync mode; the taxonomy correctly reads key presence
  through SecretStore, which resolves the process-wide constant first. In a
  single-file run no earlier file had defined the constant → has_amelia_key()
  false → not_configured/no_key. The test file's own comment documented the
  ordering assumption ("the process may already carry the constant from earlier
  test files") — an invalid test-side assumption, not a product defect: the
  production classification order (mode → key → snapshot) is correct per card
  C5 (not_configured must win when unconfigured).
- One fix attempt ALLOWED and applied (cause inside the card's own test file,
  no owner decision/environment needed, no stop condition touched): setUp now
  defensively defines the constant (guarded, dummy value, presence-only), with
  the corrected comment. Product code untouched.
- Re-verification after fix (e-c5-verify-04..07): card test exit 0 (9 tests,
  29 assertions); AmeliaApiClientTest exit 0 (7 tests, 33 assertions);
  SchemaRegistryTest exit 0 (8 tests, 30 assertions); PHP lint exit 0 ×4
  (SchemaRegistry, Amelia, AmeliaApiClient, the test).
- Disclosed secondary write: .phpunit.result.cache rewritten by the runs.

## Acceptance criteria check (C5 text, one by one)
1. "أقل حقول لازمة: service identifier ثابت وlabel آمن" — PASS: cleaner keeps
   well-formed id/title rows only (malformed/oversized drop individually).
2. "preserve previous valid snapshot عند الفشل" — PASS: taxonomy is read-only;
   a failed refresh leaves the stored option byte-identical (asserted).
3. "غياب Amelia يعطل الخدمات فقط" — PASS: not_configured/absence yields empty
   catalog while the member profile keeps working (asserted).
4. "لا PII/secrets في state أو evidence" — PASS: state JSON asserted free of
   email/first_name/secret/api_key; employees contribute IDs only.

## Scope check
Delta confined to C5's allowed files (Amelia.php, AmeliaApiClient.php,
SchemaRegistry.php, DynamicTags.php Amelia-catalog scope, the test). The fix
touched only the card's own test file. AmeliaApiClient.php's delta carries the
S-06/Writer-verb documentation split (pre-existing attribution, noted in the
C5 audit row; fields-writer gating itself is C6b's surface, not activated
here). No out-of-scope writes.

## Evidence-limits check
- Endpoint/version correctness of the real Amelia install is Not-Verifiable
  from local stubs (card's own note) — runtime pending by design.
- The five-state taxonomy, snapshot preservation, and no-PII contract are
  proven by the preserved exit-code evidence above.

## Verdict
NO BLOCKING FINDING after the authorized in-cycle fix. The existing C5 delta
satisfies every C5 acceptance criterion. Recommended §12 verdict:
PASS C5 LOCAL — RUNTIME PENDING (append-only; the EXECUTED — TEST EVIDENCE
MISSING row stays as history; the first-run failure + fix recorded, not erased).
