# Implementation Plan: Librechart EMR Rebuild

**Branch**: `001-emr-rebuild` | **Date**: 2026-03-15 | **Spec**: [spec.md](spec.md)

---

## Summary

Librechart is a Drupal CMS–based Electronic Medical Record system for multi-site medical mission clinics. It replaces a monolithic "Medical Record" node type with a proper Patient entity (persistent across visits, with full revision history) and Visit entity (one per clinical encounter, with optimistic locking). Station data (triage, labs, clinical evaluation, PT, pharmacy, teaching) is structured as field groups on the Visit entity, with repeatable sub-records (lab results, prescription items, inventory events) as Paragraphs or custom entities. Visit completion status is informational — station edits remain possible after completion. A self-hosted Whisper.cpp server provides offline speech-to-text dictation. The interface is bilingual (Spanish default, English available per user), with all translation files committed to the repository for LAN-only deployment. All reports are exportable to CSV and PDF. Sessions expire after 30 minutes of inactivity. All functionality operates on a LAN-hosted Linux server with no internet dependency.

---

## Technical Context

**Language/Version**: PHP 8.3+
**Base Platform**: Drupal CMS (Drupal 11-based distribution), `composer create-project drupal/cms-project`
**Primary Dependencies**:
- `drupal/gin` — Admin theme (ships with Drupal CMS)
- `drupal/paragraphs` — Lab Result sub-records
- `drupal/field_group` — Visual grouping of station fields on Visit form
- `drupal/field_permissions` — Per-field role-based edit/view access
- `drupal/conditional_fields` — Adult/pediatric field visibility rules
- `drupal/search_api` — Patient search (with Database backend)
- `drupal/migrate_plus` + `drupal/migrate_tools` — Legacy taxonomy import
- `drupal/autologout` — 30-minute inactivity session timeout with 2-minute warning
- `drupal/entity_print` + Dompdf — PDF export for reports
- Drupal core `language`, `locale`, `config_translation`, `interface_translation` — Spanish/English bilingual interface
- whisper.cpp — Self-hosted speech-to-text server (external binary, not a Drupal module)
**Storage**: MySQL / MariaDB (DDEV default; Linux server production)
**Testing**: PHPUnit (Drupal core test framework), PHPCS (Drupal coding standards), PHPStan level 6
**Target Platform**: Linux server (LAN-hosted, no internet); DDEV for local development
**Project Type**: Web application (Drupal CMS distribution with custom modules)
**Performance Goals**: Patient search results in <2s; inventory report generation <10s; dictation transcription <10s for 1-minute audio; CSV/PDF export <15s for any report
**Constraints**: Zero external network dependencies at runtime; all assets and translation files self-hosted; operates on modest Linux server hardware
**Scale/Scope**: ~3 clinic sites; tens of concurrent users during clinic days; thousands of patient records per year

---

## Constitution Check

The project constitution is a blank template — no project-specific principles have been ratified. No gate violations to evaluate.

*Recommended*: Run `/speckit.constitution` after Phase 1 to establish Drupal-specific development principles (config-first, module separation, coding standards) before task generation.

---

## Project Structure

### Documentation (this feature)

```
specs/001-emr-rebuild/
├── plan.md              ← this file
├── research.md          ← Phase 0: technology decisions and rationale
├── data-model.md        ← Phase 1: entity definitions, fields, relationships
├── quickstart.md        ← Phase 1: local setup and deployment instructions
├── contracts/
│   └── dictation-api.md ← Phase 1: dictation endpoint contract
└── tasks.md             ← Phase 2 output (/speckit.tasks — not yet created)
```

### Source Code (repository root)

