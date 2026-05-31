# Phase 0 Research — EMR Station Transitions

This document records decisions taken to resolve open questions from the spec and the planning phase, before any code is written.

---

## R1: How is `current_station` stored on Visit?

**Decision**: New base field `current_station` of type `list_string` with allowed values:
`registration`, `triage`, `lab`, `clinical`, `pt`, `pharmacy`, `teaching`. Required, single-value, revisionable, default = `registration`.

**Rationale**: Matches the existing pattern used for `Visit.status` and `Visit.patient_type` (both list_string base fields). Avoids a taxonomy term (no reason to make these editable by site users) and avoids a separate state-machine module (Drupal contrib `state_machine` is mature but adds dependencies; our state set is fixed and small).

**Alternatives considered**:
- `state_machine` contrib module — overkill for 7 states with a near-linear progression and one conditional skip.
- Workflows core module — better fit conceptually but introduces a transition-permission layer that overlaps with our existing role/field-access scheme; would require non-trivial integration with `librechart_visit_entity_field_access()`.
- Taxonomy term — wrong abstraction; stations are not user-editable content.

---

## R2: Terminal state after Teaching → what value lives in `current_station`?

**Decision** (revised per Clarifications 2026-05-19, Q1): Add an 8th allowed value `complete` to `current_station`. The terminal transition (Teaching → complete) writes BOTH `status = 'complete'` AND `current_station = 'complete'` in the same save. Floor view filters on `status != 'complete'` so the sentinel never appears in any column. The picker excludes `complete` from its options (it is not a workflow station).

**Rationale**: Makes the "is this visit done?" check answerable from either field independently — useful for views, exports, and revision-log readability (the last log message reads "Marked visit complete at Teaching & Referrals" with the station field reflecting that completion). Two-field updates inside one save are atomic; the cost is small compared to the readability gain. The reopen control (FR-021) provides the inverse path back into the workflow.

**Alternatives considered**:
- Keep `current_station = 'teaching'` after completion, rely on `status` alone. Rejected by the user during clarification — preferred the explicit terminal sentinel so completion is signaled in both fields.
- Null out `current_station` on completion. Rejected — breaks the field's "required" semantic and makes the strip render harder for completed visits.

---

## R3: Who is allowed to transition a visit out of a given station?

**Decision**: The role that *owns* the current station is allowed to transition; all other clinical roles can view the visit and read the station strip but the "Send to …" button is hidden from them. Administrators bypass (already handled by `administer visit entities`).

Mapping (drawn from `patient-flow.md` and existing `librechart_visit_entity_field_access`):

| Station | Owning role(s) |
|---------|---------------|
| Registration | registration_staff |
| Triage | triage_nurse |
| Lab Orders & Results | lab_technician |
| Clinical Evaluation | clinician |
| Physical Therapy | physical_therapist |
| Pharmacy Dispensing | pharmacist |
| Teaching & Referrals | teaching_coordinator |

**Rationale**: Mirrors the field-level edit access already enforced per station, so the transition permission feels consistent (you can only transition out of a station you can edit at). Avoids adding a new permission per station; uses role + current_station comparison in form_alter.

**Alternatives considered**: One permission per station (e.g., `transition visit from triage`). Rejected as 7 new permissions duplicate role information already encoded.

---

## R4: Where does the station strip render on the visit form?

**Decision**: At the very top of the visit edit form, inserted via `hook_form_alter` (specifically `hook_form_BASE_FORM_ID_alter` on `visit_form` so it covers add and edit). Rendered as a flexbox row of 7 step badges with CSS pseudo-element connectors. Current station highlighted; completed stations dimmed/checked; upcoming stations outlined.

**Rationale**: Top-of-form is consistent with the patient chart layout already in place (`PatientChartController` puts demographics → visit form). One DOM insertion via the existing form alter pattern; no template override required. Pure CSS, no JS.

**Alternatives considered**:
- Drupal block placed in a region above the visit form — requires region wiring per theme; visit form lives across two themes (Gin admin, Mercury frontend) and we'd need duplicate block placements.
- Field formatter on the `current_station` field — would also work but couples the indicator to the field's view display, harder to control placement around the form.

---

## R5: How does the "Send to next station" action interact with the existing form?

