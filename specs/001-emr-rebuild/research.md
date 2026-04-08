# Research: Librechart EMR Rebuild

**Branch**: `001-emr-rebuild` | **Date**: 2026-03-15

---

## 1. Base Platform: Drupal CMS vs Drupal 11 Core

**Decision**: Use Drupal CMS (the 2025 distribution) as the installation base, with awareness that many of its bundled features are not relevant to an EMR.

**Rationale**: The spec explicitly requests "the latest version of Drupal CMS." Drupal CMS (launched January 2025, formerly Project Starshot) is a distribution built on Drupal 11 core. It ships with the Gin admin theme, Recipes system, Automatic Updates, Project Browser, and the Experience Builder page editor. For Librechart, the relevant benefits are: a well-tested Drupal 11 base, Gin admin theme (suitable for clinical data entry interfaces), and the Recipes system for modular configuration install.

**Tradeoffs**: Several Drupal CMS features (Experience Builder, AI Assistant, marketing-focused tools) are irrelevant to an EMR and add maintenance surface. These modules should be uninstalled after initial setup to keep the footprint minimal.

**Alternatives considered**:
- Bare Drupal 11 core: Slightly leaner, but requires more initial configuration; Drupal CMS's Gin theme and recipe infrastructure are worth keeping.
- Drupal 10: Not considered — Drupal CMS requires Drupal 11.

**Composer command**: `composer create-project drupal/cms-project librechart`

---

## 2. Patient Entity Architecture

**Decision**: Custom content entity via Drupal's core Entity API, implemented in a dedicated `librechart_patient` module.

**Rationale**: The Patient entity represents a real-world person independent of any Drupal user account. It requires custom field definitions, custom access control, and must be fully version-controlled as configuration. Custom entities provide this control with no unnecessary abstractions.

**Alternatives considered**:
- **ECK (Entity Construction Kit)**: Appropriate for rapid prototyping; not appropriate for a healthcare production system where the schema must be fully source-controlled and auditable. ECK stores entity type definitions in the database.
- **Profile module**: Designed for user-linked profiles (extending the User entity). Inappropriate for a standalone patient record with no corresponding user account.
- **Node (content type)**: Nodes carry publishing/unpublishing, menus, URL aliases, and revision overhead not needed for patient records. Semantically wrong — patients are not "content."

---

## 3. Visit Entity Architecture

**Decision**: Custom content entity via core Entity API, implemented in `librechart_visit` module. Station fields are placed directly on the Visit entity and grouped visually using the Field Group module.

**Rationale**: A Visit is a structured medical record — not a "page" or "article." Using nodes would impose publishing states, menu integration, and URL alias overhead that is semantically wrong for medical records. A custom entity with all station fields directly on it (grouped by Field Group) is simpler and more performant than a node with embedded paragraphs for sections that appear exactly once per visit.

**Station field grouping approach**: The Field Group module allows fields to be grouped into collapsed/expanded vertical tabs or fieldsets on the edit form. Each clinic station (Triage, Lab, Clinical Evaluation, Physical Therapy, Pharmacy, Teaching) is one field group. This is the correct approach for sections that have a 1:1 relationship with the visit.

**Alternatives considered**:
- **Paragraph types per station**: Adds a layer of indirection for sections that appear exactly once per visit. Appropriate for repeatable items; unnecessary overhead for singleton sections.
- **Separate entity per station**: Over-engineering. Querying clinical data would require joins across 7 entity types per visit.

---

## 4. Repeatable Sub-Records: Paragraphs vs Custom Entities

**Decision**: Split based on whether the sub-record needs independent querying.

| Sub-record | Approach | Reason |
|---|---|---|
| Lab Results (per test ordered) | Paragraph type | 1 per ordered test, no independent reporting queries needed |
| Prescription Items (drugs dispensed) | Custom content entity | Must be queried independently for inventory calculations and reports |
| Inventory Receipts (stock additions) | Custom content entity | Must be queried for inventory reports and audit trail |

**Rationale**: Paragraphs are the correct Drupal pattern for structured sub-components that are always rendered as part of their parent and don't need to be queried in isolation. Lab results are only ever displayed within a visit, making Paragraphs appropriate. Prescription Items and Inventory Receipts must be aggregated across all visits to compute on-hand inventory, requiring them to be proper entities with their own Views integration.

