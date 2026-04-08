# Research: Main Menu Navigation Links for Patients and Visits

**Branch**: `002-main-menu-nav-links` | **Date**: 2026-04-07

---

## 1. How Drupal Views Defines Menu Links

**Decision**: Use a Views page display with `menu.type: normal` and `menu_name: main`.

**Rationale**: Drupal Views page displays support a `menu` block in their display options that integrates directly with Drupal's menu system. Setting `type: normal` registers the view's route as a standard menu link — appearing as a top-level navigation item. This is distinct from `type: tab` (creates a local task/tab under another route) and `type: default tab` (marks a route as the default tab in a tab group). For primary main-menu navigation, `type: normal` is correct.

**Alternatives considered**:
- `menu_link_content` entity (UI-created menu links): Not config-portable without UUID management; bypasses Views' built-in routing.
- Custom `*.links.menu.yml` in a module: Valid, but redundant when Views can own its own menu link.
- Hardcoded menu items in theme: Violates config-first approach; not role-aware.

**Reference pattern in codebase**: `views.view.media.yml` demonstrates `menu_name: main` with `type: tab` for an admin sub-tab. The patient and visit views follow the same YAML structure but use `type: normal` for a top-level link.

---

## 2. URL Paths and Menu Label Language

**Decision**: `/patients` for the patient listing view; `/visits` for the visit listing view. Menu link titles and all config labels authored in English (`Patients`, `Visits`). Spanish strings are applied through the Drupal translation layer, not baked into source config.

**Rationale**: Short, descriptive, role-neutral paths. Staff navigate to these from the main menu, so paths should be memorable and predictable. They avoid `/admin/` prefix since these are operational pages for clinic staff, not site administration tools. English is the authoring language for all config and interface strings; Drupal's translation system maps these to Spanish at runtime when a user's language preference is Spanish. This is the standard Drupal multilingual pattern.

**Alternatives considered**:
- `/admin/patients`, `/admin/visits`: Conventional for Drupal admin views, but semantically wrong — these are operational pages for clinic staff, not site administration tools.
- `/emr/patients`, `/emr/visits`: More specific, but adds path depth with no user benefit given this is a single-purpose EMR system.
- Authoring labels directly in Spanish: Incorrect — Drupal's config import uses English as the source language. Baking Spanish into the YAML would bypass the translation system and break the English interface option.

---

## 3. Access Control for the Views

**Decision**: Gate the patient view on a `view patient content` permission and the visit view on a `view visit content` permission, both defined as part of the Patient and Visit content types in the 001-emr-rebuild feature.

**Rationale**: Drupal Views page displays accept an access plugin configuration. The simplest and most maintainable approach is `access.type: perm` pointing to a Drupal permission. Since 001-emr-rebuild defines role-based access for each content type, its permissions are the correct gate. The menu link visibility in Drupal automatically follows the route's access check — users without permission do not see the link.

**Alternatives considered**:
- `access.type: role`: Ties the view to specific roles by machine name, making it brittle if roles are renamed or merged.
- `access.type: none`: No access check — inappropriate for a clinical EMR.
- Custom access callback: Unnecessary; standard permission checks are sufficient.

**Dependency note**: The exact permission machine names (`view patient content`, `view visit content` or equivalent) must be confirmed once the Patient and Visit content types are defined in 001-emr-rebuild. If Drupal CMS auto-generates these as `view any patient content`, the views config must use the generated name.

---

## 4. Menu Weight and Ordering

**Decision**: Patient link at weight `0`, Visit link at weight `10`. Both sit under no parent (top-level).

**Rationale**: The main menu currently has no EMR-specific items; the two new links are the first clinical navigation entries. Assigning incremental weights (0, 10) leaves room to insert items between them later without renumbering. Lower weight = higher position in menu render order.

**Alternatives considered**:
- Weight `-10`, `-20`: Conventional for forcing items to the top, but appropriate when displacing existing items. Not needed here since the main menu has no competing items from 001-emr-rebuild yet.
- Equal weight with alphabetical fallback: Drupal sorts equal-weight items alphabetically; acceptable but less explicit.

---

## 5. View Display Columns (Minimum Viable Listing)

**Decision**: Patient listing shows: Patient name (linked to patient), Cedula/ID, Municipality, Date of Birth. Visit listing shows: Visit date, Patient name (linked to patient), Clinic site, Visit status.

**Rationale**: These are the minimum fields needed for staff to identify and select the correct record from a listing. Triage nurses and registration staff need to distinguish patients by name and ID quickly. Visit staff need date and site to find today's visit. All fields map to attributes already defined in the 001-emr-rebuild data model.

**Alternatives considered**:
- Full-field listings: Too wide for tablet/laptop screens at clinic stations.
- Name-only listing: Insufficient for de-duplication (multiple patients with same name).

---

## 6. Patient and Visit Views Dependency on 001-emr-rebuild

**Finding**: As of 2026-04-07, no `views.view.patients.yml` or `views.view.visits.yml` exists in `config/sync/`. The Patient and Visit content types are not yet committed to config.

**Impact**: This feature cannot be deployed until the Patient and Visit content types exist in configuration. The views YAML files created here will reference those content types.

**Decision**: This feature's implementation tasks should be ordered after the Patient and Visit content type tasks in 001-emr-rebuild are complete. The view configs can be drafted now against the expected content type machine names (`patient`, `visit`) and activated once those types exist.
