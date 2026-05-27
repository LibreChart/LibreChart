---

description: "Task list for feature 003 — EMR Station Transitions & Status Tracking"
---

# Tasks: EMR Station Transitions & Status Tracking

**Input**: Design documents from `/specs/003-emr-station-transitions/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/station-transitions.md, quickstart.md

**Tests**: Included — research.md R10 explicitly plans unit, kernel, and functional tests; plan.md lists test files in the project structure.

**Organization**: Tasks are grouped by user story so each story can be implemented and validated independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2, US3, US4)
- Include exact file paths in descriptions

## Path Conventions

Single Drupal project. All paths absolute under `/Users/aaronellison/Sites/librechart/`.

- Module code: `web/modules/custom/librechart_visit/`
- Patient module touchpoint: `web/modules/custom/librechart_patient/src/Controller/PatientChartController.php`
- Module tests: `web/modules/custom/librechart_visit/tests/src/`
- Spec docs: `specs/003-emr-station-transitions/`
- One-off scripts: `scripts/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the runtime is ready for the schema update + new code.

- [X] T001 Verify `ddev status` shows the project running and `ddev drush status` reports a connected DB. Confirm `web/modules/custom/librechart_visit/` exists and the Visit entity is registered: `ddev drush field:info visit | head -5` must show the existing base fields (visit_date, patient_type, clinic_site, status). If any check fails, fix the local environment before proceeding.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Install the `current_station` field on Visit so every user story has a place to write to. Backfill seeded visits so the strip + floor view have data to render.

**⚠️ CRITICAL**: No user story work can begin until T005 is complete (field must be installed and existing rows populated).

- [X] T002 In `web/modules/custom/librechart_visit/src/Entity/Visit.php`, add the `current_station` base field inside `baseFieldDefinitions()`: type `list_string`, required, revisionable, single-value, default `'registration'`, allowed values: `registration → Registration`, `triage → Triage`, `lab → Lab Orders & Results`, `clinical → Clinical Evaluation`, `pt → Physical Therapy`, `pharmacy → Pharmacy Dispensing`, `teaching → Teaching & Referrals`. Set form `display_options` to `options_select` with weight 6; view `display_options` to `list_default` with weight 6.

- [X] T003 Create `web/modules/custom/librechart_visit/librechart_visit.install` with a `librechart_visit_update_9001()` hook that calls `\Drupal::entityDefinitionUpdateManager()->installFieldStorageDefinition('current_station', 'visit', 'librechart_visit', $field)` using the same `BaseFieldDefinition` declared in T002. The hook is the install path for already-deployed sites; the entity definition in T002 is the install path for fresh sites. Both must yield identical schema.

- [X] T004 Apply the schema change: `ddev drush updb -y`. Verify the column exists: `ddev drush sqlq "DESCRIBE visit;" | grep current_station` must show `current_station varchar(255)`. Verify revision tracking: `ddev drush sqlq "DESCRIBE visit_field_revision;" | grep current_station` must also show the column.

- [X] T005 Create `scripts/backfill_current_station.php` (idempotent, supports `--revert`): loads every Visit; for each, if `current_station` is NULL, sets it to `'teaching'` when `status = 'complete'`, else `'registration'`; saves without creating a new revision (use `setNewRevision(FALSE)`). Run via `ddev drush php:script scripts/backfill_current_station.php`. Expected output: `Backfilled current_station: 230 visits updated, 0 skipped.`

**Checkpoint**: Foundation ready — field installed, all 230 seeded visits carry a valid `current_station`. User story work can begin.

---

## Phase 3: User Story 1 - Station indicator on the visit form (Priority: P1) 🎯 MVP

**Goal**: Render a 7-station horizontal progress strip at the top of every visit edit form, with the current station visually highlighted.

**Independent Test**: Per quickstart.md US1 — open any in-progress visit's edit form and verify the strip is present, exactly one station is marked as "current", and the color scheme matches `patient-flow.md`. Completed visits show all stations as "done".

