# Phase 1 Data Model — EMR Station Transitions

## Entity Change Summary

| Entity | Operation | Field | Type | Cardinality | Required | Revisionable |
|--------|-----------|-------|------|-------------|----------|--------------|
| `visit` | ADD | `current_station` | `list_string` | 1 | yes | yes |

No other entity changes. No new entity types. No new bundles.

## Field Detail: `visit.current_station`

```yaml
type: list_string
required: true
revisionable: true
default_value: registration
allowed_values:
  registration: Registration
  triage: Triage
  lab: Lab Orders & Results
  clinical: Clinical Evaluation
  pt: Physical Therapy
  pharmacy: Pharmacy Dispensing
  teaching: Teaching & Referrals
  complete: Complete            # terminal sentinel — set only by the terminal
                                # transition (Teaching → complete) and the
                                # reopen control's inverse path. Not a station;
                                # never offered by the picker.
display_options.form:
  type: options_select
  weight: 6           # immediately after status (weight 5)
  region: content     # initially in the main content area; later moved to hidden
                     # form display so the user doesn't see the dropdown (the
                     # station strip is the UX surface)
display_options.view:
  type: list_default
  weight: 6
```

**Implementation note**: After the base field is installed, we'll export the entity form display config (`core.entity_form_display.visit.visit.default.yml`) with `current_station` moved to `hidden` so the form strip + transition button become the only way to view/change the field via the UI. Admins retain ability to edit directly via `/admin/structure/visit/manage` if needed.

## State Diagram

```text
                       ┌── (skip if pt_referral=false) ──┐
                       │                                 ▼
registration → triage → lab → clinical → pt → pharmacy → teaching → complete (terminal sentinel)
                                                                          │  ▲
                                                          status flips    │  │ admin reopen with
                                                          to 'complete'   │  │ target-station picker
                                                          atomically      ▼  │ (FR-021)
                                                                       <any workflow station>
```

- The 7 workflow stations are reachable via the linear shortcut, the picker, or admin reopen.
- The `complete` value is a **terminal sentinel**, not a workflow station: only the terminal transition (Teaching → complete) and the reopen control's inverse write it.
- `pt` is the only conditional workflow station: from `clinical` the linear shortcut goes to `pt` iff `pt_referral = true`, else to `pharmacy`. The picker honors the user's explicit choice and does not apply this skip.
- From `pt`, the linear shortcut goes unconditionally to `pharmacy`.
- From `teaching`, the linear shortcut's terminal transition atomically sets `status = 'complete'` AND `current_station = 'complete'` in the same save.
- The reopen control (admin-only) on a completed visit writes `status = 'in_progress'` AND `current_station = {target}` where `{target}` is one of the 7 workflow stations chosen by the admin.

## StationWorkflow Contract (PHP)

```php
namespace Drupal\librechart_visit\Service;

interface StationWorkflowInterface {
  /**
   * Canonical ordered list of station machine names.
   *
   * @return array<int, string>
   */
  public function stations(): array;

  /**
   * Human-readable label for a station id.
   */
  public function label(string $station): string;

  /**
   * Given a visit, return the next station id, taking PT skip into account.
   * Returns null if the visit is already at the terminal station (teaching).
   *
   * Used by the linear "Send to next station" shortcut. The picker does
   * NOT call this method — it uses the user's explicit target.
   */
  public function nextStation(ContentEntityInterface $visit): ?string;

  /**
   * Role machine name that owns a given station (one role per station).
   */
  public function ownerRole(string $station): string;

  /**
   * Returns TRUE iff the user is allowed to transition the visit to the
   * given target station via the picker.
   *
   * Combines three checks:
   *  - $target is one of $this->stations() AND $target != current
   *  - $user has the owning role for the visit's current station
   *    OR has the `administer visit entities` permission
   *  - $visit->status is not 'complete'
   *
   * Used by the picker submit handler. Defense-in-depth: HTML form only
   * offers valid options; this method rejects tampered requests.
   */
  public function canTransitionTo(
    ContentEntityInterface $visit,
    string $target,
    AccountInterface $user,
  ): bool;
}
```

## Picker → Submit Handler Data Flow

