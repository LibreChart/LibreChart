---
description: "Task list for Pharmacy Section Refinement"
---

# Tasks: Pharmacy Section Refinement

**Input**: Design documents from `/specs/005-pharmacy-refinement/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Not requested in the spec. Manual verification follows `quickstart.md`. One OPTIONAL
inventory-invariant kernel test is included in US2 because the inventory math is safety-critical and
has explicit testable invariants (contract `inventory-adjustment.md`, SC-003/SC-004).

**Organization**: Tasks are grouped by user story. Stories map to spec.md (US1…US8).

## Conventions for this Drupal project

- Custom code lives in `web/modules/custom/librechart_visit` and
  `web/modules/custom/librechart_pharmacy`; theme in `web/themes/custom/laughh`.
- Schema changes (base fields, allowed values, field-type change) ship as update hooks using the
  `EntityDefinitionUpdateManager`, per the `current_station` precedent (feature 003).
- After any config-affecting change: update module `config/install` where applicable AND active
  config, then `ddev drush config:export -y` (captured once in Polish, but export as you go).
- `[P]` = parallelizable (different files, no incomplete dependency). Tasks editing the **same**
  shared file (`Visit.php`, `librechart_visit.module`, `librechart_pharmacy.module`,
  `core.entity_form_display.visit.visit.default.yml`) are NOT marked `[P]`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Add the one new dependency the embedding model requires.

- [X] T001 Add the embedding dependency: `ddev composer require drupal/inline_entity_form` (D11
  release), `ddev drush en inline_entity_form -y`, and confirm `inline_entity_form` is present in
  `config/sync/core.extension.yml`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Install the shared Visit base fields that US1, US4, and US7 build on. Editing
`Visit.php` and `librechart_visit.install` here once avoids cross-story conflicts on these shared
files.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Add four new base fields to `web/modules/custom/librechart_visit/src/Entity/Visit.php`:
  `medications` (entity_reference → `prescription_item`, unlimited), `education_referrals`
  (entity_reference_revisions → paragraph, unlimited, target bundle `education_referral`), `pos`
  (boolean, default FALSE, label "POS"), `pos_details` (string_long). Mirror the existing
  `lab_results` paragraph field pattern for `education_referrals`.
- [X] T003 Add an update hook in `web/modules/custom/librechart_visit/librechart_visit.install`
  that installs the four field storage definitions from T002 via
  `EntityDefinitionUpdateManager::installFieldStorageDefinition()`.

**Checkpoint**: New Visit fields exist in code/schema; user stories can proceed.

---

## Phase 3: User Story 1 - Clinician prescribes medications from live inventory (Priority: P1) 🎯 MVP

**Goal**: A stock-aware medication picker in the Pharmacy group: ≤10 typeahead matches with
yellow/red stock coloring, out-of-stock not selectable, a quantity per medication, add/remove
multiple medication fieldsets, and a "View inventory" link opening the report in a new tab.

**Independent Test**: Edit a visit → Pharmacy → type in Medication (≤10 results, low=yellow,
out=red & non-selectable), set Quantity, "Add another" creates an independent fieldset, "View
inventory" opens `/reports/inventory` in a new tab.

### Implementation for User Story 1

- [X] T004 [US1] Add the autocomplete route `librechart_pharmacy.medication_autocomplete`
  (`/pharmacy/autocomplete/medications`, GET) in
  `web/modules/custom/librechart_pharmacy/librechart_pharmacy.routing.yml` per
  `contracts/medication-autocomplete.md`.
- [X] T005 [US1] Create `MedicationAutocompleteController` in
  `web/modules/custom/librechart_pharmacy/src/Controller/MedicationAutocompleteController.php`:
  query `DrugInventory`, CONTAINS-match the drug term name against `q`, return ≤10 JSON items with
  `{value,label,tid,status,qty,selectable}` and stock status per FR-028.
- [X] T006 [P] [US1] Create the stock-aware drug widget `MedicationAutocompleteWidget` in
  `web/modules/custom/librechart_pharmacy/src/Plugin/Field/FieldWidget/MedicationAutocompleteWidget.php`
  (wires the `drug` field to the autocomplete route; stores selected `tid`).
- [X] T007 [P] [US1] Add `web/modules/custom/librechart_pharmacy/js/medication-autocomplete.js`:
  extend Drupal core autocomplete to color rows (`.medication--low-stock` / `.medication--out-of-stock`)
  and block selection of `selectable:false` rows (FR-002/003); self-hosted only.
- [X] T008 [P] [US1] Add picker stock colors (`.medication--low-stock` yellow,
  `.medication--out-of-stock` red) to `web/modules/custom/librechart_pharmacy/css/pharmacy.css`, and
  register a library (e.g. `medication_autocomplete`) in
  `web/modules/custom/librechart_pharmacy/librechart_pharmacy.libraries.yml` attaching the JS + CSS.
- [X] T009 [US1] In `core.entity_form_display.visit.visit.default.yml` (module `config/install` +
  `config/sync`): in `group_pharmacy`, add `medications` with the
  `inline_entity_form_complex` widget; set the line-item `drug` widget to the medication autocomplete
  and show `quantity_dispensed` ("Quantity"). Configure IEF to allow add/remove (FR-005/006/007).
- [X] T010 [US1] In `web/modules/custom/librechart_visit/librechart_visit.module` `hook_form_alter`,
  inject a "View inventory" link into `group_pharmacy` (`target="_blank"` → `/reports/inventory`,
  `rel="noopener"`) per FR-004, and attach the `medication_autocomplete` library.
- [X] T011 [US1] In `web/modules/custom/librechart_pharmacy/librechart_pharmacy.module`, set each IEF
  child `prescription_item.visit` to the parent visit on save (presave/submit), so inventory reports
  (`InventoryReportController`, `views.view.visit_prescriptions`) keep working.
- [X] T012 [US1] Export config (`ddev drush config:export -y`) and run the US1 portion of
  `quickstart.md` §4.

**Checkpoint**: Prescribing works end-to-end (picker, quantity, multi-item, view-inventory) —
independent MVP.

---

## Phase 4: User Story 2 - Pharmacist fulfills medications and inventory adjusts (Priority: P1)

**Goal**: Per-medication "Fulfilled" (pharmacist-only) + "Fulfilled by" (auto-filled pharmacist
taxonomy, editable); checking decrements inventory, unchecking restores it; insufficient stock is
blocked with a remaining-quantity error.

**Independent Test**: As a Pharmacist, fulfill a med (qty 5) → that drug's `quantity_on_hand` drops
5; uncheck → restored; over-fulfill → blocked with remaining-qty message; "Fulfilled by" defaults to
your matching term; non-pharmacists can't edit these fields.

**Depends on**: US1 (the `medications` IEF field/subform must exist).

### Implementation for User Story 2

- [X] T013 [P] [US2] Create the Pharmacist vocabulary config
  `web/modules/custom/librechart_pharmacy/config/install/taxonomy.vocabulary.pharmacist.yml`
  (vid `pharmacist`, English label) per data-model §5.
- [X] T014 [US2] Add `fulfilled_by` base field (entity_reference → taxonomy `pharmacist`) to
  `web/modules/custom/librechart_pharmacy/src/Entity/PrescriptionItem.php` with a default-value
  callback resolving the `pharmacist` term matching the logged-in pharmacist (editable; blank if no
  match) per D4/Clarification Q3.
- [X] T015 [US2] Create/extend `web/modules/custom/librechart_pharmacy/librechart_pharmacy.install`
  with an update hook installing the `fulfilled_by` field storage.
- [X] T016 [US2] Rewrite inventory adjustment in
  `web/modules/custom/librechart_pharmacy/librechart_pharmacy.module` to be delta-based, reversible,
  and idempotent, matching `DrugInventory` by `drug` only (single pool): `presave` re-check,
  `postsave` apply `delta = effective(new) − effective(original)`, `predelete` restore. Remove the
  legacy `override_reason` decrement/floor-with-warning path. Implements
  `contracts/inventory-adjustment.md` (FR-010/011/012, SC-003/004).
- [X] T017 [P] [US2] Add the insufficient-stock validation constraint + validator in
  `web/modules/custom/librechart_pharmacy/src/Plugin/Validation/Constraint/` (e.g.
  `SufficientStock` + `SufficientStockValidator`): block `prescription_filled = TRUE` when the
  required additional units exceed available stock; violation message states the remaining quantity
  (FR-013, VR-1). Attach the constraint to `prescription_item`.
- [X] T018 [US2] Add `hook_entity_field_access()` in `librechart_pharmacy.module` restricting edit of
  `prescription_filled` and `fulfilled_by` to users with the `pharmacist` role, independent of
  station (D5/VR-3, Clarification Q1).
- [X] T019 [US2] Update the medication line-item form (IEF subform) in
  `core.entity_form_display.visit.visit.default.yml` (and/or the `prescription_item` form display) to
  surface `prescription_filled` ("Fulfilled") and `fulfilled_by` ("Fulfilled by"), per
  `contracts/medication-line-item-form.md`.
- [~] T020 [P] [US2] (OPTIONAL) Add a kernel test
  `web/modules/custom/librechart_pharmacy/tests/src/Kernel/InventoryAdjustmentTest.php` asserting
  invariants I-1..I-4 (no negative stock, toggle returns to start, idempotent re-save, no drift).
- [X] T021 [US2] Import/export config (`config:import --partial` for the new vocab, then
  `config:export -y`) and run US2 of `quickstart.md` §4.

**Checkpoint**: Fulfillment + reversible inventory + block-on-insufficient-stock all work; US1+US2
deliver the full pharmacy P1 workflow.

---

## Phase 5: User Story 3 - Allergies required, default "None", always-visible sticky pills (Priority: P1)

**Goal**: Allergy field required and defaulting to "None"; recorded allergies (except "None") shown
as red pills stickied at the bottom of the visit page, updating as allergies are added/removed.

**Independent Test**: New visit → Allergies required, defaults to "None" (no pill); add allergies →
red sticky pills appear and persist while scrolling; remove one → its pill disappears.

### Implementation for User Story 3

- [X] T022 [US3] In `web/modules/custom/librechart_visit/src/Entity/Visit.php`, make `allergies`
  required and add a default-value callback resolving the `allergies` term "None" (FR-017).
- [X] T023 [US3] Add an update hook in `librechart_visit.install` to seed the "None" allergy term if
  absent (used by the default-value callback).
- [X] T024 [P] [US3] Add `web/modules/custom/librechart_visit/js/allergy-sticky.js`: render a
  fixed-position red-pill bar from the `allergies` `autocomplete_tags` widget, excluding label
  "None", refreshing on add/remove (FR-018/019/020) per
  `contracts/allergy-sticky-and-report-coloring.md` §A.
- [X] T025 [P] [US3] Add `web/modules/custom/librechart_visit/css/allergy-sticky.css`
  (`.lc-allergy-sticky` fixed bottom bar, `.lc-allergy-pill` red), and register an `allergy_sticky`
  library in `web/modules/custom/librechart_visit/librechart_visit.libraries.yml`.
- [X] T026 [US3] In `librechart_visit.module` `hook_form_alter`, attach the `allergy_sticky` library
  on `visit_add_form`/`visit_edit_form`.
- [X] T027 [US3] Export config and run US3 of `quickstart.md` §4.

**Checkpoint**: Allergies are required, defaulted, and always visible as red pills.

---

## Phase 6: User Story 4 - Education station rename + education-class referrals (Priority: P2)

**Goal**: Rename the `teaching` station to `education` (keep its existing Teaching & Referrals
content), migrate existing data, and add per-class education referrals with a Complete checkbox.

**Independent Test**: Former "Teaching" station now reads "Education" and retains its fields; existing
`teaching` visits now show `education`; add Education referrals each with an independent "Complete"
checkbox.

**Depends on**: Foundational (the `education_referrals` field exists).

### Implementation for User Story 4

- [X] T028 [US4] In `web/modules/custom/librechart_visit/src/Entity/Visit.php`, change the
  `current_station` allowed value key `teaching` → `education` (label "Education & Referrals").
- [X] T029 [US4] In `web/modules/custom/librechart_visit/src/Service/StationWorkflow.php`, rename
  `teaching` → `education` in `WORKFLOW_STATIONS`, `label()`, `ownerRole()` (return stays
  `teaching_coordinator`), and `nextStation()` (`pharmacy → education`, `education → NULL`).
- [X] T030 [US4] In `web/modules/custom/librechart_visit/librechart_visit.module`, update the
  station→field-group map (`teaching → group_teaching` becomes `education → group_education`) and any
  other `'teaching'` station reference (≈ line 635).
- [X] T031 [US4] Add an update hook in `librechart_visit.install` to update the `current_station`
  allowed values and data-migrate rows with value `teaching` → `education` in both the base and
  revision tables (per data-model §7).
- [X] T032 [P] [US4] Create the Education vocabulary config
  `web/modules/custom/librechart_visit/config/install/taxonomy.vocabulary.education.yml` (vid
  `education`, English label).
- [X] T033 [P] [US4] Create the `education_referral` paragraph type config (paragraph type +
  `field_education` ref→`education` and `field_complete` boolean + form/view displays) under
  `web/modules/custom/librechart_visit/config/install/` (mirror the `lab_result` paragraph).
- [X] T034 [US4] In `core.entity_form_display.visit.visit.default.yml`, rename `group_teaching` →
  `group_education` (label/classes/children retained) and add `education_referrals` (paragraphs
  widget) to that group.
- [X] T035 [US4] Import/export config and run US4 of `quickstart.md` §4 (including the data-migration
  spot check in §6).

**Checkpoint**: Education station + referrals work; existing data migrated.

---

## Phase 7: User Story 5 - Pharmacy section cleanup & notes simplification (Priority: P2)

**Goal**: Remove the standalone "Pharmacist name" field; make "Notes to pharmacist" a plain text area
(no WYSIWYG).

**Independent Test**: Pharmacy group has no "Pharmacist name" field; "Notes to pharmacist" is a plain
textarea with no rich-text toolbar.

**Depends on**: US1 (both edit `group_pharmacy` in the same form display — sequence after US1).

### Implementation for User Story 5

- [X] T036 [US5] Remove the `pharmacist_name` base field from
  `web/modules/custom/librechart_visit/src/Entity/Visit.php` and convert `notes_to_pharmacist` from
  `text_long` to `string_long` with a `string_textarea` widget (D7/FR-015/016).
- [X] T037 [US5] Add update hooks in `librechart_visit.install` to (a) uninstall `pharmacist_name`
  field storage and (b) convert `notes_to_pharmacist` (create `string_long` column, copy existing
  `.value`, swap) without data loss.
- [X] T038 [US5] In `core.entity_form_display.visit.visit.default.yml`, remove `pharmacist_name` from
  `group_pharmacy` children and set `notes_to_pharmacist` widget to `string_textarea`.
- [X] T039 [US5] Export config and run US5 of `quickstart.md` §4 (back up DB first per §6).

**Checkpoint**: Pharmacy section cleaned up; notes is plain text.

---

## Phase 8: User Story 6 - Inventory report color coding (Priority: P2)

**Goal**: Low-stock rows yellow, out-of-stock rows red in the inventory report, consistent with the
picker (FR-028).

**Independent Test**: `/reports/inventory` shows low-stock rows yellow and out-of-stock rows red.

**Depends on**: shares `pharmacy.css` with US1 — sequence after US1 (or coordinate the file edit).

### Implementation for User Story 6

- [X] T040 [US6] Extend `librechart_pharmacy_preprocess_views_view_table()` in
  `web/modules/custom/librechart_pharmacy/librechart_pharmacy.module` to add
  `inventory-row--out-of-stock` (`qty == 0`) and `inventory-row--low-stock`
  (`0 < qty <= low_stock_threshold`), and attach the pharmacy library to the report.
- [X] T041 [US6] Set report row colors in
  `web/modules/custom/librechart_pharmacy/css/pharmacy.css` (low=yellow, out=red) per
  `contracts/allergy-sticky-and-report-coloring.md` §B.
- [X] T042 [P] [US6] Reconcile the conflicting light-pink `.inventory-row--low-stock` rule in
  `web/themes/custom/laughh/css/style.css` to yellow (or remove in favor of the module rule) so
  picker/report colors match.
- [X] T043 [US6] Clear cache, export config if any view config changed, and run US6 of
  `quickstart.md` §4.

**Checkpoint**: Report color coding matches the picker.

---

## Phase 9: User Story 7 - POS (local-provider referral) conditional field (Priority: P3)

**Goal**: A "POS" checkbox in the Education group that reveals a conditional text area for the
local-provider referral.

**Independent Test**: In the Education group, checking POS reveals the text area; unchecking hides it.

**Depends on**: Foundational (`pos`/`pos_details` fields) and US4 (`group_education` exists).

### Implementation for User Story 7

- [X] T044 [US7] In `core.entity_form_display.visit.visit.default.yml`, place `pos` and `pos_details`
  in `group_education`, set `pos_details` widget to `string_textarea`, and add `conditional_fields`
  third-party settings making `pos_details` visible when `pos` is checked — mirroring the existing
  `pt_referral → pt_notes` config (the boolean `#on_value` shim already handles checkbox dependees).