### Implementation tasks

- [X] T006 [US1] Create `web/modules/custom/librechart_visit/src/Service/StationWorkflow.php` implementing a `StationWorkflowInterface`. Methods for US1: `stations(): array` returning the seven ids in canonical order; `label(string $station): string` mapping each id to its human label. (US2 and US4 will extend the same class with `nextStation()` and `ownerRole()`; declare those interface methods now but leave a `// TODO: US2` body or throw a "not yet implemented" exception until those stories.)

- [X] T007 [P] [US1] Create `web/modules/custom/librechart_visit/librechart_visit.libraries.yml` declaring a `station_strip` library that loads `css/station-strip.css`.

- [X] T008 [P] [US1] Create `web/modules/custom/librechart_visit/css/station-strip.css` with: a flexbox row container (`.station-strip`), seven badge classes (`.station-strip__step`) with per-station color-coded backgrounds matching `patient-flow.md` (blue/green/yellow/purple/orange/pink/green for registration/triage/lab/clinical/pt/pharmacy/teaching), a modifier `--current` for the highlighted step, and `--done` for completed (dimmed/check-marked) steps. Also include `.floor-board` and `.floor-board__column` rules for US3.

- [X] T009 [US1] In `web/modules/custom/librechart_visit/librechart_visit.module`, add a `hook_form_BASE_FORM_ID_alter` on `visit_form` (covers add+edit). Inject `$form['station_strip']` as a render array at weight -100 (top of form): a container with seven child elements, one per station, each rendered with the appropriate CSS class based on whether it precedes / matches / follows the visit's `current_station`. Attach the `librechart_visit/station_strip` library. For visits with `status = 'complete'`, all 7 steps render as `--done` and none as `--current`.

- [X] T010 [US1] In `web/modules/custom/librechart_patient/src/Controller/PatientChartController.php`, the embedded visit form already renders inside a `<details>` element. No code change needed — the station strip from T009 will appear inside the embedded form when the visit form is built. Verify by opening `/patient/{pid}/edit`: the strip should be visible above the visit fields. If the strip is hidden by the details wrapper, adjust by setting `'#open' => TRUE` on the visit's details element (already the default in 003's controller).

- [X] T011 [P] [US1] Functional test in `web/modules/custom/librechart_visit/tests/src/Functional/StationStripTest.php`: log in as a clinical user, open `/visit/{vid}/edit` for a visit with `current_station = 'triage'`, assert the rendered HTML contains a `<div class="station-strip">` with seven children, and the 2nd child (triage) carries the `station-strip__step--current` class. Add a second scenario: a completed visit shows all 7 steps with `--done` and none with `--current`.

- [X] T012 [US1] Manual smoke test per quickstart.md US1: open three visits in different states (one at triage, one at clinical, one complete), confirm the strip renders correctly in each, including color coding and current-step highlighting.

**Checkpoint**: US1 is independently shippable — staff can see at a glance where any visit is, even without the transition action wired up.

---

## Phase 4: User Story 2 - Advance a visit to the next station (Priority: P1)

**Goal**: Owners of the current station can click a single "Send to {Next Station}" button to advance the visit's `current_station` field, log a revision, and (at terminal Teaching) flip `status` to `complete`.

**Independent Test**: Per quickstart.md US2 — log in as triage_nurse, open a visit at Triage, click "Send to Lab Orders & Results", verify the visit advances to `lab` and a revision is logged.

### Implementation tasks

