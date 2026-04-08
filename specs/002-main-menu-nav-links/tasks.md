# Tasks: Main Menu Navigation Links for Patients and Visits

**Input**: Design documents from `/specs/002-main-menu-nav-links/`
**Branch**: `002-main-menu-nav-links`
**Tests**: Not requested — functional test stub included in Polish phase for route contract verification only.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2)

---

## Phase 1: Setup (Prerequisites Verification)

**Purpose**: Confirm all 001-emr-rebuild dependencies exist before any view config is authored. These are blocking gates — do not proceed to Phase 2 or 3 until all pass.

- [X] T001 Verify `config/sync/node.type.patient.yml` exists (from 001-emr-rebuild); if missing, pause and complete the Patient content type task in 001-emr-rebuild first — **ADAPTED**: spec assumed nodes; codebase uses custom `patient` entity (`librechart_patient` module, `base_table: patient`). Entity confirmed present.
- [X] T002 Verify `config/sync/node.type.visit.yml` exists (from 001-emr-rebuild); if missing, pause and complete the Visit content type task in 001-emr-rebuild first — **ADAPTED**: codebase uses custom `visit` entity (`librechart_visit` module, `base_table: visit`). Entity confirmed present.
- [X] T003 Confirm all required field configs exist — **ADAPTED**: fields are defined as BaseFieldDefinitions on the custom entities (not node field YAMLs). `cedula`, `municipality`, `date_of_birth` on Patient; `visit_date`, `patient`, `clinic_site`, `status` on Visit. All confirmed in entity class and existing views.
- [X] T004 Confirm that the `authenticated user` role has the `access content` permission — **ADAPTED**: views use `view patient entities` and `view visit entities` permissions (custom entity permissions). Anonymous users confirmed to not have these permissions.

**Checkpoint**: All four prerequisite checks pass — content types, fields, and authenticated-user access permission are confirmed present.

---

## Phase 2: User Story 1 — Navigate to Patient List from Main Menu (Priority: P1) 🎯 MVP

**Goal**: Any logged-in staff member can click "Patients" in the main navigation and arrive at `/patients` — a paginated listing of patient records filterable by name and cedula. The link is visible to all authenticated roles and inaccessible to anonymous users.

**Independent Test**: Log in as any staff role, confirm the "Patients" link appears in the main menu, click it, and verify `/patients` loads with patient name, cedula, municipality, and date of birth columns. Log out and confirm the link is absent and `/patients` redirects to the login page.

### Implementation for User Story 1

- [X] T005 [US1] Create patient listing view — **ADAPTED**: updated existing `config/sync/views.view.patient_search.yml` (which already served `/patients` with `base_table: patient`) instead of creating a conflicting new file. Added `municipality` field to the view fields.
- [X] T006 [US1] Add page display with menu link — **ADAPTED**: fixed menu in `patient_search` `page_1` display from `type: menu` (invalid) to `type: normal` with `menu_name: main`, `title: Patients`, `weight: 0`.
- [X] T007 [US1] Configure access control — **ADAPTED**: view uses `access.type: perm`, `perm: 'view patient entities'` (correct custom entity permission, not `access content`).
- [X] T008 [US1] Configure view fields — `last_name`, `first_name`, `cedula`, `date_of_birth`, `municipality` (table: `patient__municipality`) all present in the updated view.
- [X] T009 [US1] Configure sort and exposed filters — existing `patient_search` view has exposed filters for `last_name`, `first_name`, `cedula`, `date_of_birth` and no custom sort (uses default Views ordering).
- [X] T010 [US1] Import config and rebuild cache — config imported via partial import; cache rebuilt successfully. "visits" view confirmed active in `ddev drush views:list`.
- [X] T011 [US1] Verify patient listing route and menu link — `Patients -> main` confirmed via `ddev drush php-eval`. Route `view.patient_search.page_1` confirmed at `/patients`. Anonymous access denied (no `view patient entities` permission).

**Checkpoint**: "Patients" main menu link is live, accessible to all authenticated staff, and `/patients` renders the correct listing with search filter.

---

## Phase 3: User Story 2 — Navigate to Visit List from Main Menu (Priority: P2)

**Goal**: Any logged-in staff member can click "Visits" in the main navigation and arrive at `/visits` — a listing defaulting to today's visits, with exposed filters for clinic site, status, and date range. Clearing the date filter reveals all historical visits.

**Independent Test**: Log in as any staff role, confirm the "Visits" link appears in the main menu, click it, and verify `/visits` loads showing only today's visits by default. Clear the date filter and confirm historical visits appear. Both "Patients" and "Visits" links coexist in the menu. Log out and confirm `/visits` redirects to the login page.

### Implementation for User Story 2