---

## 5. Conditional Field Visibility (Pediatric/Adult)

**Decision**: Use the `conditional_fields` contrib module for the patient_type → field visibility rules.

**Rationale**: The `conditional_fields` module (Drupal 11 compatible) provides a UI and configuration-based approach to showing/hiding fields based on another field's value. It stores conditions as configuration (not database state), making it version-controllable. This is exactly the use case: `field_patient_type = pediatric` → hide pregnancy-related fields.

**Implementation note**: Conditions are defined in `core.entity_form_display` third-party settings and can be exported via `drush config:export`. The legacy system had this module installed but never configured it — Librechart will fully configure it.

**Alternatives considered**:
- Custom JavaScript: Brittle, harder to maintain, not configuration-managed.
- `hook_form_alter` with `#states` API: The Drupal `#states` system is the underlying mechanism `conditional_fields` uses. Writing it directly in a form_alter hook is equivalent but loses the UI configuration layer.

---

## 6. Role-Based Field Access

**Decision**: Use the `field_permissions` contrib module for per-field edit/view restrictions by role.

**Rationale**: `field_permissions` (Drupal 11 compatible) adds granular permissions for each field: "view", "edit own", "edit any". This maps directly to the requirement that each station role can only edit their station's fields. Configuration is exportable via CMI.

**Alternatives considered**:
- Custom `hook_entity_field_access()`: More control but more code. `field_permissions` handles this cleanly for standard per-role field access without custom logic.
- Group module: Overkill — designed for multi-group content isolation, not single-site per-field role restrictions.

---

## 7. Patient Search

**Decision**: Use Drupal core database search (Views with exposed filters) for Phase 1. Evaluate Search API with Database backend if performance requires it.

**Rationale**: Patient search by name, cedula, and date of birth is a simple structured query against a modest dataset (mission clinic volumes are in the hundreds to low thousands of records per year). Core Views with exposed filters on the Patient entity provides this without additional infrastructure. Search API can be added later if fuzzy matching or full-text indexing is needed.

**Alternatives considered**:
- Search API + Solr: Requires a Solr server. Unsuitable for a modest LAN server with no internet.
- Search API + Database: Reasonable fallback if Views exposed filters prove insufficient for fuzzy name matching.

---

## 8. Speech-to-Text: Self-Hosted Transcription

**Decision**: Deploy `whisper.cpp` as a persistent HTTP server on the LAN host. Build a `librechart_dictation` Drupal module that proxies audio from the browser to the whisper.cpp server and returns transcription.

**Rationale**: Documented in `specs/research/speech-to-text.md`. Web Speech API rejected (requires Google cloud). Whisper.cpp provides the best accuracy/resource tradeoff for a server with ≥1 GB available RAM. The `small.en` model (~500 MB RAM) transcribes a 1-minute clip in ~4–6 seconds on a modern CPU — well within the 10-second success criterion.

**Model recommendation**: `small.en` for initial deployment. Upgrade to `medium.en` if hardware allows (improves accuracy on accented speech and medical terminology).

**Integration architecture**:
1. Browser: `MediaRecorder` API captures audio → WAV/WebM blob
2. JavaScript POSTs blob to Drupal endpoint: `POST /api/dictation/transcribe`
3. Drupal `librechart_dictation` module forwards audio to whisper.cpp HTTP server (local: `http://localhost:8080/inference`)
4. Transcript returned as JSON, JavaScript inserts text into active field at cursor

**Fallback**: If whisper.cpp server is down, the Drupal endpoint returns a clear error; JavaScript catches it and leaves the text field editable by keyboard.

**Deferred**: Vosk (WASM in-browser) noted as fallback for very low RAM servers. Drupal AI module integration deferred until broader AI feature adoption.

---

## 9. Admin Theme

**Decision**: Use the Gin admin theme (`drupal/gin`) with a `librechart_theme` subtheme for clinic-specific customisation.

**Rationale**: Gin is Drupal 11 compatible, ships with Drupal CMS, and provides a modern, clean admin interface well-suited to data-entry-heavy workflows. Its sidebar navigation and high-contrast mode are appropriate for clinical settings. A subtheme allows clinic-specific branding and field group styling without patching Gin directly.

