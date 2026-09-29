# E-C8-RVW-01 — Leader's single independent review of card C8 (2026-09-13) — with the card-level verdict

## Scope verification
- mtimes isolate the C8 delta to EXACTLY 2 files: OptionalIntegrations.php (16:34) + new DashboardLinkContractTest.php (16:37). Bootstrap.php (09-11), Dependencies.php (08-27), ProfileOverview.php (09-08) untouched.
- git status: M 47→48 (+1 = OptionalIntegrations), +1 untracked (the new test file), 0 staged. Stray-tree incident (second حسam typo by the executor) verified deleted by the leader via read-only D:\ listing — no stray tree exists; no external project directory was ever accessed.

## Code review vs card invariants (final states read)
- The published contract: `OptionalIntegrations::get_profile_overview_link(int $viewer_id): ?string` — data-only (never echoes/renders; fixture asserts zero output and no <a>/<form>/<nav>), canonical route = UM's own profile URL (the exact route where C3's Overview renders; no second rewrite invented), ordered fail-closed gates: owned detection filter (strict true) → owned readiness filter (strict true) → viewer>0 + UM present → get_userdata resolves → persona verdict from the owned Settings catalog (exactly-one-match, UM status guarded like ProfileContext) → um_user_profile_url exists → URL non-empty absolute http(s).
- Feature detection is WITHOUT hard dependency: two HAL-OWNED filters (hal_member_profiles_frontend_dashboard_detected / _ready), default false, exact-boolean-only (1/'yes'/'true'/array all fail closed — tested). This is HAL publishing ITS OWN contract, not inventing the external project's.
- Absence/not-ready/invalid/unauthorized ⇒ null only hides the link; Dashboard absence leaves HAL boot + UM surface intact (tested directly).
- No presentation gate reached: the executor proved existing interfaces represent the contract (no new adapter) and the UM URL is the owned route (no new rewrite) — both gates correctly NOT triggered, evidence documented.
- One in-cycle fix used: test-side filter arming in the executor's own new test file; NOTE (non-blocking evidence-practice observation): unlike C7's convention, the failed first run was re-run into the SAME evidence file (e-c8-verify-02) instead of being preserved under a distinct EXIT=1 ID. The fix itself was in-scope and legitimate; the failed output is not separately preserved — recorded here as the audit trail.

## Verification (preserved, reproduced counts)
e-c8-verify-01 lint ×2 EXIT=0; -02 DashboardLinkContractTest 8/28 EXIT=0; -03 ProfileOverviewTest 4/50; -04 WiringTest 12/119; -05 FULL suite 210/913/2 skips EXIT=0. Full-suite delta vs pre-C8 (202/885) = +8 tests/+28 assertions = exactly the new fixture.

## Acceptance mapping (the card's LOCAL, provable side)
- One link / no duplication: proven as data-only provider (zero echo, markup-negative, stable repeat call).
- Dashboard absence breaks nothing: proven (null + boot intact + UM URL intact).
- No link for visitor/unauthorized: proven (visitor 0 ⇒ null; unmapped/unapproved member ⇒ null).
- present/absent/not-ready/invalid-route/unauthorized matrix: all tested green.

## CARD-LEVEL VERDICT (leader's call, per the card's stop condition): BLOCKED — EXTERNAL CONTRACT MISSING
Basis: the card's stop condition makes the existence of a stable PUBLIC extension hook/route contract in HAL Frontend Dashboard a prerequisite for the card — and prescribes this exact verdict when it is absent, with two owner options to follow. The governing record (GOV_TARGET lines 1090 + 1129) documents the planning-phase read-only examination: the external repo (Carrier/Release architecture, under development) proves NO published Runtime and NO extension hook consuming Member Profiles' contract. Nothing inside the root contradicts that record; the external project cannot be re-examined under the containment rule (outside PROJECT_ROOT). Unlike C6b, no internal contract basis exists here: the consuming contract must come FROM the external project, and the card forbids Member Profiles inventing one ("لا يخترع عقدًا له"). The consumption side is therefore unmeetable in this run, and the verdict honestly records that rather than lowering acceptance.
The LOCAL work is complete, green, and is a prerequisite for BOTH owner options — nothing about it is wasted. This BLOCKED is a documented dependency state, not a run failure: no acceptance lowered, no scope violation, stop condition applied exactly as written.

## Evidence limits
Unit/fixture proofs only; the link appearing inside the real external Dashboard NOT VERIFIED (needs the external project's own extension contract + authorized environment); no live/network; no external project accessed.

## Review verdict
NO blocking finding on the executed work. Card verdict recorded: BLOCKED — EXTERNAL CONTRACT MISSING (stop condition), local contract delivered and PASS-verified, owner decision point opened per the card: [a] independent Change Request to the external project, or [b] defer C8 and redefine its Definition of Done (owner ruling/document). Until the owner resolves C8, C9 cannot close (it requires C1..C8) and the global PASS gate stays open.