```text
[ user selects target from <select> ]
              │
              ▼
form_state.values:
   station_picker = "<target_station_id>"
   visit          = <Visit entity>
              │
              ▼
librechart_visit_visit_picker_submit($form, $form_state):
   1. $visit  = $form_state->getFormObject()->getEntity();
   2. $target = $form_state->getValue('station_picker');
   3. $user   = \Drupal::currentUser();
   4. if (empty($target) || $target === $visit->get('current_station')->value):
        return;  // no-op
   5. if (!$workflow->canTransitionTo($visit, $target, $user)):
        $form_state->setErrorByName('station_picker', t('…'));
        \Drupal::logger('librechart_visit')->warning(…);
        return;
   6. $prior_label = $workflow->label($visit->get('current_station')->value);
   7. $new_label   = $workflow->label($target);
   8. $visit->set('current_station', $target);
   9. $visit->setRevisionLogMessage("Transitioned from $prior_label to $new_label by {username}.");
  10. $visit->setRevisionUser($user);
  11. $visit->save();
  12. \Drupal::messenger()->addStatus(t('Visit sent to @label.', ['@label' => $new_label]));
  13. \Drupal::logger('librechart_visit')->notice('picker transition: …');
  14. $form_state->setRedirect(<patient chart route>);
```

## Revision Log Format

When a transition happens, the Visit revision log message is set to:

```
Transitioned from {prior_label} to {new_label} by {username}.
```

…and `revision_user` is set to the acting user. The Visit entity already has `RevisionLogEntityTrait` and a revision metadata key for the user.

## Validation Rules

- `current_station` MUST be one of the eight defined allowed values (Drupal core enforces via list_string): the 7 workflow stations plus the terminal sentinel `complete`.
- Required on save (Drupal core enforces).
- The picker submit handler and `StationWorkflow::canTransitionTo()` MUST accept only the 7 workflow stations as targets; the `complete` sentinel is NOT a valid picker target. Defense-in-depth — the UI only renders the 7 workflow options; the submit handler additionally rejects the sentinel.
- The terminal transition (linear shortcut from `teaching`) MUST atomically set `status = 'complete'` AND `current_station = 'complete'` in the same save.
- The reopen submit handler (FR-021) MUST atomically set `status = 'in_progress'` AND `current_station = {chosen workflow station}` in the same save. Choosing the `complete` sentinel as a reopen target is impossible (not in the option list).
- The linear shortcut's PT-skip rule remains: `librechart_visit_visit_advance_submit()` MUST set `current_station = 'pharmacy'` when advancing from `clinical` with `pt_referral = false`. The picker does NOT apply this rule.

## Schema Migration

New column added via the entity field definition + a custom update hook in `librechart_visit.install`:

```php
function librechart_visit_update_9001(): void {
  $field = BaseFieldDefinition::create('list_string')
    ->setLabel(new TranslatableMarkup('Current Station'))
    ->setRequired(TRUE)
    ->setRevisionable(TRUE)
    ->setSetting('allowed_values', [
      'registration' => 'Registration',
      'triage' => 'Triage',
      'lab' => 'Lab Orders & Results',
      'clinical' => 'Clinical Evaluation',
      'pt' => 'Physical Therapy',
      'pharmacy' => 'Pharmacy Dispensing',
      'teaching' => 'Teaching & Referrals',
      'complete' => 'Complete',
    ])
    ->setDefaultValue('registration');

  \Drupal::entityDefinitionUpdateManager()
    ->installFieldStorageDefinition('current_station', 'visit', 'librechart_visit', $field);
}
```

After the field is installed, `scripts/backfill_current_station.php` populates existing rows.

## Backfill Rules

| Existing visit's `status` | Backfilled `current_station` |
|---------------------------|----------------------------|
| `complete` | `teaching` |
| `in_progress` (or any other) | `registration` |

Idempotent: backfill skips any row that already has a non-null `current_station`.

## Telemetry

| Event | Channel | Level | Payload |
|-------|---------|-------|---------|
| Successful linear-shortcut transition | (no extra log; revision message is the record) | — | — |
| Successful picker transition | watchdog (`librechart_visit` channel) | `notice` | `mechanism=picker, vid={vid}, from={prior}, to={target}, uid={uid}` |
| Rejected picker transition (tampered submit) | watchdog (`librechart_visit` channel) | `warning` | `mechanism=picker, vid={vid}, from={prior}, attempted={target}, uid={uid}, reason={validation_failure_class}` |

No new database tables; logs ride on the existing `watchdog` table. Picker-specific logging lets supervisors run a query to count out-of-order transitions ("how often are people deviating from the linear path?") without parsing revision logs.
