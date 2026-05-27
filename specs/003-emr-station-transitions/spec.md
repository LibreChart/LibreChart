# Feature Specification: EMR Station Transitions & Status Tracking

**Feature Branch**: `003-emr-station-transitions`
**Created**: 2026-05-19
**Status**: Draft
**Input**: User description: "EMR transitions — we would like a way to transition patients from one station to another and be able to observe which patients are in which phase of their visit. In the patient visit screen, there should be a visual indicator of which station they are currently at. When they are finished and sent to another station the form should change the patient state in their record. The patient-flow.md file in the documentation folder shows this flow. We also want a page that shows which patients are currently at which stations."

## Clarifications

### Session 2026-05-19

- Q: When a visit is marked complete (Teaching → complete transition), what value should `current_station` hold? → A: Add an 8th allowed value `complete` to `current_station` and set it on completion. Both `status = 'complete'` and `current_station = 'complete'` are written in the terminal transition.
- Q: When an admin clicks "Reopen visit" on a completed visit, what state should the visit return to? → A: The reopen action takes a target-station argument — admin picks one of the 7 workflow stations in the same click; on submit, `status` is set to `in_progress` and `current_station` is set to the chosen target.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Station indicator on the visit form (Priority: P1)

A clinical staff member opens a patient's visit form and immediately sees which station the patient is currently at (e.g., "Triage", "Clinical Evaluation"), highlighted on a station progress strip at the top of the form. The indicator reflects the visit's current station value at all times and is visible to every clinical role regardless of which station the user owns.

**Why this priority**: Without a visual cue, staff must guess which station a visit "belongs to" right now, leading to duplicate work or skipped steps. This single indicator is the foundation everything else builds on.

**Independent Test**: Open `/visit/{vid}/edit` for any visit and verify a station strip is rendered with exactly one station visually marked as current.

**Acceptance Scenarios**:

1. **Given** a visit whose current station is "Triage", **When** any clinical staff member opens the visit form, **Then** the station strip shows seven stations and "Triage" is visually highlighted.
2. **Given** a newly created visit, **When** the visit form first loads, **Then** the current station is "Registration" (the initial state).
3. **Given** a visit whose status is "complete", **When** the visit form is opened, **Then** the station strip shows all stations completed and no station is marked as "in progress."

---

### User Story 2 - Advance a visit to the next station (Priority: P1)

The staff member at the patient's current station finishes their work and clicks a "Send to next station" action on the visit form. The visit's current_station field is updated, the patient appears at the next station on the floor view, and the visit form re-renders with the new station highlighted.

**Why this priority**: Without an explicit transition action, station status drifts out of sync with the physical patient location. This is the verb that makes the indicator meaningful.

**Independent Test**: Open a visit currently at "Triage", click the action to advance to "Lab", reload the form, and verify the station strip now highlights "Lab" and the visit record's `current_station` field is `lab`.

**Acceptance Scenarios**:

1. **Given** a visit at station "Triage", **When** the triage nurse clicks "Send to Lab Orders & Results", **Then** the visit's current_station is updated to `lab` and a revision is logged.
2. **Given** a visit at station "Clinical Evaluation" where the clinician has ordered a PT Referral, **When** the clinician clicks "Send to next station", **Then** the next station is "Physical Therapy" (not "Pharmacy"); when PT Referral is not ordered, the next station is "Pharmacy."
3. **Given** a visit at the final station "Teaching & Referrals", **When** the teaching coordinator clicks "Complete visit", **Then** the visit status becomes `complete` and the patient leaves the floor view.
4. **Given** a user who does not own the current station, **When** they open the visit form, **Then** they cannot transition the visit (the action button is hidden or disabled).

---

### User Story 3 - Floor view: which patients are at which stations (Priority: P1)

A clinic supervisor or any staff member opens a "Floor" page and sees a board grouped by station, with each in-progress patient listed under the station they are currently at. The view auto-refreshes (or refreshes on reload) so staff can spot bottlenecks at a glance.

**Why this priority**: This is the supervisory view that makes the workflow legible at the room level — without it, transitions exist but no one sees the bigger picture.

