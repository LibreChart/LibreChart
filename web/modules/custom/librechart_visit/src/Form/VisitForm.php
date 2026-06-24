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
 * save rewrites the whole row. When two staff edit the same visit at once (or a
 * stale browser tab is saved after the patient has moved on), a plain save can
 * silently overwrite another station's work.
 *
 * To prevent that without locking anyone out, this form performs a per-field
 * three-way merge on save:
 *   - baseline  = the field value when this editor opened the form,
 *   - submitted = the value this editor is saving,
 *   - storage   = the current value in the database.
 * If the editor did not change a field (submitted == baseline), the value
 * currently in storage is adopted, preserving a concurrent edit by someone
 * else. Only when all three differ is there a true same-field collision; the
 * editor's value is kept and a warning names the field.
 *
 * The baseline is captured once, when the form is first built, and carried in
 * the form state (this form is cached because of its paragraph/inline-entity
 * widgets, so form-state storage survives the GET/POST cycle). The station
 * transition submit handlers in librechart_visit.module call
 * reconcileConcurrentChanges() too, so every save path is covered.
 */
class VisitForm extends ContentEntityForm {

  /**
   * Form-state key under which the loaded field baseline is stored.
   */
  protected const BASELINE_KEY = 'librechart_visit_baseline';

  /**
   * Fields the merge must not touch.
   *
   * These are entity keys, revision metadata, the optimistic-locking timestamp,
   * workflow state set explicitly by the transition handlers, computed values,
   * and the immutable patient reference. Everything else is editable clinical
   * data and is reconciled.
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
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    // Snapshot the loaded values once, on the initial build. On any later
    // rebuild (validation error, AJAX, autosave restore) the baseline is
    // already present and must not be overwritten with edited values.
    if ($form_state->get(self::BASELINE_KEY) === NULL) {
      $form_state->set(self::BASELINE_KEY, $this->captureBaseline());
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $this->reconcileAndWarn($form_state);
    return parent::save($form, $form_state);
  }

  /**
   * Reconciles concurrent edits and surfaces any collisions to the editor.
   *
   * Shared entry point for the default Save (above) and the station transition
   * submit handlers in librechart_visit.module, so every form save path merges
   * before persisting.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state holding the captured baseline.
   */
  public function reconcileAndWarn(FormStateInterface $form_state): void {
    $this->warnConcurrentConflicts($this->reconcileConcurrentChanges($form_state));
  }

  /**
   * Captures the loaded values of every merge-managed field.
   *
   * @return array<string, array>
   *   Field name keyed to its raw value array as loaded from storage.
   */
  protected function captureBaseline(): array {
    $entity = $this->entity;
    $baseline = [];
    foreach ($entity->getFields() as $name => $items) {
      if (in_array($name, self::UNMANAGED_FIELDS, TRUE)) {
        continue;
      }
      $baseline[$name] = $items->getValue();
    }
    return $baseline;
  }

  /**
   * Reconciles this editor's changes against concurrent edits in storage.
   *
   * Mutates the form entity in place: fields this editor left untouched adopt
   * the current storage value, so a concurrent edit by another station is not
   * lost. The optimistic-locking timestamp is aligned with storage so the
   * Visit::preSave() backstop passes for this reconciled save.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state holding the captured baseline.
   *
   * @return string[]
   *   Human-readable labels of fields with a true same-field collision.
   */
  public function reconcileConcurrentChanges(FormStateInterface $form_state): array {
    $entity = $this->entity;
    $baseline = $form_state->get(self::BASELINE_KEY);
    if ($entity->isNew() || !is_array($baseline)) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $current = $storage->loadUnchanged($entity->id());
    if (!$current instanceof ContentEntityInterface) {
      return [];
    }

    $conflicts = [];
    foreach ($baseline as $name => $baseline_value) {
      if (!$entity->hasField($name) || !$current->hasField($name)) {
        continue;
      }
      $submitted_value = $entity->get($name)->getValue();
      $storage_value = $current->get($name)->getValue();
      $user_changed = $submitted_value != $baseline_value;
      $other_changed = $storage_value != $baseline_value;

      if (!$user_changed) {
        // This editor did not touch the field; keep whatever is now in storage
        // so a concurrent edit by someone else survives this save.
        if ($other_changed) {
          $entity->set($name, $storage_value);
        }
      }
      elseif ($other_changed && $storage_value != $submitted_value) {
        // Both editors changed the same field to different values: keep this
        // editor's value but surface the collision.
        $conflicts[] = (string) $entity->get($name)->getFieldDefinition()->getLabel();
      }
    }

    // Adopt storage's changed timestamp so the preSave optimistic-lock backstop
    // sees a match; the changed field then bumps itself to now on save.
    if ($current->hasField('changed')) {
      $entity->set('changed', $current->get('changed')->value);
    }

    return $conflicts;
  }

  /**
   * Emits a warning message for each field with a same-field collision.
   *
   * @param string[] $conflicts
   *   Field labels that collided.
   */
  protected function warnConcurrentConflicts(array $conflicts): void {
    foreach ($conflicts as $label) {
      $this->messenger()->addWarning($this->t(
        'Another user changed %field at the same time. Your value was kept — please confirm it is correct.',
        ['%field' => $label],
      ));
    }
  }

}