**Alternatives considered**:
- Claro (Drupal core admin theme): Functional but dated. Less suitable for complex multi-section forms.
- Custom theme from scratch: Unnecessary effort when Gin provides a strong base.

---

## 10. Configuration Management & Module Structure

**Decision**: All content model definitions (entity types, fields, vocabularies, roles, permissions, form displays) are managed via Drupal's CMI (Configuration Management Interface) and stored in `config/sync/`. Custom functionality is split into feature modules by domain.

**Module structure**:
- `librechart_patient` — Patient entity type, fields, form displays
- `librechart_visit` — Visit entity type, station fields, field groups, conditional fields config
- `librechart_lab` — Lab Result paragraph type
- `librechart_pharmacy` — PrescriptionItem and DrugInventory entities, inventory logic
- `librechart_dictation` — Whisper.cpp proxy endpoint, JS dictation UI
- `librechart_reports` — Views-based reporting (demographics, diagnoses, inventory)
- `librechart_migrate` — Migration plugins to import legacy vocabulary content from OpenEMR

**Rationale**: Domain-separated modules allow independent enable/disable, cleaner dependency tracking, and parallel development. All configuration lives in `config/sync` for full version control.

---

## 11. Data Migration from Legacy System

**Decision**: Use Drupal's Migrate API with custom migration plugins in `librechart_migrate` to import taxonomy vocabulary terms (diagnoses, drug names, clinic sites, municipalities) from the legacy database.

**Rationale**: The legacy system at `/Users/aaronellison/Sites/mission` has 39 taxonomy vocabularies with clinical content that should not be re-entered manually. Migrate API is Drupal's standard tool for this, supports rollback, and can be re-run during development.

**Scope of migration**: Taxonomy terms only (diagnoses, drugs, referral destinations, clinic sites, municipalities). Patient records and medical record history are out of scope for Phase 1 — the legacy system remains available for historical lookups.

**Alternatives considered**:
- Manual re-entry: Unacceptable for 39 vocabularies with potentially hundreds of terms.
- Direct database copy: Fragile, schema-dependent, not repeatable.

---

## 12. Spanish Language Interface (i18n)

**Decision**: Use Drupal's core multilingual stack (`language`, `locale`, `config_translation`, `interface_translation` modules). Set Spanish (`es`) as the system default language. Bundle all Spanish PO translation files in the repository — do not rely on runtime downloads from localize.drupal.org.

**Rationale**: Drupal's multilingual system is battle-tested, configuration-managed, and covers the full interface: field labels, buttons, validation messages, Views headings, admin pages, and custom module strings. Bundling PO files is mandatory because the production server has no internet access; attempting to download translations at runtime would silently fail.

**Per-user language preference**: The `language` module supports per-user language preference stored on the user account. Staff who prefer English (typically visiting mission team members) set their account to English; local clinic staff use Spanish. Both are served from the same installation.

**Translation file workflow**:
1. During development, download Spanish PO files for Drupal core and all contrib modules using `ddev drush locale:check` + `ddev drush locale:update` (requires internet during development only)
2. Commit the downloaded PO files to `translations/` in the repository
3. On production install, translations are imported from the committed files — no internet needed

**Custom module strings**: All custom module PHP must use Drupal's `t()` function (or `$this->t()` in OOP context) for every user-facing string. Custom JS strings use `Drupal.t()`. This makes all custom strings extractable as PO source strings and translatable via the admin UI or PO file import.

**Whisper.cpp — Spanish dictation**: The `librechart_dictation` module's admin config form includes a language selector. When set to `es`, the Drupal endpoint passes `language=es` in the whisper.cpp request. The `small` (multilingual) model replaces `small.en` to support Spanish audio. The multilingual model is ~465 MB — nearly identical RAM footprint to `small.en`.

**Alternatives considered**:
- i18n contrib module (Drupal 7 era): Not applicable to Drupal 11; core multilingual replaces it entirely.
- Translating only field labels via config_translation without locale: Incomplete — would miss UI chrome, validation messages, and custom module strings.
- Shipping with English only and translating later: Unacceptable given Spanish-speaking staff are the primary users from day one.