```
web/
├── modules/
│   └── custom/
│       ├── librechart_patient/       # Patient custom entity (revisions enabled)
│       │   ├── librechart_patient.info.yml
│       │   ├── librechart_patient.module
│       │   ├── src/Entity/Patient.php
│       │   ├── src/Entity/PatientInterface.php
│       │   └── config/install/
│       ├── librechart_visit/         # Visit entity + all station fields (optimistic locking)
│       │   ├── librechart_visit.info.yml
│       │   ├── librechart_visit.module
│       │   ├── src/Entity/Visit.php
│       │   └── config/install/
│       ├── librechart_lab/           # LabResult paragraph type
│       │   ├── librechart_lab.info.yml
│       │   └── config/install/
│       ├── librechart_pharmacy/      # PrescriptionItem, DrugInventory, InventoryReceipt
│       │   ├── librechart_pharmacy.info.yml
│       │   ├── librechart_pharmacy.module
│       │   ├── src/Entity/PrescriptionItem.php
│       │   ├── src/Entity/DrugInventory.php
│       │   ├── src/Entity/InventoryReceipt.php
│       │   └── config/install/
│       ├── librechart_dictation/     # Whisper.cpp proxy + JS dictation UI
│       │   ├── librechart_dictation.info.yml
│       │   ├── librechart_dictation.routing.yml
│       │   ├── src/Controller/DictationController.php
│       │   ├── js/dictation.js
│       │   └── config/install/
│       ├── librechart_reports/       # Views-based reporting with CSV/PDF export
│       │   ├── librechart_reports.info.yml
│       │   └── config/install/
│       └── librechart_migrate/       # Legacy taxonomy migration
│           ├── librechart_migrate.info.yml
│           ├── migrations/
│           │   ├── librechart_taxonomy_clinic_sites.yml
│           │   ├── librechart_taxonomy_diagnoses.yml
│           │   └── librechart_taxonomy_drugs.yml
│           └── src/Plugin/migrate/source/
├── themes/
│   └── custom/
│       └── librechart_theme/         # Gin subtheme (branding + station form styling)
│           ├── librechart_theme.info.yml
│           ├── librechart_theme.libraries.yml
│           └── css/
translations/
└── es.po                             # Committed Spanish PO file (downloaded during dev)
config/
└── sync/                             # All Drupal CMI configuration (committed to git)
composer.json
composer.lock
```

**Structure Decision**: Standard Drupal CMS layout with domain-separated custom modules. Configuration in `config/sync` for full version control. Translation PO files in `translations/` committed to the repository. No backend/frontend split — Drupal handles both via admin forms and Views.

---

## Complexity Tracking

No constitution violations to justify. All complexity in this plan is directly required by spec requirements.

---

## Implementation Phases

### Phase 1: Foundation, Patient/Visit Core & Multilingual Setup

**Goal**: A working Drupal CMS installation with Patient and Visit entities, basic field structure, user roles, session security, patient search, and a fully functioning bilingual (Spanish/English) interface. Deployable and demonstrable independently.

**Deliverables**:
1. Drupal CMS installed via `drupal/cms-project` with irrelevant modules disabled (Experience Builder, AI Assistant, marketing tools)
2. Core multilingual modules enabled: `language`, `locale`, `config_translation`, `interface_translation`
3. Spanish (`es`) added as a language; set as system default. English retained as secondary language
4. Spanish PO files downloaded for Drupal core and all enabled contrib modules; committed to `translations/es.po`
5. `librechart_patient` module: Patient entity with all demographic fields, **revisions enabled** (FR-001a), admin list view, search via Views exposed filters
6. `librechart_visit` module: Visit entity with core fields (patient reference, date, patient_type, clinic_site, status); **optimistic locking** via `changed` timestamp check on save (FR-015a); **visit completion is informational only** — no fields locked on complete (FR-015b)
7. 8 user roles created with basic visit/patient CRUD permissions; per-user language preference enabled
8. `drupal/autologout` installed and configured: **30-minute inactivity timeout, 2-minute warning prompt, unsaved data warning** (FR-028a/FR-028b)
9. Gin admin theme configured; `librechart_theme` subtheme scaffolded
10. All taxonomy vocabularies created (empty; populated in Phase 4 via migration); vocabulary labels translated into Spanish via `config_translation`
11. DDEV environment confirmed working; CI baseline (PHPCS, PHPStan, PHPUnit) passing

**Acceptance**: A Spanish-speaking Registration Staff user can create a Patient (revisions tracked), open a Visit, and view the patient's visit history — entirely in Spanish. Session auto-logout fires after 30 minutes of inactivity with a 2-minute warning. An optimistic lock conflict on Visit save produces a clear reload prompt.

---

### Phase 2: Clinic Station Fields & Conditional Visibility

**Goal**: All station sections on the Visit form, with field group UI, per-field role access, and adult/pediatric conditional visibility. All field labels translated into Spanish.

