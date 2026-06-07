# Implementation Plan: EMR Station Transitions & Status Tracking

**Branch**: `003-emr-station-transitions` | **Date**: 2026-05-19 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/003-emr-station-transitions/spec.md`

## Summary

Add a `current_station` field to the existing Visit entity to track where each in-progress visit sits in the 7-station workflow (Registration → Triage → Lab → Clinical → PT* → Pharmacy → Teaching). The visit edit form gains a horizontal station progress strip above the form with the current station highlighted; the station owner sees two transition controls in the form actions area: (1) a one-click "Send to next station" linear shortcut that advances the field through the canonical workflow (skipping PT when no PT Referral was ordered, flipping `status` to `complete` after the final station), and (2) a "Send to specific station…" picker that lets the owner route to any of the six non-current stations for clinician-directed or load-balancing reasons. A new `/floor` controller renders a column-per-station board listing in-progress visits, linking each to its edit form. All transitions — linear or picker — log a Visit revision capturing prior → new station + acting user. Picker transitions also emit a `notice`-level watchdog entry; tampered picker submissions emit a `warning`. The picker does not apply PT-skip and does not flip `status` to `complete` — those remain shortcut-only behaviors.

## Technical Context

**Language/Version**: PHP 8.3+ (matches `composer.json` core requirement)
**Primary Dependencies**: Drupal core 11.x (Entity API, Form API, Routing); existing `librechart_visit` custom module; no new contrib modules required (the conditional_fields module already handles `pt_referral` visibility, and the Visit entity already supports revisions)
**Storage**: MariaDB/MySQL via Drupal DBTNG; new column `current_station VARCHAR(32)` added to the `visit` and `visit_field_revision` tables via Drupal's entity schema update API (no manual migration SQL)
**Testing**: PHPUnit Kernel + Functional via `ddev exec phpunit -c web/core/phpunit.xml.dist` (per CLAUDE.md); use existing `librechart_visit/tests/` location; manual UAT via the seeded visit data
**Target Platform**: LAN-hosted Linux server (per project memory); browser support per Drupal 11 defaults; offline-capable (no external CDNs)
**Project Type**: Drupal web application — single project, custom modules under `web/modules/custom/`
**Performance Goals**: Floor view renders ≤1s for 300 in-progress visits (SC-003); station transition form submission ≤500ms server-side
**Constraints**: All assets self-hosted (LAN deployment); English source strings (Spanish overlay only); all changes config-driven where possible (CLAUDE.md "Configuration-First Development"); preserve existing Visit revision history and station-based per-field edit access (existing `librechart_visit_entity_field_access` hook)
**Scale/Scope**: ~300 visits/day during clinic operations; 6 staff roles; 7 stations; 1 new entity field; 1 new controller route; 1 new page; ~15 form-alter additions (station strip + linear button + picker); 2 transition mechanisms (linear shortcut + picker) writing the same field

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

The project's `.specify/memory/constitution.md` is a template with placeholder principles (not yet ratified). In its absence, this plan adheres to the **CLAUDE.md** conventions, which act as the de facto governance for this Drupal project:

- **Configuration-First** — `current_station` allowed values defined in the entity, displays exported to `config/sync`. Pass.
- **English source labels** — all station machine names/labels English; Spanish overlay applied via existing translation infrastructure. Pass.
- **Contrib over custom** — no replacement for the field needed; uses Drupal core `list_string`. Pass.
- **Drupal 11 coding standards** (PHP 8.3, strict types, PSR-4, 2-space indent, 120-char lines, PHPDoc on classes/methods, dependency injection). Plan adheres. Pass.
- **Preserve existing behavior** — visit revisions, station field access hook, optimistic locking on Visit::preSave all remain. Pass.

No violations to track in the Complexity Tracking table.

## Project Structure

### Documentation (this feature)

```text
specs/003-emr-station-transitions/
├── plan.md              # This file
├── research.md          # Phase 0: open questions + decisions
├── data-model.md        # Phase 1: current_station field schema + transitions
├── quickstart.md        # Phase 1: how a developer/QA runs through US1–US4
├── contracts/
│   └── station-transitions.md  # Allowed transitions + role permissions
└── tasks.md             # Phase 2 output (NOT created by /speckit.plan)
```

### Source Code (repository root)

```text
web/modules/custom/librechart_visit/
├── librechart_visit.module                 # MODIFY: hook_entity_presave for transition log + form_alter to add station strip + Send action
├── librechart_visit.libraries.yml          # NEW: station_strip library (CSS)
├── librechart_visit.routing.yml            # NEW: /floor route
├── librechart_visit.links.menu.yml         # NEW: "Floor" menu link
├── librechart_visit.install                # NEW: update hook to install current_station field on existing sites
├── src/
│   ├── Entity/Visit.php                    # MODIFY: add current_station base field definition
│   ├── Controller/FloorController.php      # NEW: renders column-per-station board
│   └── Service/StationWorkflow.php         # NEW: pure logic — next station, PT skip rule, terminal handling
└── css/
    └── station-strip.css                   # NEW: progress-strip + floor column styling

web/modules/custom/librechart_patient/src/Controller/
└── PatientChartController.php              # MODIFY: include station strip above the embedded visit form

config/sync/
└── (entity field schema update applied via librechart_visit.install update hook;
    form display config exports happen after the field is installed)

scripts/
└── backfill_current_station.php            # NEW: sets current_station for existing visits
                                            #      (complete → 'teaching', others → 'registration')
```

**Structure Decision**: Single Drupal project; all new code lives in the existing `librechart_visit` custom module. No frontend/backend split — Drupal handles both. New service `StationWorkflow` isolates the pure transition logic (next-station, skip-PT) so it can be unit-tested without the entity layer.

## Complexity Tracking

> No Constitution violations to track.
