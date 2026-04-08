<?php

declare(strict_types=1);

namespace Drupal\librechart_visit\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the Visit content entity.
 *
 * Stores one clinical encounter per patient visit, with all station fields
 * as field groups. Implements optimistic locking via changed-timestamp check
 * on preSave() to prevent concurrent overwrites during busy clinic sessions.
 *
 * Visit completion status is informational only — station edits remain
 * possible after a visit is marked complete (FR-015b).
 */
#[ContentEntityType(
  id: 'visit',
  label: new TranslatableMarkup('Visit'),
  label_collection: new TranslatableMarkup('Visits'),
  label_singular: new TranslatableMarkup('visit'),
  label_plural: new TranslatableMarkup('visits'),
  label_count: [
    'singular' => '@count visit',
    'plural' => '@count visits',
  ],
  handlers: [
    'view_builder' => 'Drupal\Core\Entity\EntityViewBuilder',
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'form' => [
      'default' => 'Drupal\Core\Entity\ContentEntityForm',
      'add' => 'Drupal\Core\Entity\ContentEntityForm',
      'edit' => 'Drupal\Core\Entity\ContentEntityForm',
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
    'access' => 'Drupal\librechart_visit\VisitAccessControlHandler',
  ],
  base_table: 'visit',
  revision_table: 'visit_revision',
  revision_data_table: 'visit_field_revision',
  show_revision_ui: TRUE,
  translatable: FALSE,
  entity_keys: [
    'id' => 'vid',
    'revision' => 'revision_id',
    'label' => 'visit_date',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log_message',
  ],
  links: [
    'canonical' => '/visit/{visit}',
    'add-form' => '/visit/add',
    'edit-form' => '/visit/{visit}/edit',
    'delete-form' => '/visit/{visit}/delete',
    'collection' => '/admin/content/visits',
    'version-history' => '/visit/{visit}/revisions',
  ],
  admin_permission: 'administer visit entities',
)]
class Visit extends ContentEntityBase {

  use RevisionLogEntityTrait;

