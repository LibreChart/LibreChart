<?php

declare(strict_types=1);

namespace Drupal\librechart_visit\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Edit form for Visit entities with non-destructive concurrency handling.
 *
 * The Visit is a single "fat" entity holding every station's fields, so each
 * save rewrites the whole row. When two staff edit the same visit at once — or
 * a stale browser tab is saved after the patient has moved on — a plain save
 * can silently overwrite another station's work, because the form carries the
 * field values as they were when it was opened.
 *
 * Field-edit access is already partitioned by role in
 * librechart_visit_entity_field_access(): a triage nurse may edit triage
 * fields, a clinician the clinical fields, and so on. This form leans on that
 * partition. On save, every field the current user is *not* permitted to edit
 * is reset to the value currently in storage, so the stale copy this form is
 * carrying can never overwrite a concurrent edit made by the owning station.
 * Fields the user may edit are saved as submitted.
 *
 * This is deliberately a value-free reconciliation: it never diffs field
 * values (which is unreliable for paragraphs, formatted text, and multi-value
 * fields, whose stored representation changes between load and save even when
 * untouched), so it raises no spurious "another user changed this" warnings.
 * Administrators bypass field-access restrictions and so are unaffected — their
 * saves behave exactly as before.
 *
 * The station transition submit handlers in librechart_visit.module call
 * protectConcurrentEdits() too, so every form save path is covered.
 */
class VisitForm extends ContentEntityForm {

  /**
   * Fields the reconciliation must not touch.
   *
   * These are entity keys, revision metadata, the optimistic-locking timestamp,
   * workflow state set explicitly by the transition handlers, computed values,
   * and the immutable patient reference. Everything else is role-owned clinical
   * data governed by librechart_visit_entity_field_access().
   *
   * @var string[]
   */
  protected const UNMANAGED_FIELDS = [
    'vid',
    'uuid',
    'langcode',
    'default_langcode',
    'revision_id',
    'revision_uid',
    'revision_timestamp',
    'revision_log_message',
    'revision_default',
    'revision_translation_affected',
    'changed',
    'patient',
    'current_station',
    'status',
    'station_entered',
    'vital_bmi',
    'patient_type',
  ];

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $this->protectConcurrentEdits();
    return parent::save($form, $form_state);
  }

  /**
   * Resets fields the current user may not edit back to their stored values.
   *
   * Shared entry point for the default Save (above) and the station transition
   * submit handlers in librechart_visit.module, so every form save path is
   * reconciled before the entity is persisted. Mutates the form entity in
   * place.
   */
  public function protectConcurrentEdits(): void {
    $entity = $this->entity;
    if ($entity->isNew()) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $current = $storage->loadUnchanged($entity->id());
    if (!$current instanceof ContentEntityInterface) {
      return;
    }

    $account = $this->currentUser();
    foreach ($entity->getFields() as $name => $items) {
      if (in_array($name, self::UNMANAGED_FIELDS, TRUE) || !$current->hasField($name)) {
        continue;
      }
      // A field this user cannot edit at their station must not be written from
      // this form's (possibly stale) copy. Adopt the current stored value so a
      // concurrent edit by the owning station is preserved.
      if (!$items->access('edit', $account)) {
        $entity->set($name, $current->get($name)->getValue());
      }
    }

    // Station issue-flags are toggled out-of-band from the station strip, never
    // through this form. Always adopt the stored set so a form built before a
    // flag was toggled does not silently revert it on save.
    if ($current->hasField('flagged_stations')) {
      $entity->set('flagged_stations', $current->get('flagged_stations')->getValue());
    }

    // Align the optimistic-lock timestamp with storage so Visit::preSave()'s
    // backstop passes for this reconciled save; the changed field then bumps
    // itself to now on save.
    if ($current->hasField('changed')) {
      $entity->set('changed', $current->get('changed')->value);
    }
  }

}