- [X] T013 [US2] Implement `StationWorkflow::nextStation(ContentEntityInterface $visit): ?string` in `web/modules/custom/librechart_visit/src/Service/StationWorkflow.php`. Logic per data-model.md state diagram: linear next, with `clinical → pharmacy` when `pt_referral = false` (US4 will exercise this skip rule — implement now since it's a single conditional). Returns `null` when current station is `teaching` (terminal handled separately).

- [X] T014 [US2] Implement `StationWorkflow::ownerRole(string $station): string` returning the role id per the contract: `registration → registration_staff`, `triage → triage_nurse`, `lab → lab_technician`, `clinical → clinician`, `pt → physical_therapist`, `pharmacy → pharmacist`, `teaching → teaching_coordinator`.

- [X] T015 [US2] In `web/modules/custom/librechart_visit/librechart_visit.module`, extend the existing form alter (T009) to inject the "Send to next station" button into `$form['actions']`. Button label is dynamic: `"Send to {next_label}"` for non-terminal stations; `"Complete visit"` for the terminal Teaching station. `#submit` is a single-element array pointing to `librechart_visit_visit_advance_submit` (does not chain to the standard save). Add a guard so the button only renders when `_librechart_visit_user_owns_current_station(Visit, AccountInterface): bool` returns true (helper added in this task).

- [X] T016 [US2] Implement `librechart_visit_visit_advance_submit(array &$form, FormStateInterface $form_state)` in `librechart_visit.module`. Steps: (1) load Visit from form object; (2) compute next station via `StationWorkflow::nextStation($visit)`; (3) if null (terminal Teaching): set `status = 'complete'`, set revision log message `"Marked visit complete at Teaching & Referrals by {username}."`, set revision user, save, status message + redirect; (4) otherwise: set `current_station = $next`, revision log `"Transitioned from {prior_label} to {next_label} by {username}."`, save, status message + redirect to the patient chart route.

- [X] T017 [P] [US2] Kernel test in `web/modules/custom/librechart_visit/tests/src/Kernel/VisitTransitionTest.php`: create a visit at each station, call the advance submit handler programmatically, assert `current_station` advances correctly and a revision exists with the expected log message. Include a terminal-station case asserting `status` flips to `'complete'`.

- [X] T018 [P] [US2] Functional test in `web/modules/custom/librechart_visit/tests/src/Functional/VisitAdvanceTest.php`: log in as `triage_nurse`, open a visit at Triage, click the advance button, assert redirect + status message + DB state. Second scenario: log in as `clinician` (wrong role for a Triage visit), open the same visit, assert the advance button is NOT in the rendered HTML.

- [X] T019 [US2] Manual smoke test per quickstart.md US2: as triage_nurse, advance a visit; as clinician with pt_referral=true, advance to PT; as clinician with pt_referral=false, advance to Pharmacy; as teaching_coordinator, advance from Teaching and confirm status flips to complete.

**Checkpoint**: US2 is independently shippable — the strip indicator from US1 now updates via clicks instead of manual DB writes.

---

## Phase 5: User Story 3 - Floor view (Priority: P1)

**Goal**: A `/floor` page renders a column-per-station board of in-progress visits, linkable to each visit's edit form, excluding completed visits.

**Independent Test**: Per quickstart.md US3 — open `/floor` and verify every in-progress visit appears exactly once under the column matching its `current_station`; complete visits are absent.

### Implementation tasks

- [X] T020 [US3] Create `web/modules/custom/librechart_visit/librechart_visit.routing.yml` declaring a `librechart_visit.floor` route at path `/floor` with `_controller` pointing to `Drupal\librechart_visit\Controller\FloorController::board` and `_permission: view visit entities`.

- [X] T021 [P] [US3] Create `web/modules/custom/librechart_visit/librechart_visit.links.menu.yml` adding a "Floor" entry to `system.menu.main` pointing at the route from T020. Weight 5 (after Patients and Visits, before Pharmacy).

- [X] T022 [US3] Create `web/modules/custom/librechart_visit/src/Controller/FloorController.php`. Method `board(): array` performs a single entity query: `getQuery()->accessCheck(TRUE)->condition('status', 'complete', '!=')->sort('visit_date', 'ASC')->execute()`. Group the loaded visits by `current_station`. Render a top-level container `.floor-board` with seven column children `.floor-board__column`. Each column header shows the station label and a `(count)`; column body lists visit rows linked to `/visit/{vid}/edit` with text `"{patient_last_name}, {patient_first_name} — {arrival_time}"`. Empty columns render an `(empty)` placeholder. Attach the `librechart_visit/station_strip` library (the CSS file already includes `.floor-board` rules from T008). Set cache tags `visit_list` + `patient_list`.

- [X] T023 [P] [US3] Functional test in `web/modules/custom/librechart_visit/tests/src/Functional/FloorViewTest.php`: programmatically create visits at multiple stations, log in as a clinical user, open `/floor`, assert each visit appears under the correct column and complete visits are absent. Assert empty columns render the `(empty)` placeholder.

- [X] T024 [US3] Manual smoke test per quickstart.md US3: open `/floor` after the backfill from T005; verify all 230 seeded visits are distributed across columns (or all in "Teaching" since most were complete — cross-check counts with `ddev drush sqlq "SELECT current_station, COUNT(*) FROM visit WHERE status != 'complete' GROUP BY current_station"`).

**Checkpoint**: US3 is independently shippable — supervisors have the room-level view.

---

## Phase 6: User Story 4 - Skip non-applicable PT station (Priority: P2)

**Goal**: When `pt_referral = false`, the "Send to next" button skips PT — going Clinical → Pharmacy directly. The PT skip rule was implemented in T013, this phase just adds dedicated tests + UI label correctness.

**Independent Test**: Per quickstart.md US4 — at Clinical with pt_referral=false, the button reads "Send to Pharmacy Dispensing"; with pt_referral=true, it reads "Send to Physical Therapy".

### Implementation tasks

- [X] T025 [P] [US4] Unit test in `web/modules/custom/librechart_visit/tests/src/Unit/StationWorkflowTest.php`: exercise `nextStation()` for all seven stations. Specifically: at `clinical` with `pt_referral = true` returns `pt`; at `clinical` with `pt_referral = false` returns `pharmacy`; at `pt` returns `pharmacy`; at `teaching` returns `null`.

- [X] T026 [P] [US4] Kernel test extension in `VisitTransitionTest`: assert the advance submit handler honors the PT skip; assert it does NOT skip when pt_referral is true.

- [X] T027 [US4] Manual smoke test per quickstart.md US4: as admin, set pt_referral=false on a Clinical visit, confirm button label reads "Send to Pharmacy Dispensing"; toggle pt_referral=true and confirm label reads "Send to Physical Therapy". Click each; confirm correct destination.

**Checkpoint**: US4 confirmed — conditional skip is exposed correctly in the linear shortcut UI.

---

## Phase 7: User Story 5 - Route a patient to any station out of order (Priority: P2)

**Goal**: Station owners (and admins) can use a "Send to specific station…" picker next to the linear shortcut to route the visit to any of the six non-current stations. Backward, forward, and multi-skip routes are all allowed.

**Independent Test**: Per quickstart.md US5 — open a visit at Clinical Evaluation with `pt_referral = true`, select "Pharmacy Dispensing" from the picker (bypassing PT), submit, then verify `current_station` is `pharmacy`, a revision is logged, and watchdog has a `notice` entry.

### Implementation tasks

- [X] T028 [US5] Add `canTransitionTo(ContentEntityInterface $visit, string $target, AccountInterface $user): bool` to `web/modules/custom/librechart_visit/src/Service/StationWorkflow.php` and its interface. Returns FALSE when: `$target` is not in `stations()`, `$target` equals the visit's current station, `$user` does not own the visit's current station AND lacks `administer visit entities`, or `$visit->status` equals `'complete'`. Returns TRUE otherwise. Pure validation — no entity save, no side effects.

- [X] T029 [P] [US5] Unit test for `canTransitionTo()` in `tests/src/Unit/StationWorkflowTest.php`: valid forward target (`triage` → `lab`); valid backward target (`pharmacy` → `triage`); valid multi-skip (`registration` → `clinical`); same-station target false; non-station target false; owner-role user true; non-owner-no-admin user false; admin user without owner role true; completed visit false.

- [X] T030 [US5] In `librechart_visit.module`, extend the form alter (T009/T015) to inject the picker element in `$form['actions']` immediately after the linear "Send to next station" button. Add a `select` element keyed `station_picker` with `#empty_option => '- Choose station -'`, `#options` = the six non-current stations (machine-name keys, `StationWorkflow::label()` values), `#title => 'Or send to a specific station'`. Add a Submit button next to it: `'#type' => 'submit'`, `'#value' => 'Send'`, `'#submit' => ['librechart_visit_visit_picker_submit']`, `'#limit_validation_errors' => [['station_picker']]`.

- [X] T031 [US5] Hide both the picker AND the linear button for non-owners and for completed visits. Reuse the existing `_librechart_visit_user_owns_current_station(Visit, AccountInterface): bool` helper (added in T015) so both controls share one access check. Admins should see a "Reopen visit" link on completed visits in place of the controls (preserve existing behavior).

- [X] T032 [US5] Write the picker submit handler `librechart_visit_visit_picker_submit(array &$form, FormStateInterface $form_state)` in `librechart_visit.module`. Steps per data-model.md: (1) load `$visit` from form object; (2) read `$target` from `$form_state->getValue('station_picker')`; (3) treat empty `$target` or same-as-current as a no-op; (4) call `StationWorkflow::canTransitionTo($visit, $target, $current_user)`; on FALSE, `setErrorByName('station_picker', t('You may not transition this visit from its current station.'))`, log `warning` watchdog entry, return; (5) compute prior + new labels via `StationWorkflow::label()`; (6) set `current_station = $target`; (7) set revision log message `"Transitioned from {prior} to {new} by {username}."`; (8) set revision user; (9) save; (10) status message + `notice` watchdog entry `picker transition: vid={vid} from={prior} to={target} uid={uid}`; (11) `setRedirect()` to the patient chart route.

- [X] T033 [P] [US5] Kernel test `tests/src/Kernel/VisitTransitionPickerKernelTest.php`: programmatically invoke the picker submit against a visit at Clinical with `pt_referral = false`; assert picking `pt` writes `current_station = 'pt'` (no PT skip applied by picker). Second case: pick `pharmacy` from `clinical` with `pt_referral = true`; assert PT is bypassed and `current_station = 'pharmacy'`. Third case: pick `teaching` via picker; assert `status` is NOT changed to `complete`.

- [X] T034 [P] [US5] Functional test `tests/src/Functional/VisitTransitionPickerTest.php`: log in as `clinician`, open a visit at `clinical`, fill the picker with `pharmacy`, submit Send, assert redirect + status message + DB state. Second scenario: log in as `pharmacist`, open a visit at `clinical`, assert neither the picker NOR the linear button appears in the HTML.

- [X] T035 [US5] Manual smoke test per quickstart.md US5: (a) open a visit form as `clinician`, verify picker visible with six options (Clinical Evaluation excluded); (b) select Pharmacy, click Send; (c) verify status message, redirect, DB state via `ddev drush sqlq`; (d) verify watchdog `notice` via `ddev drush watchdog:show --severity=notice | grep "picker transition"`; (e) attempt a tampered submission as `pharmacist` and verify rejection + warning entry.

**Checkpoint**: US5 confirmed — picker writes any-to-any transitions, revisions log them, tampered requests rejected, completed visits blocked.

---

## Phase 8: User Story 6 - Admin reopen of completed visits (Priority: P2)

**Goal**: Implement FR-021 — when a visit is complete (`status = complete`, `current_station = complete`), admins see a "Reopen visit" control in place of the transition controls. The control takes a target-station argument and, on submit, atomically writes `status = in_progress` AND `current_station = {target}` with a revision log entry.

**Independent Test**: As admin, open a completed visit. Verify the linear button and picker are hidden. Verify the reopen control is present with a target-station select containing the 7 workflow stations. Submit with a chosen target. Verify both fields are updated and the visit reappears on `/floor` under the chosen column.

### Implementation tasks

- [X] T042 [US6] In `web/modules/custom/librechart_visit/librechart_visit.module`, extend the form alter from T009/T015/T030 to inject the reopen control. Render the control only when ALL of: (a) visit's `status == 'complete'`, (b) current user has `administer visit entities` permission. Hide the linear button + picker in this case. Layout: a `select` element keyed `reopen_target` with `#empty_option => '- Choose station to reopen at -'` and `#options` = the 7 workflow stations (machine-name keys, `StationWorkflow::label()` values; the `complete` sentinel is NOT in the list); a Submit button labelled "Reopen visit" with `'#submit' => ['librechart_visit_visit_reopen_submit']`, `'#limit_validation_errors' => [['reopen_target']]`.

- [X] T043 [US6] Add `StationWorkflow::workflowStations(): array` that returns the 7 workflow stations only (excludes the `complete` sentinel). Used by the reopen control's option list and by `canTransitionTo()` defense-in-depth check. Distinct from `stations(): array` which returns all 8 allowed values including the sentinel.

- [X] T044 [US6] Write the reopen submit handler `librechart_visit_visit_reopen_submit(array &$form, FormStateInterface $form_state)` in `librechart_visit.module`. Steps: (1) load `$visit`; (2) read `$target` from `getValue('reopen_target')`; (3) validate `$target ∈ workflowStations()` AND current user has `administer visit entities`; on FALSE, set form error + watchdog warning + return; (4) write `status = 'in_progress'` AND `current_station = $target` on the entity; (5) set revision log message `"Reopened visit at {target_label} by {username}."`; (6) set revision user; (7) save; (8) status message `Visit reopened at {target_label}.`; (9) log `notice` watchdog entry `reopen: vid={vid} to={target} uid={uid}`; (10) `setRedirect()` to the patient chart route.

- [X] T045 [P] [US6] Kernel test `tests/src/Kernel/VisitReopenKernelTest.php`: create a completed visit (status=complete, current_station=complete); invoke the reopen submit handler with `target=triage`; assert `status = in_progress`, `current_station = triage`, and a new revision exists with the expected log message. Second case: invoke with `target=complete` (the sentinel); assert the handler rejects via form error AND no save occurs. Third case: invoke as a non-admin user; assert rejection with watchdog warning.

- [X] T046 [P] [US6] Functional test `tests/src/Functional/VisitReopenTest.php`: log in as admin, open a completed visit, assert the linear button and picker are NOT in the HTML; assert the reopen control IS present with 7 options. Fill `reopen_target = clinical`, submit Reopen, assert redirect + status message + DB state. Second scenario: log in as a non-admin clinical role; open the same completed visit; assert the reopen control is NOT in the HTML (only Save is shown).

- [X] T047 [US6] Manual smoke test per quickstart.md (will be added in T048): (a) advance a visit through Teaching to terminal (linear shortcut, terminal transition); verify `current_station = complete`; (b) as admin, open the visit; verify reopen control visible with 7 stations and no transition controls; (c) pick `clinical`, click Reopen; (d) verify `status = in_progress`, `current_station = clinical`, revision log entry, watchdog notice with `reopen:` prefix; (e) verify `/floor` shows the visit under Clinical.

- [X] T048 [US6] Update `specs/003-emr-station-transitions/quickstart.md` to add a "User Story 6: Admin reopen" walkthrough section mirroring the existing US sections (setup, expected, pass criteria, edge cases for non-admin and same-target rejection).

**Checkpoint**: US6 confirmed — completed visits can be reopened to a chosen workflow station; the `complete` sentinel is reachable only via the terminal transition and reopen-able only by admins.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Final hygiene, lint, config sync, manual UAT walkthrough.

- [X] T049 [P] Run `ddev exec phpcs --standard=Drupal web/modules/custom/librechart_visit/ web/modules/custom/librechart_patient/` and resolve any new violations.

- [X] T050 [P] Run `ddev exec phpstan analyse --level 6 web/modules/custom/librechart_visit/` and resolve new issues.

- [X] T051 Export config to capture the form-display change for `current_station`: `ddev drush config:export -y`. Inspect `config/sync/core.entity_form_display.visit.visit.default.yml` for the `current_station` widget entry. Move it to the `hidden` section so the dropdown doesn't show on the form (the station strip + advance button + picker + reopen control are the UX surface). Re-import: `ddev drush config:import -y`.

- [X] T052 Watchdog observability check: trigger 3 linear transitions, 3 picker transitions, 1 terminal transition (to complete), and 1 admin reopen. Run `ddev drush watchdog:show --count=20` and confirm: each linear transition has a revision log entry (no extra watchdog); each picker transition has BOTH a revision log entry AND a `notice` watchdog entry `picker transition: …`; the reopen has BOTH a revision entry (`"Reopened visit at …"`) AND a `notice` watchdog entry `reopen: vid=… to=… uid=…`. Recover revisions via `ddev drush sqlq "SELECT vid, revision_log_message FROM visit_revision WHERE revision_log_message LIKE 'Transitioned from%' OR revision_log_message LIKE 'Marked visit complete%' OR revision_log_message LIKE 'Reopened visit at%' ORDER BY revision_id DESC LIMIT 15"`.

- [X] T053 Verify the feature meets all Success Criteria from spec.md: SC-001 strip renders within first paint; SC-002 one-click advances the field; SC-003 `/floor` renders ≤1s for 300 visits; SC-004 PT skip never lands a visit at PT when `pt_referral=false` via the linear shortcut; SC-005 revision log gives full station history including terminal + reopen; SC-006 anonymous users blocked from `/floor`; SC-007 picker reachable in ≤2 clicks; SC-008 100% of picker transitions logged with prior+new+user; SC-009 floor view reflects picker writes on next load; SC-010 linear shortcut unchanged after adding picker. Also assert FR-021: completed visits show only the reopen control (no transition controls); reopen target list excludes the `complete` sentinel; reopen atomically writes both fields.

- [ ] T054 Final commit on branch `003-emr-station-transitions`. Message: "003-emr-station-transitions: add current_station field, station strip, advance action, floor view, picker, and admin reopen". Include all spec docs (spec.md, plan.md, research.md, data-model.md, contracts/, quickstart.md, tasks.md), the new module files, the install update hook, the backfill script, and the test files.

---

## Dependencies

```text
T001 (Setup: verify env)
  │
  ▼
T002 (entity base field) ─▶ T003 (update hook) ─▶ T004 (run update) ─▶ T005 (backfill)
                                                                          │
                                                                          ▼
                                                              T006 (StationWorkflow scaffold)
                                                                  │
                                       ┌──────────────────────────┼────────────────────────┐
                                       ▼                          ▼                        ▼
                                US1 phase (T007–T012)      US2 phase (T013–T019)    US3 phase (T020–T024)
                                                                  │                        │
                                                                  └─────────┬──────────────┘
                                                                            ▼
                                                                  US4 phase (T025–T027)
                                                                            │
                                                                            ▼
                                                                  US5 phase (T028–T035)
                                                                            │
                                                                            ▼
                                                                  US6 phase (T042–T048)
                                                                            │
                                                                            ▼
                                                                  Polish (T049–T054)
```

US1, US2, and US3 share a dependency on T006 (the StationWorkflow scaffold) but otherwise touch different files and can be pursued in parallel by separate developers. US4 must run after US2 because it relies on the advance submit handler and label-dynamic button text. US5 depends on US2 (linear button + owner-role helper) being in place because the picker reuses the same access guard and form alter location. US6 (admin reopen) depends on US2's terminal-transition flow being in place (it needs visits that can reach the `complete` state) and on the StationWorkflow scaffold (it needs `workflowStations()`).

## Parallel execution opportunities

- T007 (libraries.yml) and T008 (css) within US1 — independent files.
- T011 (functional test) parallel with T012 (manual) — different surfaces.
- T013 (nextStation) and T014 (ownerRole) — independent methods on the same file.
- T017 (kernel) and T018 (functional) within US2 — different test files.
- T020 (routing.yml) and T021 (links.menu.yml) within US3 — independent files.
- T025, T026 across US4 — different test files.
- T029 (unit), T033 (kernel), T034 (functional) within US5 — different test files; all three can run in parallel after T032 (picker submit handler) lands.
- T045 (kernel) and T046 (functional) within US6 — different test files; both runnable in parallel after T044 (reopen submit handler) lands.
- T049 and T050 within Polish — different tool runs.

## Implementation Strategy

**MVP scope**: Phase 1 + Phase 2 + Phase 3 (US1) = T001–T012. Ships the visual indicator alone — already a substantial UX improvement even without the transition action wired up.

**Incremental delivery**:
1. PR 1: T001–T005 (foundation only). Lands the schema change and backfills data.
2. PR 2: T006–T012 (US1). Adds the strip indicator. Lands the StationWorkflow scaffold.
3. PR 3: T013–T019 (US2). Adds the linear advance action.
4. PR 4: T020–T024 (US3). Adds the floor view.
5. PR 5: T025–T027 (US4). PT-skip tests + label correctness.
6. PR 6: T028–T035 (US5). Adds the picker for flexible transitions.
7. PR 7: T042–T048 (US6). Adds the admin reopen control for completed visits.
8. PR 8: T049–T054 (Polish). Lint, config sync, telemetry verification, final hygiene.

Or one PR for everything T001–T054 if the team prefers a single landing.

**Risk surface**:
- Schema update on a populated DB (T004) — if the entity definition update fails, the field won't install. Watch `ddev drush watchdog:show` during T004 for errors. The update hook in T003 is the canonical path for already-deployed sites.
- Backfill idempotency (T005) — confirm the script's `--revert` mode works correctly before relying on it in rollback.
- Form-display hidden state (T038) — if `current_station` accidentally appears in the visible form widgets, users could edit it via the dropdown bypassing both the linear shortcut AND the picker. Inspect the exported form display carefully.
- Picker submit must not chain to standard save (R13/T032) — otherwise unsaved sibling fields (e.g., half-filled vitals) get silently persisted when the picker is used. T033 kernel test should assert this explicitly.
- Linear vs. picker access guard parity (T031) — both controls must use the same `_librechart_visit_user_owns_current_station()` helper; divergence is a class of bug where one is rendered to the wrong user.
- Terminal transition + reopen are the ONLY paths in/out of the `complete` sentinel — if either flow is wrong, completed visits get stuck or never marked complete. T045 kernel test must cover both directions explicitly.
- `complete` sentinel exclusion from picker (T028/T030) — if the picker accidentally offers `complete` as a target, a non-admin user could mark a visit complete without going through Teaching. Defense-in-depth: `StationWorkflow::canTransitionTo()` already rejects it, but the picker UI must also exclude it.

## Format validation

All 54 tasks follow the checklist format: `- [ ] T### [P?] [Story?] Description with file path`. Phases 1, 2, 9 carry no `[Story]` label. Phases 3–8 carry `[US1]`/`[US2]`/`[US3]`/`[US4]`/`[US5]`/`[US6]` consistently. Every implementation task names exact file paths.