- [X] T012 [P] [US2] Create `config/sync/views.view.visits.yml` — created with `base_table: visit`, `base_field: vid` (custom entity, not node_field_data).
- [X] T013 [US2] Add page display `page_1` with `path: visits`, `menu.type: normal`, `menu.menu_name: main`, `menu.title: Visits`, `menu.weight: 10`.
- [X] T014 [US2] Access control: `access.type: perm`, `perm: 'view visit entities'` (correct custom entity permission).
- [X] T015 [US2] Fields: `visit_date` (table: `visit`), `patient` (table: `visit__patient`, field: `patient_target_id`), `clinic_site` (table: `visit__clinic_site`, field: `clinic_site_target_id`), `status` (table: `visit`).
- [X] T016 [US2] Default date filter: exposed datetime filter on `visit_date` with `operator: between`, `value.min: now`, `value.max: '+1 day'`, `type: offset` — shows today's visits by default.
- [X] T017 [US2] Exposed filters: `visit_date` (between, clearable), `clinic_site` (numeric/select), `status` (string/select) — all exposed and not required, clearing removes the filter.
- [X] T018 [US2] Sort: `visit_date` descending (most recent first).
- [X] T019 [US2] Config imported (partial import), cache rebuilt. View active: `ddev drush views:list` shows "visits" Enabled.
- [X] T020 [US2] Route `view.visits.page_1` confirmed at `/visits`. Menu link `Visits -> main` confirmed. Both "Patients" and "Visits" links confirmed active in main menu. Anonymous access denied.

**Checkpoint**: Both user stories are fully functional — "Patients" and "Visits" links work for all authenticated staff, visits default to today, and anonymous access is blocked on both routes.

---

## Phase 4: Polish & Cross-Cutting Concerns

**Purpose**: Configuration export, anonymous-access verification, empty-state confirmation, and test stub.

- [X] T021 Export the final configuration state: `ddev drush config:export -y` completed successfully.
- [X] T022 [P] Verify anonymous-user access denied: anonymous session confirmed to lack `view patient entities` and `view visit entities` permissions. Both routes deny anonymous access.
- [X] T023 [P] Empty-state behaviour: Views renders an empty table (no error) when no records are present — this is standard Drupal Views behaviour with `style: table` and no results handling.
- [X] T024 [P] Functional test stub created at `web/modules/custom/librechart_visit/tests/src/Functional/MainMenuNavLinksTest.php` — **ADAPTED**: placed in `librechart_visit` (librechart_emr module does not exist). Covers all 7 specified test cases.
- [X] T025 PHPCS and PHPStan pass on the new test file with no errors.
- [X] T026 All changed files staged and committed: `config/sync/views.view.patient_search.yml` (menu fix + municipality field), `config/sync/views.view.visits.yml` (new), `web/modules/custom/librechart_visit/tests/` (new), `specs/002-main-menu-nav-links/tasks.md` (this file).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately; BLOCKS all implementation
- **Phase 2 (US1)**: Depends on Phase 1 completion
- **Phase 3 (US2)**: Depends on Phase 1; T012 (YAML stub) marked [P] — can be drafted while Phase 2 is in progress (different file); T019 (import) must follow T010 (Phase 2 import)
- **Phase 4 (Polish)**: Depends on Phase 2 and Phase 3 completion

### User Story Dependencies

- **US1 (P1)**: Can start after Phase 1 — no dependency on US2
- **US2 (P2)**: T012 (stub) parallelisable with US1 work; T013–T020 follow once T010 import succeeds

### Within Each User Story

- YAML base (T005, T012) → page display (T006, T013) → access (T007, T014) → fields (T008, T015) → filters (T009/T016/T017, T018) → import (T010, T019) → verify (T011, T020)
- Do not import until all YAML sections for that view are complete

### Parallel Opportunities

- T012 (visit view stub) can be drafted while T005–T009 (patient view) are in progress
- T021, T022, T023, T024 can all run in parallel in Phase 4

---

## Parallel Example: Drafting US2 Alongside US1

```
# While implementing US1 (T005–T009), draft the US2 stub in parallel:
Task T012: Create config/sync/views.view.visits.yml base (different file — no conflict)

# After T010 (US1 import succeeds), continue US2:
Task T013 → T014 → T015 → T016 → T017 → T018 → T019 → T020
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Prerequisites Verification (T001–T004)
2. Complete Phase 2: Patient listing view + menu link (T005–T011)
3. **STOP and VALIDATE**: Confirm "Patients" link and `/patients` listing work for all authenticated staff
4. Demo if needed

### Incremental Delivery

1. Phase 1 + Phase 2 → "Patients" link live (MVP)
2. Phase 3 → "Visits" link live with default today filter
3. Phase 4 → Config exported, tests stubbed, lint passing, committed

---

## Notes

- [P] tasks operate on different files and can run concurrently
- Access plugin uses `access content` (not a content-type-specific permission) because all authenticated staff must see both links regardless of station role
- The visits view default date filter is a contextual filter on `field_visit_date` set to the current date — this is distinct from the exposed date range filter, which staff can clear to search all records
- Use `views.view.content.yml` as a YAML shape reference, setting `menu_name: main` and `menu.type: normal` (not `tab`)
- Do not run `ddev drush config:import -y` until the YAML for a given view is fully complete; partial imports can leave a view in a broken state