**Decision**: A new secondary submit button next to the standard Save button. Its `#submit` callback (custom) runs the StationWorkflow logic, sets `current_station` to the next value, and then defers to the standard entity save flow. The user does not have to fill the entire form to transition; pressing the button saves whatever is filled and advances the station in the same operation.

**Rationale**: One click = save+advance is the workflow the clinic actually wants. Combining the actions also means the revision log captures field changes and the station change atomically.

**Alternatives considered**:
- Separate "Save" then "Send to next" — requires two clicks; mismatch with the spec verb "When they are finished and sent…"
- Confirmation dialog before transition — adds friction; the action is reversible (admin can revert via revision rollback if needed).

---

## R6: Conditional skip when no PT Referral

**Decision**: `StationWorkflow::nextStation(Visit $visit)` returns:
- From `clinical`: if `pt_referral` field is truthy → `pt`, else → `pharmacy`.
- From `pt`: always → `pharmacy`.
- All other transitions: the next station in the canonical order.

**Rationale**: Encapsulates the one conditional path in a single pure function — easy to unit-test, easy to reason about, easy to extend if a second conditional station ever appears (e.g., dental).

**Alternatives considered**: Encoding skip rules in config. Rejected because the rule lives in PHP business logic anyway (it queries another field on the same entity) — adding a config layer would just be indirection.

---

## R7: Floor page — implementation approach

**Decision**: Custom controller (`FloorController::board`) returning a render array with one `'#type' => 'container'` per station. Each container holds the visit list for its station, fetched with a single entity query grouped by `current_station`. Cache by `visit_list` cache tag.

**Rationale**: A Views display with grouping could work but expressing 7 specific columns (always shown, even when empty) is awkward in Views — the controller is ~30 lines and easier to reason about. Direct cache tags on `visit_list` mean any visit save invalidates the page.