  /**
   * {@inheritdoc}
   *
   * Implements optimistic locking: if the changed timestamp in the database
   * differs from the value loaded into memory, another user has saved the
   * record since this user loaded it. Reject the save with a user-facing
   * message so the user can reload and re-enter their changes.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   When a concurrent modification is detected.
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Only check on updates, not on initial create.
    if (!$this->isNew() && $this->original instanceof self) {
      $db_changed = $this->original->get('changed')->value;
      $form_changed = $this->get('changed')->value;

      // If timestamps differ, another save occurred while this form was open.
      if ($db_changed !== $form_changed) {
        throw new EntityStorageException(
          (string) \Drupal::translation()->translate(
            'Record changed since you loaded it — please reload and re-enter your changes.'
          )
        );
      }
    }

    // Auto-calculate BMI when height and weight are present (US2).
    $height = (float) $this->get('vital_height')->value;
    $weight = (float) $this->get('vital_weight')->value;
    if ($height > 0 && $weight > 0) {
      $height_m = $height / 100;
      $bmi = round($weight / ($height_m ** 2), 2);
      $this->set('vital_bmi', $bmi);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);

    $fields['patient'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Patient'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'patient')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 1,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['visit_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Visit Date'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime')
      ->setDefaultValueCallback(static::class . '::getDefaultVisitDate')
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['patient_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Patient Type'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        'adult' => new TranslatableMarkup('Adult'),
        'pediatric' => new TranslatableMarkup('Pediatric'),
      ])
      ->setDefaultValue('adult')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 3,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['clinic_site'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Clinic Site'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['clinic_sites' => 'clinic_sites'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 4,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        'in_progress' => new TranslatableMarkup('In Progress'),
        'complete' => new TranslatableMarkup('Complete'),
      ])
      ->setDefaultValue('in_progress')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 5,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time the visit was last saved. Used for optimistic locking.'))
      ->setRevisionable(TRUE);

    // Triage fields (US2).
    $fields['vital_temperature'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Temperature'))
      ->setRevisionable(TRUE)
      ->setSetting('precision', 5)
      ->setSetting('scale', 1)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 20])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_decimal', 'weight' => 20])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['vital_pulse'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Pulse'))
      ->setRevisionable(TRUE)
      ->setSetting('min', 0)
      ->setSetting('max', 300)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 21])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_integer', 'weight' => 21])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['vital_respiration'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Respiration Rate'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 22])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_integer', 'weight' => 22])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['vital_systolic'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Systolic BP'))
      ->setRevisionable(TRUE)
      ->setSetting('min', 0)
      ->setSetting('max', 300)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 23])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_integer', 'weight' => 23])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['vital_diastolic'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Diastolic BP'))
      ->setRevisionable(TRUE)
      ->setSetting('min', 0)
      ->setSetting('max', 300)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 24])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_integer', 'weight' => 24])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['vital_height'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Height (cm)'))
      ->setRevisionable(TRUE)
      ->setSetting('precision', 5)
      ->setSetting('scale', 1)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 25])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_decimal', 'weight' => 25])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['vital_weight'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Weight (kg)'))
      ->setRevisionable(TRUE)
      ->setSetting('precision', 5)
      ->setSetting('scale', 1)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 26])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_decimal', 'weight' => 26])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['vital_bmi'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('BMI (auto-calculated)'))
      ->setRevisionable(TRUE)
      ->setSetting('precision', 5)
      ->setSetting('scale', 2)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 27])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_decimal', 'weight' => 27])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['complaint'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Chief Complaint'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 28])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'text_default', 'weight' => 28])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['past_medical_history'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Past Medical History'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 29])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'text_default', 'weight' => 29])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['allergies'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Allergies'))
      ->setRevisionable(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['allergies' => 'allergies'],
        'auto_create' => TRUE,
      ])
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => 30])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 30])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['current_medications'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Current Medications'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 31])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 31])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['pregnancy_history'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Pregnancy History'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 32])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'text_default', 'weight' => 32])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['lmp'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('LMP (Last Menstrual Period)'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 32)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 33])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 33])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['breastfeeding'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Breastfeeding'))
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        'yes' => new TranslatableMarkup('Yes'),
        'no' => new TranslatableMarkup('No'),
        'n_a' => new TranslatableMarkup('N/A'),
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 34])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'list_default', 'weight' => 34])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Clinical Evaluation fields (US4).
    $fields['clinical_notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Clinical Notes'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 50])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'text_default', 'weight' => 50])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['clinician_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Clinician Name'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 51])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 51])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['dx_write_in'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Diagnosis (Write-in)'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 52])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'text_default', 'weight' => 52])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['orders'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Orders'))
      ->setRevisionable(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['orders' => 'orders'],
      ])
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => 53])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 53])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['referrals'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Referrals'))
      ->setRevisionable(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['referrals' => 'referrals'],
      ])
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => 54])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 54])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['pt_referral'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('PT Referral'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 55])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'boolean', 'weight' => 55])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Body system boolean fields (US4).
    foreach ([
      'sys_cardiac' => 'Cardiac',
      'sys_derm' => 'Dermatology',
      'sys_endo' => 'Endocrinology',
      'sys_ent' => 'ENT',
      'sys_eye' => 'Eye',
      'sys_gi' => 'Gastrointestinal',
      'sys_gyn_ob' => 'GYN / OB',
      'sys_mental_health' => 'Mental Health',
      'sys_musculoskeletal' => 'Musculoskeletal',
      'sys_neuro' => 'Neurology',
      'sys_respiratory' => 'Respiratory',
      'sys_uro_genital' => 'Urogenital',
      'sys_vascular' => 'Vascular',
      'sys_wound_ostomy' => 'Wound / Ostomy',
    ] as $field_id => $label) {
      $fields[$field_id] = BaseFieldDefinition::create('boolean')
        ->setLabel(new TranslatableMarkup($label))
        ->setRevisionable(TRUE)
        ->setDefaultValue(FALSE)
        ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 60])
        ->setDisplayOptions('view', ['label' => 'above', 'type' => 'boolean', 'weight' => 60])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    }

    // Diagnosis entity reference fields per body system (US4).
    $dx_fields = [
      'dx_chronic_diseases' => ['chronic_diseases', 'Chronic Diseases Diagnoses'],
      'dx_cardiac' => ['cardiac_thoracic', 'Cardiac Diagnoses'],
      'dx_derm' => ['derm', 'Dermatology Diagnoses'],
      'dx_endo' => ['endo', 'Endocrinology Diagnoses'],
      'dx_ent' => ['ent', 'ENT Diagnoses'],
      'dx_eye' => ['eye', 'Eye Diagnoses'],
      'dx_gi' => ['gi', 'GI Diagnoses'],
      'dx_gyn_ob' => ['gyn_ob', 'GYN/OB Diagnoses'],
      'dx_mental_health' => ['mental_health', 'Mental Health Diagnoses'],
      'dx_muscular_skeletal' => ['muscular_skeletal', 'Musculoskeletal Diagnoses'],
      'dx_neuro' => ['neuro', 'Neurology Diagnoses'],
      'dx_opthalmic_otic' => ['opthalmic_otic', 'Ophthalmic/Otic Diagnoses'],
      'dx_resp' => ['resp', 'Respiratory Diagnoses'],
      'dx_uro_genital' => ['uro_genital', 'Urogenital Diagnoses'],
      'dx_vascular' => ['vascular', 'Vascular Diagnoses'],
      'dx_wound_ostomy' => ['wound_ostomy', 'Wound/Ostomy Diagnoses'],
      'dx_pain' => ['pain_management', 'Pain Management Diagnoses'],
      'dx_pt_treatment' => ['physical_therapy_treatment', 'PT Treatment Diagnoses'],
      'dx_vitamins' => ['vitamins_nutrients_lv', 'Vitamins/Nutrients'],
      'dx_anti_infective' => ['anti_infective_agents', 'Anti-Infective Agents'],
      'dx_misc' => ['miscellaneous', 'Miscellaneous Diagnoses'],
    ];

    $dx_weight = 70;
    foreach ($dx_fields as $field_id => [$vocab, $label]) {
      $fields[$field_id] = BaseFieldDefinition::create('entity_reference')
        ->setLabel(new TranslatableMarkup($label))
        ->setRevisionable(TRUE)
        ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
        ->setSetting('target_type', 'taxonomy_term')
        ->setSetting('handler', 'default:taxonomy_term')
        ->setSetting('handler_settings', [
          'target_bundles' => [$vocab => $vocab],
        ])
        ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => $dx_weight])
        ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => $dx_weight])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
      $dx_weight++;
    }

    // Physical Therapy fields (US7).
    $fields['pt_notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('PT Notes'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 95])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'text_default', 'weight' => 95])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['pt_interventions'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('PT Interventions'))
      ->setRevisionable(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['physical_therapy_treatment' => 'physical_therapy_treatment'],
      ])
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => 96])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 96])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['pt_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Physical Therapist Name'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 97])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 97])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Teaching & Referrals fields (US8).
    $fields['teaching_topics'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Teaching Topics'))
      ->setRevisionable(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['teaching_topics' => 'teaching_topics'],
      ])
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => 100])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 100])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['external_referral'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('External Referral'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 101])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 101])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['diagnostic_referral'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Diagnostic Referral'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 102])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 102])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Pharmacy fields (US5).
    $fields['pharmacist_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Pharmacist Name'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 110])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 110])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['notes_to_pharmacist'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Notes to Pharmacist'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 111])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'text_default', 'weight' => 111])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Default value callback for visit_date field.
   *
   * @return array<int, array<string, int>>
   *   Default visit date value as today's timestamp.
   */
  public static function getDefaultVisitDate(): array {
    return [['value' => \Drupal::time()->getRequestTime()]];
  }

}
