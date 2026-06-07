# Implementation Plan: Pharmacy Section Refinement

**Branch**: `005-pharmacy-refinement` | **Date**: 2026-05-31 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/005-pharmacy-refinement/spec.md`

## Summary

Refine the Visit form's Pharmacy section into a stock-aware prescribing/fulfillment workflow, plus
related Visit-form changes: required + always-visible allergies, a renamed Education station with
education-class referrals, a conditional POS (local-provider referral) field, inventory-report color
coding, and autosave for the Visit form.

Technical approach: **reuse the existing `PrescriptionItem` content entity and its inventory hooks**
in `librechart_pharmacy`, embedding multiple medication line items inside the Visit form via
**Inline Entity Form** (the one new contrib dependency). The medication picker becomes a custom
stock-aware autocomplete (custom route + widget + JS) sourced from `DrugInventory`. Inventory
adjustment is rewritten to be **delta-based, reversible, and idempotent** (fixing the current
one-way decrement) and to **block** fulfillment when stock is insufficient, reporting the remaining
quantity. Education referrals use a new **Paragraph type** (consistent with the existing `lab_result`
paragraph). The `teaching` station is renamed to `education` (id + references + a data-migration
update hook). POS reuses the existing `conditional_fields` pattern. Autosave is enabled for the
`visit` entity by extending `autosave_form.settings`. Allergy "sticky pills" are a small JS/CSS
component on the Visit form.

## Technical Context

**Language/Version**: PHP 8.3+ (Drupal core 11.x)
**Primary Dependencies**: Drupal core (Entity API, Form API, Routing, Views); already-enabled contrib
`field_group`, `conditional_fields`, `paragraphs` + `entity_reference_revisions`, `autosave_form`;
existing custom modules `librechart_visit` and `librechart_pharmacy`. **New contrib**:
`drupal/inline_entity_form` (D11-compatible release) to embed `PrescriptionItem` line items in the
Visit form.
**Storage**: MariaDB/MySQL via Drupal entity schema. Changes: new `pharmacist` + `education`
vocabularies (config); new `education_referral` paragraph type (config); new Visit base fields
(`medications`, `education_referrals`, `pos`, `pos_details`); new `fulfilled_by` field on
`prescription_item`; convert `notes_to_pharmacist` to plain (`string_long`); remove `pharmacist_name`;
extend `current_station` allowed values (`teaching` → `education`) with a data-migration update hook.
**Testing**: PHPUnit (`web/core/phpunit.xml.dist`) for kernel/functional tests; `phpcs` (Drupal
standard) and `phpstan` (level 6) on `web/modules/custom`.
**Target Platform**: LAN-hosted Linux server, fully offline/self-hosted (no external CDNs or
internet calls); DDEV for local development.
**Project Type**: Drupal web application — configuration-driven custom modules + a custom front-end
theme (`laughh`, sub-theme of `mercury`).
**Performance Goals**: Medication typeahead returns ≤10 matches in under ~1s; Visit form remains
responsive with multiple medication line items.
**Constraints**: Offline-only (self-host all JS/CSS — reuse Drupal core's autocomplete/jQuery UI, no
external assets); configuration-first (changes via YAML/config + update hooks, not ad-hoc DB edits);
Drupal coding standards (2-space indent, 120-col, strict types, PHPDoc); all source config
labels/strings in **English** (Spanish is a translation overlay — see project memory).
**Scale/Scope**: Single clinic site (one clinic-wide inventory pool — inventory matched by drug
only); modest concurrent clinical/pharmacy users.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

The repository's `.specify/memory/constitution.md` is an **unratified template** (placeholder
principles only), so there are no ratified gates to evaluate. In its place, this plan is held to the
project's established guardrails from `CLAUDE.md`:

- **Configuration-first** — entity/field/display/vocabulary changes are delivered as config
  (`config/install` + active config + `config:export`) and update hooks, not manual DB changes. ✅
- **Contrib over custom** — embedding reuses `inline_entity_form`; conditional logic reuses
  `conditional_fields`; repeating sub-content reuses `paragraphs`; autosave reuses `autosave_form`.
  The only custom code is where no contrib fits (stock-aware autocomplete, reversible inventory
  math, sticky-allergy UI, station rename). ✅
- **Reuse existing structures** — keeps `PrescriptionItem` + `DrugInventory` + inventory hooks +
  inventory reports rather than re-modeling them. ✅
- **Drupal standards + tooling** — phpcs/phpstan/phpunit run on custom modules. ✅
- **English-source labels** — new vocabularies/fields/labels authored in English. ✅

**Result: PASS** (no violations; no Complexity Tracking entries required).

Post-Phase-1 re-check: design introduces one new contrib module (`inline_entity_form`) and a bounded
set of custom components, each justified in [research.md](./research.md). Still **PASS**.

## Project Structure

### Documentation (this feature)

```text
specs/005-pharmacy-refinement/
├── plan.md              # This file (/speckit.plan)
├── research.md          # Phase 0 — decisions & rationale
├── data-model.md        # Phase 1 — entities, fields, migrations, validation
├── quickstart.md        # Phase 1 — build/enable/verify steps (DDEV + offline)
├── contracts/           # Phase 1 — interface contracts (autocomplete, inventory, UI components)
│   ├── medication-autocomplete.md
│   ├── inventory-adjustment.md
│   ├── medication-line-item-form.md
│   └── allergy-sticky-and-report-coloring.md
├── checklists/
│   └── requirements.md  # Spec quality checklist (from /speckit.specify)
└── tasks.md             # Phase 2 (/speckit.tasks — NOT created here)
```

### Source Code (repository root)

Drupal layout — changes are concentrated in two existing custom modules, one theme, and the config
sync directory:

```text
web/modules/custom/librechart_visit/
├── src/Entity/Visit.php                 # add medications, education_referrals, pos, pos_details;
│                                        #   convert notes_to_pharmacist→string_long; remove
│                                        #   pharmacist_name; rename current_station 'teaching'→'education'
├── src/Service/StationWorkflow.php      # rename 'teaching'→'education' (order, label, role, next)
├── librechart_visit.module              # form_alter: View-inventory link, sticky-allergy attach,
│                                        #   station_field_group_map 'teaching'→'education'
├── librechart_visit.install             # NEW update hook: station allowed-values + data migrate
│                                        #   teaching→education; notes_to_pharmacist conversion;
│                                        #   remove pharmacist_name; install new base fields
├── css/allergy-sticky.css               # NEW — sticky red allergy pills
├── js/allergy-sticky.js                 # NEW — render/refresh pills from allergies widget
└── librechart_visit.libraries.yml       # NEW 'allergy_sticky' library