- [X] T045 [US7] Export config and run US7 of `quickstart.md` §4.

**Checkpoint**: POS show/hide works.

---

## Phase 10: User Story 8 - Autosave the Visit form (Priority: P3) — DEFERRED

**Goal**: In-progress Visit edits are retained/restored without manual save.

**Independent Test**: Edit a visit, navigate away without saving, return → entries restored.

> **DEFERRED (2026-05-31):** `autosave_form` activates only on standard entity-form
> routes (`_entity_form`) and explicitly does not support embedded forms. Visits are
> edited through the patient chart (`entity.patient.edit_form`, a custom controller that
> embeds the visit form and removes `_entity_form`), so `autosave_form` cannot fire there.
> Adding `visit` to the allowlist only enables autosave on the unused standalone
> `/visit/{id}/edit` route. Per the user's decision, the allowlist change was reverted and
> US8 is deferred pending a custom approach (bypass the route gate, or a bespoke autosave).

### Implementation for User Story 8

- [~] T046 [US8] Add the `visit` entity type to `allowed_content_entity_types` in
  `config/sync/autosave_form.settings.yml` (module already enabled) per D11/FR-027.
- [~] T047 [US8] Import/export config, then verify per `quickstart.md` §4 — exercising the
  medication (IEF) and education (paragraphs) subforms; if nested restore misbehaves, apply the D11
  risk mitigation (`only_on_form_change`) and re-verify.

