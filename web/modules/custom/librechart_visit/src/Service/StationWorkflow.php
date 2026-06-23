<?php

declare(strict_types=1);

namespace Drupal\librechart_visit\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Canonical workflow logic for Visit station transitions.
 *
 * Encapsulates the station list, labels, owner roles, linear next-station
 * rule (with PT-skip), and picker validation. Pure helpers — no entity saves;
 * callers (form submit handlers) own persistence.
 */
final class StationWorkflow {

  use StringTranslationTrait;

  /**
   * Workflow station ids in canonical order.
   *
   * Excludes the `complete` sentinel.
   *
   * @var list<string>
   */
  private const WORKFLOW_STATIONS = [
    'registration',
    'triage',
    'lab',
    'clinical',
    'pt',
    'optometry',
    'education',
    'pharmacy',
    'discharge',
  ];

  /**
   * Terminal sentinel value set by the terminal transition.
   *
   * Never returned by stations() or workflowStations().
   */
  public const COMPLETE_SENTINEL = 'complete';

  /**
   * Returns all valid `current_station` values.
   *
   * 7 workflow stations plus the terminal sentinel.
   *
   * @return list<string>
   *   Station ids in canonical order; the sentinel is last.
   */
  public function stations(): array {
    return [...self::WORKFLOW_STATIONS, self::COMPLETE_SENTINEL];
  }

  /**
   * Returns only the 7 workflow stations.
   *
   * Used by the picker option list and the reopen control's target list.
   *
   * @return list<string>
   *   Workflow station ids in canonical order.
   */
  public function workflowStations(): array {
    return self::WORKFLOW_STATIONS;
  }

  /**
   * Returns the human-readable label for a station id.
   *
   * Falls back to the id itself for unknown values (defensive — should not
   * happen in normal flow).
   */
  public function label(string $station): string {
    $labels = [
      'registration' => (string) $this->t('Registration'),
      'triage' => (string) $this->t('Triage'),
      'lab' => (string) $this->t('Lab Orders & Results'),
      'clinical' => (string) $this->t('Clinical Evaluation'),
      'pt' => (string) $this->t('Physical Therapy'),
      'optometry' => (string) $this->t('Optometry'),
      'pharmacy' => (string) $this->t('Pharmacy Dispensing'),
      'education' => (string) $this->t('Education'),
      'discharge' => (string) $this->t('Discharge'),
      self::COMPLETE_SENTINEL => (string) $this->t('Complete'),
    ];
    return $labels[$station] ?? $station;
  }

  /**
   * Returns the role machine name that owns a given station.
   *
   * One role per station per contracts/station-transitions.md. Returns an
   * empty string for the `complete` sentinel.
   */
  public function ownerRole(string $station): string {
    return match ($station) {
      'registration' => 'registration_staff',
      'triage' => 'triage_nurse',
      'lab' => 'lab_technician',
      'clinical' => 'clinician',
      'pt' => 'physical_therapist',
      'pharmacy' => 'pharmacist',
      'education' => 'teaching_coordinator',
      'discharge' => 'discharge_staff',
      default => '',
    };
  }

  /**
   * Returns the next station for the linear "Send to next station" shortcut.
   *
   * Applies the PT-skip rule for clinical→education when pt_referral is false.
   * Returns NULL if the visit is already at the terminal workflow station
   * (`discharge`) — the caller (advance submit handler) then writes the
   * `complete` sentinel and the status field.
   *
   * Not used by the picker — the picker honors the user's exact choice
   * without applying any skip rules.
   */
  public function nextStation(ContentEntityInterface $visit): ?string {
    $current = (string) $visit->get('current_station')->value;
    return match ($current) {
      'registration' => 'triage',
      'triage' => 'lab',
      'lab' => 'clinical',
      'clinical' => ((bool) $visit->get('pt_referral')->value) ? 'pt' : 'education',
      'pt' => 'education',
      // Optometry is an opt-in side station: no rule routes into it (the linear
      // flow never auto-advances here), so it is reached only via the station
      // picker. When a visit is manually at optometry, "next" exits to
      // education — its position in the canonical order.
      'optometry' => 'education',
      'education' => 'pharmacy',
      'pharmacy' => 'discharge',
      'discharge' => NULL,
      default => NULL,
    };
  }

  /**
   * Validates whether the user may transition a visit via the picker.
   *
   * Implements FR-013, FR-014, FR-015, FR-019 by combining four checks:
   *   1. $target is one of workflowStations()  — the `complete` sentinel is
   *      explicitly rejected; only workflow stations are picker targets.
   *   2. $target != the visit's current_station — same-station is a no-op
   *      and excluded from the picker UI.
   *   3. $user may edit the visit ($visit->access('update')). Moving a patient
   *      between stations is open to any staff who can edit visits; per-station
   *      field-edit access (hook_entity_field_access) keeps clinical data
   *      locked to the owning role.
   *   4. The visit's status is not 'complete' — completed visits use the
   *      reopen control instead.
   *
   * Pure validation; no entity save. Defense-in-depth: the picker UI only
   * offers valid options, but tampered submissions are still caught here.
   */
  public function canTransitionTo(
    ContentEntityInterface $visit,
    string $target,
    AccountInterface $user,
  ): bool {
    if (!in_array($target, self::WORKFLOW_STATIONS, TRUE)) {
      return FALSE;
    }
    $current = (string) $visit->get('current_station')->value;
    if ($target === $current) {
      return FALSE;
    }
    if ((string) $visit->get('status')->value === 'complete') {
      return FALSE;
    }
    // Any user who may edit the visit can move it between stations. Per-station
    // clinical field-edit access is enforced separately in
    // hook_entity_field_access, so this does not loosen who can change data.
    return $visit->access('update', $user);
  }

}