**Deliverables**:
1. All Triage station fields on Visit (vitals, complaint, PMH, allergies, pregnancy fields)
2. BMI auto-calculation on Visit presave
3. Clinical Evaluation fields (body system booleans, diagnosis references, notes, clinician name, referrals)
4. Physical Therapy fields (PT notes, interventions, PT name); visible only when `pt_referral = true`
5. Teaching & Referrals fields
6. `field_group` module installed; station fields grouped into collapsible field groups on Visit form
7. `field_permissions` module configured: each role can edit only their station's fields; **edits remain possible on completed visits** (soft lock per FR-015b)
8. `conditional_fields` module configured: pediatric rules applied (hide pregnancy history, LMP, breastfeeding, GYN/OB system, pregnancy lab)
9. `LabResult` paragraph type (`librechart_lab`); Visit has `lab_results` paragraph field; Lab Technician role can enter results
10. All field labels, group headings, and help text translated into Spanish via `config_translation` and committed to `config/sync/`

**Acceptance**: A full multi-user clinic day can be simulated in Spanish: Registration → Triage → Lab → Clinical Eval → PT → Teaching, each step by a different role, with correct field access, pediatric hiding, and zero English strings visible to Spanish-preference users. A completed visit remains editable by the appropriate station role.

---

### Phase 3: Pharmacy & Inventory

**Goal**: Pharmacy dispensing with per-drug dosage fields, inventory tracking, and pharmacy report.

**Deliverables**:
1. `PrescriptionItem` custom entity; Pharmacist can add multiple prescriptions to a Visit
2. `DrugInventory` custom entity; one record per drug per clinic site
3. `InventoryReceipt` custom entity; Pharmacist can record stock additions
4. Save hook: PrescriptionItem save decrements DrugInventory.quantity_on_hand
5. Save hook: InventoryReceipt save increments DrugInventory.quantity_on_hand
6. Low-stock threshold field on DrugInventory; low-stock drugs flagged in report
7. Inventory report View (filterable by clinic site, date range): on-hand, received, dispensed per drug
8. Warning UI when pharmacist attempts to dispense a drug with quantity_on_hand < quantity_dispensed
9. All pharmacy UI strings translated into Spanish

**Acceptance**: Pharmacist can add stock, dispense drugs, see inventory deducted in real time, and generate a Spanish-language inventory report filtered by clinic site.

---

### Phase 4: Legacy Data Migration

**Goal**: All 39 legacy taxonomy vocabulary terms imported from OpenEMR database.

**Deliverables**:
1. `librechart_migrate` module with Migrate API plugins for each vocabulary group
2. Migration configuration for: clinic_sites, municipalities, village_town, referrals, allergies, orders, all diagnosis vocabularies, all drug category vocabularies
3. `ddev drush migrate:import --group=librechart_taxonomy` runs without errors
4. Rollback (`migrate:rollback`) confirmed working
5. Migrated taxonomy terms reviewed against Spanish translation requirements — terms already in Spanish require no additional translation; any English terms are translated

**Acceptance**: All drug and diagnosis dropdowns are populated with legacy content; zero manual re-entry of taxonomy terms required.

---

### Phase 5: Speech-to-Text Dictation

**Goal**: Self-hosted whisper.cpp dictation on all long-form text fields used by clinical staff, supporting both English and Spanish audio.

**Deliverables**:
1. `librechart_dictation` module: `POST /api/dictation/transcribe` endpoint (see `contracts/dictation-api.md`)
2. Endpoint proxies audio to whisper.cpp HTTP server; returns transcript JSON
3. `dictation.js`: Attaches microphone button to all `data-dictation-enabled` text_long fields; handles recording, upload, transcript insertion at cursor; button label and error messages translated via `Drupal.t()`
4. Admin config form: whisper server URL, enabled roles, language (`en`/`es`), max duration
5. Whisper.cpp deployed with the multilingual `small` model (supports both `en` and `es`; ~465 MB RAM)
6. Graceful degradation: 503/422 responses show Spanish error messages; field remains keyboard-editable
7. `docs/whisper-setup.md`: Instructions for compiling whisper.cpp and running as systemd service on Linux server