**Checkpoint**: Visit drafts autosave/restore.

---

## Phase 11: Polish & Cross-Cutting Concerns

**Purpose**: Quality gates and final validation across all stories.

- [ ] T048 [P] Run `ddev exec phpcs --standard=Drupal web/modules/custom/librechart_visit web/modules/custom/librechart_pharmacy` and fix violations.
- [ ] T049 [P] Run `ddev exec phpstan analyse --level 6 web/modules/custom/librechart_visit web/modules/custom/librechart_pharmacy` and fix issues.
- [ ] T050 Run `ddev drush config:export --diff` and confirm active config matches `config/sync` (no
  stray/missing config); commit the exported config.
- [ ] T051 Execute the full `quickstart.md` §4 verification pass and §5 quality gates end-to-end on a
  clean `updatedb` + `config:import`.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (P1)** → no deps.
- **Foundational (P2)** → after Setup; **blocks** US1, US4, US7 (shared Visit fields).
- **US1 (P1)** → after Foundational.
- **US2 (P1)** → after **US1** (needs the `medications` IEF subform).
- **US3 (P1)** → after Foundational; independent of US1/US2.
- **US4 (P2)** → after Foundational.
- **US5 (P2)** → after **US1** (shares `group_pharmacy` in the form display).
- **US6 (P2)** → after **US1** (shares `pharmacy.css`).
- **US7 (P3)** → after Foundational and **US4** (needs `group_education`).
- **US8 (P3)** → after Foundational (best verified after US1/US4 subforms exist).
- **Polish (P11)** → after all desired stories.

