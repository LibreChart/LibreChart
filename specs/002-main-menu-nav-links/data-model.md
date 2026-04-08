# Data Model: Main Menu Navigation Links

**Branch**: `002-main-menu-nav-links` | **Date**: 2026-04-07

This feature introduces no new database entities. It adds two Drupal Views and their associated menu link registrations, all managed through configuration YAML.

---

## Configuration Entities

### views.view.patients

| Property | Value |
|----------|-------|
| View ID | `patients` |
| Label | `Patients` (translatable) |
| Base table | `node_field_data` |
| Base field | `nid` |
| Content type filter | `type = patient` |

**Page Display** (`patients_page`):

| Property | Value |
|----------|-------|
| Path | `/patients` |
| Access plugin | `perm` |
| Access permission | `access content` (all authenticated staff have this; anonymous users do not) |
| Menu type | `normal` |
| Menu name | `main` |
| Menu title | `Patients` |
| Menu weight | `0` |
| Menu parent | `` (top-level) |

**Fields displayed**:

| Field | Label | Notes |
|-------|-------|-------|
| `title` | Patient Name | Linked to patient node |
| `field_cedula` | Cedula / ID | Plain text |
| `field_municipality` | Municipality | Plain text |
| `field_date_of_birth` | Date of Birth | Formatted date |

**Sort**: Last name ascending (default); date created descending (secondary)
**Filter**: Exposed search on title (name) and `field_cedula`

---

### views.view.visits

| Property | Value |
|----------|-------|
| View ID | `visits` |
| Label | `Visits` (translatable) |
| Base table | `node_field_data` |
| Base field | `nid` |
| Content type filter | `type = visit` |

**Page Display** (`visits_page`):

| Property | Value |
|----------|-------|
| Path | `/visits` |
| Access plugin | `perm` |
| Access permission | `access content` (all authenticated staff have this; anonymous users do not) |
| Menu type | `normal` |
| Menu name | `main` |
| Menu title | `Visits` |
| Menu weight | `10` |
| Menu parent | `` (top-level) |

**Fields displayed**:

| Field | Label | Notes |
|-------|-------|-------|
| `field_visit_date` | Visit Date | Formatted date |
| `field_patient` | Patient | Entity reference; linked to patient node |
| `field_clinic_site` | Clinic Site | Entity reference label |
| `field_visit_status` | Status | In-progress / Complete |

**Sort**: Visit date descending (most recent first)
**Default filter**: Contextual filter on `field_visit_date` = current date (today); applied automatically on page load
**Exposed filters**: `field_clinic_site` (select), `field_visit_status` (select), `field_visit_date` date range — clearing the date range reveals all historical visits

---

## Configuration File Map

| File | Purpose |
|------|---------|
| `config/sync/views.view.patients.yml` | Patient listing view + main menu link |
| `config/sync/views.view.visits.yml` | Visit listing view + main menu link |

No other configuration files are added or modified by this feature. The main menu (`system.menu.main`) automatically picks up the new links from the Views menu registration.

---

## Dependencies on 001-emr-rebuild

The following must exist before these views can be activated:

| Dependency | Source |
|------------|--------|
| `patient` content type | 001-emr-rebuild |
| `visit` content type | 001-emr-rebuild |
| `field_cedula` field | 001-emr-rebuild |
| `field_municipality` field | 001-emr-rebuild |
| `field_date_of_birth` field | 001-emr-rebuild |
| `field_visit_date` field | 001-emr-rebuild |
| `field_patient` field | 001-emr-rebuild |
| `field_clinic_site` field | 001-emr-rebuild |
| `field_visit_status` field | 001-emr-rebuild |
| `access content` permission (granted to all authenticated roles) | Drupal core default |