**Independent Test**: Open `/floor` and verify each in-progress visit is listed exactly once under the column matching its `current_station`, while complete visits are excluded.

**Acceptance Scenarios**:

1. **Given** an active clinic day with visits at multiple stations, **When** any staff member opens the floor view, **Then** each in-progress visit is shown under the station that matches its `current_station` value.
2. **Given** the floor view is open, **When** a visit is transitioned to the next station, **Then** the visit appears under the new station after a page reload (real-time push is out of scope for v1).
3. **Given** a visit whose status is `complete`, **When** the floor view is rendered, **Then** the visit is omitted from all station columns.
4. **Given** a station with zero in-progress visits, **When** the floor view is rendered, **Then** the station column appears with an "empty" state message.

---

### User Story 4 - Skip non-applicable stations (Priority: P2)

When the clinician records that no PT Referral is needed, the PT station is automatically skipped during transitions — the visit moves from "Clinical Evaluation" directly to "Pharmacy." When PT Referral is marked, the PT station is included.

**Why this priority**: The patient-flow document specifies PT as conditional. Without this rule, the transition action would push every visit through every station regardless of clinical decisions.

**Independent Test**: Set PT Referral = false on a visit at "Clinical Evaluation", advance the visit, and verify next station is "Pharmacy" (not "PT"). Set PT Referral = true and verify next station is "PT".

**Acceptance Scenarios**:

1. **Given** a visit at "Clinical Evaluation" with `pt_referral = false`, **When** the clinician advances, **Then** the next station is "Pharmacy."
2. **Given** a visit at "Clinical Evaluation" with `pt_referral = true`, **When** the clinician advances, **Then** the next station is "Physical Therapy."
3. **Given** a visit at "Physical Therapy", **When** the PT advances, **Then** the next station is "Pharmacy."

---

### User Story 5 - Route a patient to any station out of order (Priority: P2)

A clinician finishes a clinical evaluation but realizes the patient needs to skip directly to Pharmacy without stopping at PT, or a supervisor wants to redirect a queue of patients away from a backed-up station. From the visit form, the staff member opens a station picker, chooses the desired station, and submits — the patient now appears under the chosen station on the floor view. The picker lives next to the "Send to next station" linear shortcut so the common one-click path is preserved.

**Why this priority**: The linear sequence covers the majority of visits, but clinical judgment and operational load-balancing both demand a way to route out of order. Without this, staff have no UI path for clinician-recommended skips or for distributing patient traffic across stations.

**Independent Test**: Open any in-progress visit, select a target station that is not the next sequential station, submit the picker, then verify the visit's `current_station` matches the selected target and the floor view reflects the new placement.

**Acceptance Scenarios**:

1. **Given** a visit at Triage, **When** the triage nurse selects "Clinical Evaluation" from the station picker and submits, **Then** the visit's current station becomes `clinical` and the floor view shows the patient under Clinical (skipping Lab).
2. **Given** a visit at Clinical Evaluation with PT Referral marked, **When** the clinician selects "Pharmacy Dispensing" instead of PT and submits, **Then** the visit goes directly to Pharmacy (PT is bypassed).
3. **Given** a visit at Pharmacy, **When** the pharmacist selects "Triage" (a backward station) and submits, **Then** the visit moves to Triage and a revision is logged identifying the prior → new station.
4. **Given** a station picker is open, **When** the user views the choices, **Then** all six non-current stations are listed; the visit's current station is not a selectable target.
5. **Given** a user who does not own the current station, **When** they open the visit form, **Then** neither the linear shortcut nor the picker is rendered.

---

### Edge Cases

