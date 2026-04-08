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

- [ ] T001 Verify `config/sync/node.type.patient.yml` exists (from 001-emr-rebuild); if missing, pause and complete the Patient content type task in 001-emr-rebuild first
- [ ] T002 Verify `config/sync/node.type.visit.yml` exists (from 001-emr-rebuild); if missing, pause and complete the Visit content type task in 001-emr-rebuild first
- [ ] T003 Confirm all required field configs exist in `config/sync/`: `field.field.node.patient.field_cedula`, `field.field.node.patient.field_municipality`, `field.field.node.patient.field_date_of_birth`, `field.field.node.visit.field_visit_date`, `field.field.node.visit.field_patient`, `field.field.node.visit.field_clinic_site`, `field.field.node.visit.field_visit_status`
- [ ] T004 Confirm that the `authenticated user` role has the `access content` permission in `config/sync/user.role.authenticated.yml` (this is the Drupal core default; verify it has not been revoked)

**Checkpoint**: All four prerequisite checks pass — content types, fields, and authenticated-user access permission are confirmed present.

---

## Phase 2: User Story 1 — Navigate to Patient List from Main Menu (Priority: P1) 🎯 MVP

**Goal**: Any logged-in staff member can click "Patients" in the main navigation and arrive at `/patients` — a paginated listing of patient records filterable by name and cedula. The link is visible to all authenticated roles and inaccessible to anonymous users.

**Independent Test**: Log in as any staff role, confirm the "Patients" link appears in the main menu, click it, and verify `/patients` loads with patient name, cedula, municipality, and date of birth columns. Log out and confirm the link is absent and `/patients` redirects to the login page.

### Implementation for User Story 1

- [ ] T005 [US1] Create `config/sync/views.view.patients.yml` — define the view base: `id: patients`, `label: Patients`, `base_table: node_field_data`, `base_field: nid`, with a default display configuring a filter for `type = patient`
- [ ] T006 [US1] Add the page display (`patients_page`) to `config/sync/views.view.patients.yml` with `path: /patients`, `menu.type: normal`, `menu.menu_name: main`, `menu.title: Patients`, `menu.weight: 0`, `menu.parent: ''`
- [ ] T007 [US1] Configure access control on the `patients_page` display in `config/sync/views.view.patients.yml`: `access.type: perm`, `access.options.perm: 'access content'` (grants access to all authenticated users, denies anonymous users)
- [ ] T008 [US1] Configure view fields on the `patients_page` display in `config/sync/views.view.patients.yml`: `title` (rendered as link to node, label "Patient Name"), `field_cedula` (plain text, label "Cedula"), `field_municipality` (plain text, label "Municipality"), `field_date_of_birth` (formatted date, label "Date of Birth")
- [ ] T009 [US1] Configure sort and exposed filter on the `patients_page` display in `config/sync/views.view.patients.yml`: default sort by `title` ascending; exposed filter combining `title` (contains) and `field_cedula` (contains) in a single search block
- [ ] T010 [US1] Import the new view config and rebuild cache: `ddev drush config:import -y && ddev drush cache:rebuild`
- [ ] T011 [US1] Verify the patient listing route and menu link: run `ddev drush views:list | grep patients` and confirm "Patients" appears in the main menu in the browser for a logged-in user but not for an anonymous (logged-out) user

**Checkpoint**: "Patients" main menu link is live, accessible to all authenticated staff, and `/patients` renders the correct listing with search filter.

---

## Phase 3: User Story 2 — Navigate to Visit List from Main Menu (Priority: P2)

**Goal**: Any logged-in staff member can click "Visits" in the main navigation and arrive at `/visits` — a listing defaulting to today's visits, with exposed filters for clinic site, status, and date range. Clearing the date filter reveals all historical visits.

**Independent Test**: Log in as any staff role, confirm the "Visits" link appears in the main menu, click it, and verify `/visits` loads showing only today's visits by default. Clear the date filter and confirm historical visits appear. Both "Patients" and "Visits" links coexist in the menu. Log out and confirm `/visits` redirects to the login page.