### Shared-file sequencing (avoid conflicts)

- `core.entity_form_display.visit.visit.default.yml`: T009 (US1) → T019 (US2) → T034 (US4) → T038
  (US5) → T044 (US7). Edit sequentially.
- `librechart_visit.install`: T003 → T023 → T031 → T037 (append distinct update hook functions).
- `librechart_pharmacy.module`: T011 → T016 → T018 → T040 (sequential).
- `Visit.php`: T002 → T022 → T028 → T036 (sequential).
- `pharmacy.css`: T008 (US1) → T041 (US6).

### Parallel opportunities

- US1 internals: T006, T007, T008 are `[P]` (widget / JS / CSS+library — different files).
- US2: T013 (vocab) and T017 (constraint) are `[P]`; T020 optional test `[P]`.
- US3: T024 (JS) and T025 (CSS+library) are `[P]`.
- US4: T032 (vocab) and T033 (paragraph type) are `[P]`.
- Cross-story: after Foundational, **US3** can run fully in parallel with the US1→US2 chain (no shared
  files). US6's T042 (theme CSS) is `[P]` with module work.
- Polish: T048 and T049 are `[P]`.

---

## Parallel Example: User Story 1

```bash
# After T004 (route) + T005 (controller), launch the independent client pieces together:
Task: "T006 MedicationAutocompleteWidget plugin"
Task: "T007 medication-autocomplete.js"
Task: "T008 pharmacy.css picker colors + libraries.yml"
```

---

## Implementation Strategy

### MVP first (US1 only)

1. Phase 1 Setup → 2. Phase 2 Foundational → 3. Phase 3 US1 → **STOP & validate** the prescribing
   picker independently → demo. This is the smallest shippable slice.

### Incremental delivery (recommended order)

Setup + Foundational → **US1** (prescribe) → **US2** (fulfill + inventory) = full P1 pharmacy
workflow → **US3** (allergies safety, parallelizable with US1/US2) → **US4** (education) → **US5**
(cleanup) → **US6** (report colors) → **US7** (POS) → **US8** (autosave) → Polish. Each story is an
independently testable increment.

### Notes

- `[P]` = different files, no incomplete dependency. Respect the shared-file sequencing above.
- Back up the database before `updatedb` in any environment with real data (US5 + US4 are
  destructive/migrating — quickstart §6).
- Export config after each config-affecting task; the final `config:export --diff` (T050) is the
  consistency gate.