- A visit that has been "completed" cannot be transitioned further via the linear shortcut or the picker; both controls are hidden. In their place, admins (and only admins, per `administer visit entities`) see a "Reopen visit" control: a small form with a target-station picker (the 7 workflow stations, excluding the `complete` sentinel) and a Reopen submit button. Submitting sets `status = in_progress` and `current_station = {chosen target}` in a single save, logging a revision: `"Reopened visit at {target_label} by {username}."`
- If two staff members try to advance the same visit concurrently, optimistic locking (already implemented on the Visit entity) rejects the second save with a friendly error.
- If the floor view is opened by a user without permission to view a particular visit, that visit is filtered out (not shown).
- New visits default to station "Registration" but registration staff may immediately transition to "Triage" without filling registration-specific fields.
- A user routes a visit to its current station (e.g., Triage → Triage): the picker does not list the current station as an option, so this cannot be selected through the UI. A programmatic submission with the same station is treated as a no-op (no revision logged, no error raised).
- A user tries to route to "complete" via the picker: there is no "complete" station entry in the picker. Completion happens only via the linear shortcut's terminal transition.
- A visit at Teaching is routed backward (say, to Clinical) via the picker: this is allowed; the visit re-enters the in-progress flow at Clinical and the floor view shows it there.
- A non-owning user tampers with the form to submit a picker target: the submit handler rejects the change, sets a form error, and logs a `warning` watchdog entry containing the visit id, attempted target, and acting user id.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Visit entity MUST have a `current_station` field (single-value list, required) with the allowed values: `registration`, `triage`, `lab`, `clinical`, `pt`, `pharmacy`, `teaching`, `complete`. Default value: `registration`. The `complete` value is a terminal sentinel set only by the terminal transition (see FR-005); it is not a workflow station and is not offered by the station picker.
- **FR-002**: The visit edit form MUST display a station progress strip at the top showing all 7 stations in order. The station matching `current_station` MUST be visually distinct (e.g., highlighted color, current-step marker). Completed stations (those before the current one in the workflow order) MUST be visually distinct from upcoming stations.
- **FR-003**: The visit form MUST provide an action button ("Send to next station" / "Send to {Next Station Name}") that, when clicked, advances the visit's `current_station` to the next station in the workflow, taking PT Referral into account (PT is skipped if `pt_referral = false`).
- **FR-004**: The "advance station" action MUST be visible only to users who own the current station's role (per the role/station mapping in patient-flow.md). Users at other stations may view the visit but not transition it.
- **FR-005**: When the visit reaches "Teaching & Referrals" and the teaching coordinator advances, the terminal transition MUST update both fields atomically in the same save: `status` changes from `in_progress` to `complete`, AND `current_station` changes from `teaching` to `complete` (the terminal sentinel from FR-001). Both writes are part of the same revision; if either fails, neither is persisted.
- **FR-006**: A "Floor" page MUST be available at `/floor`, visible to every authenticated clinical role, listing each in-progress visit under a column for its `current_station`. Columns MUST follow the workflow order and use the same color cues as the visit form's station strip.
- **FR-007**: The floor view MUST exclude visits whose `status = complete`.
- **FR-008**: Each visit row in the floor view MUST link to that visit's edit form.
- **FR-009**: Every transition MUST create a Visit revision with a log message identifying the prior station, new station, and acting user (the Visit entity already has revisions enabled).
- **FR-010**: The `current_station` field MUST be set to `registration` on visit create unless overridden by the registration form.
- **FR-011**: The station strip and floor view MUST use the same station labels (canonical English source strings; Spanish overlay applied via existing translation infrastructure).
- **FR-012**: The visit edit form MUST expose a "Send to specific station…" picker alongside the linear "Send to next station" shortcut. The picker MUST list every station other than the visit's current one.
- **FR-013**: Submitting the picker MUST update the visit's `current_station` to the selected target — any of the six non-current stations is a valid target, including stations earlier in the canonical workflow order (backward routing) and stations more than one step ahead (multi-station skips).
- **FR-014**: Both the linear shortcut AND the picker MUST be visible only to users who own the visit's current station (or to administrators). The flexibility introduced is only in the destination, not in who may initiate.
- **FR-015**: The picker MUST exclude the visit's current station from its options so users cannot transition a visit to where it already is. Programmatic same-station submissions MUST be treated as a no-op (no save, no revision, no error).
- **FR-016**: Selecting a target via the picker MUST NOT change the visit's `status` field — only the linear shortcut's terminal transition (from Teaching) flips `status` to `complete`. Routing into Teaching via the picker leaves the visit in-progress.
- **FR-017**: The picker MUST NOT apply the PT-skip rule. If the user chooses `pt`, the visit goes to `pt`, even when `pt_referral = false`. If the user chooses `pharmacy` from `clinical` while `pt_referral = true`, the visit goes to `pharmacy`. The picker honors the user's explicit choice.
- **FR-018**: Every picker transition MUST create a Visit revision with the same log message format as the linear shortcut (`"Transitioned from {prior_label} to {new_label} by {acting_username}."`).
- **FR-019**: Tampered submissions (a non-owning user POSTs a picker target) MUST be rejected at the submit handler with a user-facing form error and a `warning`-level watchdog entry containing visit id, attempted target, and acting user id.
- **FR-020**: No new permissions are introduced by the picker; the "may transition" check remains "owns the current station OR has the `administer visit entities` permission."
- **FR-021**: A "Reopen visit" control MUST be rendered in place of the transition controls on visits with `status = 'complete'`, visible only to users with `administer visit entities`. The control MUST require the admin to pick a target station from the 7 workflow stations (the `complete` sentinel from FR-001 MUST be excluded). On submit, the visit MUST be saved with `status = 'in_progress'` and `current_station = {chosen target}` in a single revision, logged as `"Reopened visit at {target_label} by {username}."`