### Implementation for User Story 2

- [ ] T012 [P] [US2] Create `config/sync/views.view.visits.yml` — define the view base: `id: visits`, `label: Visits`, `base_table: node_field_data`, `base_field: nid`, with a default display configuring a filter for `type = visit`
- [ ] T013 [US2] Add the page display (`visits_page`) to `config/sync/views.view.visits.yml` with `path: /visits`, `menu.type: normal`, `menu.menu_name: main`, `menu.title: Visits`, `menu.weight: 10`, `menu.parent: ''`
- [ ] T014 [US2] Configure access control on the `visits_page` display in `config/sync/views.view.visits.yml`: `access.type: perm`, `access.options.perm: 'access content'`
- [ ] T015 [US2] Configure view fields on the `visits_page` display in `config/sync/views.view.visits.yml`: `field_visit_date` (formatted date, label "Date"), `field_patient` (entity reference rendered as link to patient node, label "Patient"), `field_clinic_site` (entity reference label, label "Clinic Site"), `field_visit_status` (plain text, label "Status")
- [ ] T016 [US2] Configure default contextual filter on `field_visit_date` in `config/sync/views.view.visits.yml`: filter value = current date (PHP `date('Y-m-d')` equivalent in Views date filter plugin), applied automatically on page load so the listing shows only today's visits by default
- [ ] T017 [US2] Configure exposed filters on the `visits_page` display in `config/sync/views.view.visits.yml`: `field_clinic_site` (select, label "Clinic Site"), `field_visit_status` (select, label "Status"), `field_visit_date` (date range, label "Date") — the date range filter must be clearable so staff can remove the default date restriction to view all historical visits
- [ ] T018 [US2] Configure sort on the `visits_page` display in `config/sync/views.view.visits.yml`: default sort by `field_visit_date` descending (most recent first)
- [ ] T019 [US2] Import the updated config and rebuild cache: `ddev drush config:import -y && ddev drush cache:rebuild`
- [ ] T020 [US2] Verify the visit listing route, default date filter, and menu link: confirm `/visits` loads showing only today's visits; confirm clearing the date filter shows all visits; confirm both "Patients" and "Visits" links appear together in the main menu for a logged-in user

**Checkpoint**: Both user stories are fully functional — "Patients" and "Visits" links work for all authenticated staff, visits default to today, and anonymous access is blocked on both routes.

---

## Phase 4: Polish & Cross-Cutting Concerns

**Purpose**: Configuration export, anonymous-access verification, empty-state confirmation, and test stub.

- [ ] T021 Export the final configuration state to capture both new view files: `ddev drush config:export -y`
- [ ] T022 [P] Verify anonymous-user redirect: with Drupal's default anonymous role having no `access content` permission, confirm that visiting `/patients` and `/visits` while logged out redirects to `/user/login` (or returns 403) — validate this matches the behaviour specified in FR-004
- [ ] T023 [P] Verify empty-state behaviour: with no patient or visit records present, confirm `/patients` and `/visits` each render an empty-state message rather than an error, and that the menu links remain visible
- [ ] T024 [P] Create functional test stub `web/modules/custom/librechart_emr/tests/src/Functional/MainMenuNavLinksTest.php` with test cases: (a) `/patients` returns 200 for any authenticated staff user, (b) `/patients` is inaccessible for anonymous user, (c) `/visits` returns 200 for any authenticated staff user, (d) `/visits` is inaccessible for anonymous user, (e) main menu contains "Patients" link for authenticated user, (f) main menu contains "Visits" link for authenticated user, (g) `/visits` defaults to showing only today's visits
- [ ] T025 Run lint and static analysis to confirm no regressions: `ddev exec phpcs && ddev exec phpstan`
- [ ] T026 Stage and commit the two new view config files: `git add config/sync/views.view.patients.yml config/sync/views.view.visits.yml`

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
