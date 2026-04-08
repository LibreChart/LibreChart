# Implementation Plan: Main Menu Navigation Links for Patients and Visits

**Branch**: `002-main-menu-nav-links` | **Date**: 2026-04-07 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/002-main-menu-nav-links/spec.md`

## Summary

Add two top-level links to the Drupal main navigation menu (`system.menu.main`) — one pointing to a Patient listing view and one pointing to a Visit listing view. Both views are created as Drupal Views page displays with their menu links defined in configuration YAML. No custom PHP code is required; the entire feature is delivered as exportable Drupal configuration.

## Technical Context

**Language/Version**: PHP 8.3+, Drupal CMS (latest stable)
**Primary Dependencies**: Drupal Views (core), Drupal Menu system (core)
**Storage**: MySQL/MariaDB — all changes are config-driven; no schema changes needed
**Testing**: PHPUnit with `BrowserTestBase` for functional route and access tests
**Target Platform**: Linux server (LAN-hosted, no internet), DDEV for local development
**Project Type**: Drupal web application — configuration-driven feature
**Performance Goals**: Standard Drupal page load; no elevated performance targets
**Constraints**: Config-managed only (no hardcoded links or UI-only changes), no external CDN or cloud dependencies, LAN-only deployment
**Scale/Scope**: 1 updated view + 1 new view + 2 menu links; <50 concurrent clinic staff users
**Access**: `view patient entities` (librechart_patient), `view visit entities` (librechart_visit) — custom entity permissions; not `access content` (node permission). Views use `base_table: patient` / `base_table: visit` (custom entities), not `node_field_data`.

## Constitution Check

The project constitution file is an unfilled template with no ratified project-specific principles. No gates to evaluate. This section will be re-evaluated once the constitution is populated.

## Project Structure

### Documentation (this feature)

```text
specs/002-main-menu-nav-links/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   └── routes.md
└── tasks.md             # Phase 2 output (/speckit.tasks — NOT created here)
```

### Source Code (repository root)

```text
config/sync/
├── views.view.patient_search.yml  # Patient listing view — UPDATED to add main menu link + municipality field
└── views.view.visits.yml          # Visit listing view + menu link (new)

web/modules/custom/librechart_visit/tests/src/Functional/
└── MainMenuNavLinksTest.php       # Functional test stub (new)
```

No custom module code is required. Access control uses the existing custom entity permissions `view patient entities` and `view visit entities` defined by `librechart_patient` and `librechart_visit` modules respectively.

**Structure Decision**: Config-only. Both views use custom entity base tables (`patient`, `visit`) — not `node_field_data` as originally specced. The patient listing reuses and extends `views.view.patient_search` (which already served `/patients`) rather than introducing a conflicting new view. The visit listing is a new view. All changes are exported as standard Drupal Views YAML and committed to `config/sync/`.

## Complexity Tracking

No constitution violations to justify.
