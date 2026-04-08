# Quickstart: Main Menu Navigation Links

**Branch**: `002-main-menu-nav-links` | **Date**: 2026-04-07

## Prerequisites

Before implementing this feature, confirm the following are present in `config/sync/`:

- `node.type.patient.yml` — Patient content type
- `node.type.visit.yml` — Visit content type
- Field config for `field_cedula`, `field_municipality`, `field_date_of_birth`, `field_visit_date`, `field_patient`, `field_clinic_site`, `field_visit_status`
- A user role with `view patient content` and `view visit content` permissions

If these do not exist, complete the relevant tasks in 001-emr-rebuild first.

## Implementation Steps

### 1. Create the Patient listing view

Create `config/sync/views.view.patients.yml`. The view must:

- Target the `node_field_data` base table filtered to `type = patient`
- Have a page display at path `/patients`
- Set `menu.type: normal`, `menu.menu_name: main`, `menu.title: Patients`, `menu.weight: 0`
- Set `access.type: perm` with the `view patient content` permission
- Display fields: title (linked), cedula, municipality, date of birth
- Expose a search filter on title and cedula

Use the structure from `config/sync/views.view.content.yml` as a reference for YAML shape, but set `menu_name: main` and `type: normal` (not `tab`).

### 2. Create the Visit listing view

Create `config/sync/views.view.visits.yml`. The view must:

- Target the `node_field_data` base table filtered to `type = visit`
- Have a page display at path `/visits`
- Set `menu.type: normal`, `menu.menu_name: main`, `menu.title: Visits`, `menu.weight: 10`
- Set `access.type: perm` with the `view visit content` permission
- Display fields: visit date, patient (linked), clinic site, visit status
- Expose filters on clinic site and status

### 3. Import configuration

```bash
ddev drush config:import -y
ddev drush cache:rebuild
```

### 4. Verify

```bash
# Confirm views are registered
ddev drush views:list | grep -E "patients|visits"

# Confirm menu links exist
ddev drush menu:link-list main | grep -E "Patients|Visits"
```

Then log in as a user with the appropriate role and confirm:
- `/patients` loads the patient listing
- `/visits` loads the visit listing
- Both links appear in the main navigation
- A user without the role does not see the links

### 5. Export and commit

After verifying in the browser or via Drush:

```bash
ddev drush config:export -y
git add config/sync/views.view.patients.yml config/sync/views.view.visits.yml
git commit -m "Add patient and visit listing views with main menu links"
```

## Testing

Run functional tests to assert route access:

```bash
ddev exec phpunit --filter MainMenuNavLinksTest web/modules/custom/librechart_emr/tests/src/Functional/
```

Test cases to cover:
- `GET /patients` returns 200 for a user with `view patient content`
- `GET /patients` returns 403 for a user without that permission
- `GET /visits` returns 200 for a user with `view visit content`
- `GET /visits` returns 403 for a user without that permission
- Main menu contains "Patients" link for permitted user, absent for unpermitted user
- Main menu contains "Visits" link for permitted user, absent for unpermitted user