### Key Entities

- **Visit (existing entity)**: Gains a new base field `current_station` (list_string, required, single-value, revisionable). All other fields remain unchanged.
- **Station (concept, not an entity)**: A logical workflow phase. Seven stations: Registration, Triage, Lab Orders & Results, Clinical Evaluation, Physical Therapy (conditional), Pharmacy Dispensing, Teaching & Referrals.
- **Floor view**: A new admin/staff page rendered as a column-per-station board of in-progress visits.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Opening any visit's edit form shows a station strip with exactly one current station highlighted within the first paint of the page.
- **SC-002**: Clicking "Send to next station" advances the visit's `current_station` and the next form load reflects the new station — no manual edit of the field needed.
- **SC-003**: The floor view at `/floor` displays every in-progress visit exactly once, under the column matching its `current_station`; the page reloads in under 1 second for a clinic day of up to 300 visits.
- **SC-004**: PT station is correctly included or skipped based on `pt_referral`, with no visit ever landing at PT when `pt_referral = false`.
- **SC-005**: A visit's full station history is reconstructable from the Visit's revision log (prior station → new station → who → when).
- **SC-006**: Anonymous users cannot view the floor page; non-owning station roles can see but not transition visits.
- **SC-007**: A staff member can route a visit to any non-current station in two clicks or fewer (open picker → choose target → submit; or one click via the linear shortcut).
- **SC-008**: 100% of picker-driven transitions are recorded in the Visit revision log with prior station + new station + acting user.
- **SC-009**: The floor view reflects an out-of-order transition on the next page load — there is no special "out-of-order" path; the view reads `current_station` regardless of how it was set.
- **SC-010**: The linear "Send to next station" shortcut continues to perform a one-click advance with PT-skip applied (no regression from US2/US4 when the picker is added).

## Assumptions

- **A-001**: The permission model is uniform across both transition mechanisms: only the role that owns the current station (or `administer visit entities`) may initiate any transition. Flexibility added by the picker (US5) is only about *which target station* is chosen, not *who* may initiate.
- **A-002**: The linear shortcut and the picker coexist on the visit form. Removing the shortcut and forcing every transition through the picker is out of scope.
- **A-003**: Completion (status → `complete`) happens only when the visit advances past the Teaching & Referrals station via the linear terminal-transition flow. The picker does not flip the status field.
- **A-004**: The picker does not enforce any "sensible path" rules beyond excluding the current station. Backward routing (Pharmacy → Triage) is allowed because the stated use cases include both clinician overrides and load balancing — neither of which should be artificially constrained by direction.
- **A-005**: No new entity fields are needed beyond `current_station`; the picker is purely UI + submit-handler logic on top of the same field.