**Acceptance**: Clinician can dictate clinical notes in Spanish with no internet connection; transcript appears in field within 10 seconds; if whisper server is stopped, keyboard entry continues unaffected.

---

### Phase 6: Reporting, Export & Patient History

**Goal**: Demographic reports, diagnosis frequency reports, pharmacy inventory report, and patient visit history view — all with English labels (Spanish available via translation) and CSV/PDF export.

**Deliverables**:
1. `librechart_reports` module: Views configuration for demographic report (age group, sex, municipality breakdowns)
2. Diagnosis frequency report View: most common diagnoses by body system, filterable by date range
3. Patient profile page: chronological list of all visits with summary (date, site, diagnoses, medications dispensed)
4. All report Views exported to `librechart_reports/config/install/`; View labels and exposed filter labels translated via `config_translation`
5. **CSV export** enabled on all three report Views using Drupal core Views data export (FR-035a)
6. **PDF export** enabled on all three report Views via `drupal/entity_print` + Dompdf; rendered server-side with no internet dependency (FR-035a)
7. All `entity_print` and Dompdf library assets committed to repository; no CDN calls at render time

**Acceptance**: Administrator can download CSV and PDF exports of all three reports in Spanish for any date range without technical assistance; patient history accessible from Patient profile page; all PDF generation works offline.

---

## Key Technical Decisions

| Decision | Choice | Reference |
|---|---|---|
| Base platform | Drupal CMS (Drupal 11) | research.md §1 |
| Patient entity | Custom content entity, revisions enabled | research.md §2; FR-001a |
| Visit entity | Custom content entity, optimistic locking | research.md §3; FR-015a |
| Visit completion | Soft lock — informational status, edits remain possible | FR-015b |
| Station sections | Field groups on Visit | research.md §3 |
| Lab results | Paragraphs | research.md §4 |
| Prescription/Inventory | Custom entities | research.md §4 |
| Conditional fields | `conditional_fields` module | research.md §5 |
| Field access | `field_permissions` module | research.md §6 |
| Patient search | Views exposed filters | research.md §7 |
| Speech-to-text | Whisper.cpp local server (multilingual `small` model) | research.md §8 |
| Admin theme | Gin + subtheme | research.md §9 |
| Config management | Drupal CMI (`config/sync`) | research.md §10 |
| Migration | Drupal Migrate API | research.md §11 |
| Spanish i18n | Drupal core multilingual stack; PO files committed to repo | research.md §12 |
| Session timeout | `drupal/autologout`; 30 min inactivity + 2 min warning | FR-028a/b |
| Report export | Views CSV (core) + `drupal/entity_print` + Dompdf (PDF) | FR-035a |

---

## Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| `conditional_fields` Drupal 11 compatibility gaps | Medium — conditional visibility may need fallback | Test early in Phase 2; fallback to `#states` API in `hook_form_alter` |
| whisper.cpp server hardware constraints | Medium — multilingual `small` model requires ~465 MB RAM | Verify server RAM before Phase 5; Vosk WASM is fallback for low-RAM servers |
| Legacy taxonomy vocabulary data quality | Low-Medium — messy terms may need manual cleanup | Run migration in Phase 4 and review output with clinic staff before go-live |
| Custom entity Views integration | Low — custom entities require ViewsData annotation | Implement and test Views integration in Phase 1; standard pattern in Drupal 11 |
| Drupal CMS bundled module conflicts | Low — some CMS modules may conflict with EMR workflows | Audit and disable non-essential Drupal CMS modules immediately after install |
| Incomplete Spanish translation coverage for custom strings | Medium — untranslated strings default to English, breaking SC-013 | Enforce `t()` usage in all custom module strings via PHPCS sniff; run locale:check in CI |
| Spanish PO files becoming stale after contrib module updates | Low-Medium — updated modules introduce new untranslated strings | Re-run `drush locale:update` and re-commit `translations/es.po` after each contrib update |
| Optimistic lock conflicts during busy clinic sessions | Low — rare given station-scoped permissions; disruptive when it occurs | Clear "record changed, please reload" message; preserve form values in session where possible |
| Dompdf PDF rendering performance on modest server hardware | Low-Medium — large reports may be slow to render | Benchmark during Phase 6; add async generation with download link if render time exceeds 15s |