web/modules/custom/librechart_pharmacy/
├── src/Entity/PrescriptionItem.php      # add fulfilled_by (ref→pharmacist vocab, default=logged-in)
├── src/Controller/MedicationAutocompleteController.php   # NEW — ≤10 stock-aware matches (JSON)
├── src/Plugin/Field/FieldWidget/MedicationAutocompleteWidget.php  # NEW — stock-aware drug widget
├── src/Plugin/Validation/Constraint/…   # NEW — block fulfillment when stock insufficient
├── librechart_pharmacy.module           # rewrite inventory adjust (delta/reversible/idempotent +
│                                        #   delete-restore); match by drug only; field-access gating
│                                        #   of prescription_filled + fulfilled_by to pharmacist role;
│                                        #   extend preprocess_views_view_table for out-of-stock rows
├── librechart_pharmacy.routing.yml      # NEW autocomplete route
├── js/medication-autocomplete.js        # NEW — color rows, disable out-of-stock selection
├── css/pharmacy.css                     # low-stock→yellow, out-of-stock→red (picker + report)
└── config/install/
    ├── taxonomy.vocabulary.pharmacist.yml   # NEW
    └── taxonomy.vocabulary.education.yml     # NEW (education classes)

web/themes/custom/laughh/css/style.css   # reconcile inventory-row colors (yellow/red)

config/sync/                              # exported counterparts of all of the above, plus:
├── core.entity_form_display.visit.visit.default.yml   # group_pharmacy (medications, IEF, notes),
│                                        #   group_teaching→group_education (+education_referrals, pos),
│                                        #   conditional_fields pos→pos_details, remove pharmacist_name
├── autosave_form.settings.yml           # add 'visit' to allowed_content_entity_types
├── core.extension.yml                   # add inline_entity_form
├── paragraphs.paragraphs_type.education_referral.yml + field.*           # NEW paragraph type
└── taxonomy.vocabulary.pharmacist.yml / taxonomy.vocabulary.education.yml # NEW
```

**Structure Decision**: No new module is created. Work extends `librechart_visit` (Visit entity,
form, station workflow, sticky-allergy UI) and `librechart_pharmacy` (prescription line items,
inventory math, medication autocomplete, report coloring), with config changes exported to
`config/sync`. This matches the existing feature pattern (003/004 also extended these modules) and
honors contrib-over-custom by adding only `inline_entity_form`.

## Complexity Tracking

> No constitution violations. The single added dependency (`inline_entity_form`) and the custom
> components are justified in [research.md](./research.md); no entries required here.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| (none)    | —          | —                                   |