**Alternatives considered**:
- Views with grouping by current_station — empty groups disappear (workflow doesn't surface 0-patient stations), and column color coding would still need preprocess.
- Real-time updates (BigPipe / WebSocket) — out of scope per SC-003 (page reload is acceptable for v1).

---

## R8: Backfill for existing seeded visits

**Decision**: One-shot `scripts/backfill_current_station.php` invoked manually after deploy. Sets `current_station = 'teaching'` for any visit where `status = 'complete'`, `'registration'` for any visit where `status = 'in_progress'`. Idempotent (skips visits that already have a current_station set).

**Rationale**: The seeded 230 sample visits were created without a current_station; new visits going forward get the default. Skipping the migration means existing rows hold NULL, which breaks the visit form's strip (no current station to highlight).

**Alternatives considered**: An update hook that runs on `drush updb`. Equivalent but tied to update numbering; a standalone script is easier to inspect/re-run.

---

## R9: How to handle access on the Floor page

**Decision**: Route requires permission `view visit entities` (existing). Inside the controller, the entity query uses `accessCheck(TRUE)` so each user only sees visits they're allowed to view. The page itself is visible to all authenticated clinical roles.

**Rationale**: Reuses the existing visit access handler — no new permission. Honors any custom restrictions the access handler enforces (no leakage of out-of-scope visits).

---

## R10: Test coverage strategy

**Decision**:
- **Unit**: `StationWorkflow` next/skip logic + `canTransitionTo()` (no Drupal bootstrap; pure PHP).
- **Kernel**: Visit entity loads with `current_station`; default value on save; revisions record field changes; picker submit handler updates field and creates revision; picker does NOT apply PT skip; picker does NOT flip status to complete.
- **Functional**: Form submission via the linear button advances the station; non-owning role cannot see either control; floor page renders columns correctly; picker writes the user's exact target choice; tampered picker submission is rejected with a form error and a watchdog warning.

**Rationale**: Mirrors the project's existing test layout (`librechart_visit/tests/`) and the three Drupal test levels per CLAUDE.md.

---

## R11: Picker UI element — dropdown vs. radio group vs. button row?

**Decision**: Native HTML `<select>` (Drupal core `select` form element) with the six non-current stations as options. A small Submit button labelled "Send" sits next to it.

**Rationale**: Native select is keyboard-operable out of the box (Tab focuses, arrows + Enter submit). Compact — fits in the existing form footer area next to the linear button without restructuring the layout. No JS dependency, no Select2 (we already use Select2 for entity reference autocomplete elsewhere, but it's overkill for 6 fixed options). Accessible by default.

**Alternatives considered**:
- Radio button group with 6 buttons + Submit — more click-friendly but 6× the screen real-estate.
- One button per station ("Send to Triage", "Send to Lab", …) — instant submit, but seven side-by-side buttons clutter the form. Could be revisited if the dropdown proves slow.
- Modal/dialog with picker inside — adds a click; spec asks for ≤2 clicks (SC-007).

---

## R12: Where does the picker live on the form?

**Decision**: In the existing `$form['actions']` group, immediately after the linear "Send to next station" button. The picker sits as a sub-container: a label "Or send to a specific station:", a `<select>`, and a Submit button. Order in the actions row: Save → Send to next → Or send to: [select] [Send].

**Rationale**: Co-locating with the existing transition action keeps the mental model tight. The "Or" language signals the picker is an alternative, not a required step. Inherits Gin/Claro flex-styling from the actions container.

**Alternatives considered**:
- Top of the form near the station strip — visually closer to the indicator but distant from Save.
- A "Reroute" toggle that hides the linear button — removes one option; spec wants both to coexist (A-002).

---

## R13: Picker submit-handler ordering

**Decision**: A new submit handler `librechart_visit_visit_picker_submit()` is attached to the picker's Submit button only. It does NOT chain to the standard entity-form save flow. The handler does its own entity save (load Visit from form state, set `current_station`, write revision, call `save()`).

**Rationale**:
- The picker is a transition action, not a "save this form's inputs" action. Other unsaved fields (e.g. half-filled vitals) must not be persisted by clicking the picker — that would be surprising.
- Matches the linear button's submit pattern (it also does its own save).
- Cleaner test setup: the picker handler operates on a single-field write.

**Alternatives considered**:
- Piggyback on the standard save chain — saves all form inputs + advances station in one click. Rejected for the same reason: explicit transition action should not silently persist unrelated inputs.

---

## R14: `canTransitionTo()` enforces target validity AND user-can-initiate

**Decision**: Single method, returns one bool, evaluates all three rules (target ∈ stations, target ≠ current, user-owns-current OR is admin, visit not complete).

**Rationale**:
- Pure-logic method that returns a single bool is easier to reason about than two methods.
- The two checks are always evaluated together at the call site; collapsing removes a class of "forgot to check role" bugs.
- One unit test class covers both axes.

**Alternatives considered**: Split into `isValidTarget()` + `userCanTransition()`. Cleaner in theory; the two are never called separately in practice.

---

## R15: What happens when a non-owner submits the picker via a tampered request?

**Decision**: `canTransitionTo()` returns false. The submit handler emits `$form_state->setErrorByName('station_picker', t('You may not transition this visit from its current station.'))`. The entity is not saved; no revision is created; watchdog logs a `warning` containing acting user uid, visit id, and attempted target.

**Rationale**:
- Visible UI error so a legitimate user who somehow lost their role mid-session sees what happened.
- Watchdog warning so persistent tampering is investigable without flooding logs.
- No silent failure — every rejected request emits one user-facing message and one log entry.

---

## R16: Telemetry for picker transitions

**Decision**: Every successful picker transition logs a `notice` to watchdog (`librechart_visit` channel) with `mechanism=picker, vid, from, to, uid`. Rejected attempts log `warning` per R15. The linear shortcut does NOT emit a watchdog notice (the revision log message is sufficient).

**Rationale**: Lets supervisors run a watchdog query to count out-of-order transitions ("how often are people deviating from the linear path?") without parsing revision logs. Same logging pattern as the existing pharmacy-override warnings.

---

## R17: PT-skip rule scope

**Decision**: The PT-skip rule applies **only** to the linear shortcut (Mechanism A). The picker honors the user's explicit target — no auto-skip, no auto-include. If a user with `pt_referral = false` selects `pt` from the picker, the visit goes to `pt`.

**Rationale**: The picker exists precisely so clinicians can override the linear rules. Re-applying PT-skip on top of the picker would be the worst of both worlds — overrides that don't actually override.

---

## R18: Status flip scope

**Decision**: Routing into Teaching via the picker does NOT flip `status` to `complete`. Only the linear shortcut's terminal transition (advancing past Teaching) flips status.

**Rationale**: Picker routes can be backward, sideways, or experimental — completion should remain a deliberate one-click action. Otherwise a misclick "send to Teaching" via the picker would close out the visit unexpectedly.
