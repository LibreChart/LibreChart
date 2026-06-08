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
   * Starter specialties seeded into the `specialties` vocabulary.
   *
   * Maps the term name to its default Floor-board color (hex). Admins can add
   * more specialties and edit colors through the taxonomy UI after install, so
   * this list only bootstraps a fresh or upgrading site.
   *
   * @var array<string, string>
   */
  public const SPECIALTY_SEED = [
    'GP' => '#2563eb',
    'GYN' => '#db2777',
    'Pediatrics' => '#16a34a',
    'Wounds' => '#ea580c',
  ];

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

    // BMI is always derived from height (cm) and weight (kg); it is never
    // entered by hand (the form input is disabled). Recalculate on every save
    // and clear it when either input is missing so a stale value cannot
    // persist (US2).
    $height = (float) $this->get('vital_height')->value;
    $weight = (float) $this->get('vital_weight')->value;
    if ($height > 0 && $weight > 0) {
      $height_m = $height / 100;
      $this->set('vital_bmi', round($weight / ($height_m ** 2), 2));
    }
    else {
      $this->set('vital_bmi', NULL);
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

    $fields['current_station'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Current Station'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        'registration' => new TranslatableMarkup('Registration'),
        'triage' => new TranslatableMarkup('Triage'),
        'lab' => new TranslatableMarkup('Lab Orders & Results'),
        'clinical' => new TranslatableMarkup('Clinical Evaluation'),
        'pt' => new TranslatableMarkup('Physical Therapy'),
        'pharmacy' => new TranslatableMarkup('Pharmacy Dispensing'),
        'education' => new TranslatableMarkup('Education'),
        'discharge' => new TranslatableMarkup('Discharge'),
        'complete' => new TranslatableMarkup('Complete'),
      ])
      ->setDefaultValue('registration')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 6,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 6,
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
      ->setRequired(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['allergies' => 'allergies'],
        'auto_create' => TRUE,
      ])
      ->setDefaultValueCallback(static::class . '::getDefaultAllergies')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => 30])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 30])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Clinical specialties the patient will be seen by (selected at triage).
    // Each referenced term carries a color used on the Floor board; the first
    // referenced term is treated as the patient's primary specialty.
    $fields['specialties'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Specialties'))
      ->setDescription(new TranslatableMarkup('Specialties this patient will be evaluated by in the clinic.'))
      ->setRevisionable(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['specialties' => 'specialties'],
      ])
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => 32])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 32])
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

    $fields['pt_referral'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('PT Referral'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 55])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'boolean', 'weight' => 55])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Diagnosis entity reference fields per body system (US4). Each references
    // a taxonomy vocabulary of conditions for that system.
    $dx_fields = [
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
      'dx_resp' => ['resp', 'Respiratory Diagnoses'],
      'dx_uro_genital' => ['uro_genital', 'Urogenital Diagnoses'],
      'dx_vascular' => ['vascular', 'Vascular Diagnoses'],
      'dx_wound_ostomy' => ['wound_ostomy', 'Wound/Ostomy Diagnoses'],
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

    // Pharmacy fields. The standalone pharmacist_name field was removed in
    // feature 005 (replaced by the per-medication "Fulfilled by"); notes to
    // pharmacist is a plain text area (string_long), not a rich-text field.
    $fields['notes_to_pharmacist'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes to Pharmacist'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 111])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 111])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Medications: per-visit prescription line items, embedded via Inline
    // Entity Form in the Pharmacy section (feature 005). Reuses the existing
    // PrescriptionItem entity so inventory hooks and reports keep working.
    $fields['medications'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Medications'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'prescription_item')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'inline_entity_form_complex',
        'weight' => 112,
        'settings' => [
          'allow_new' => TRUE,
          'allow_existing' => FALSE,
          'allow_duplicate' => FALSE,
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 112,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Non-formulary medications: orders for drugs the clinic does not stock,
    // to be filled at an outside hospital or clinic (free-text name, quantity,
    // strength, frequency). A separate entity from PrescriptionItem so these
    // never touch drug inventory or the inventory report.
    $fields['non_formulary_medications'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Non-formulary medications'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'non_formulary_medication')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'inline_entity_form_complex',
        'weight' => 113,
        'settings' => [
          'allow_new' => TRUE,
          'allow_existing' => FALSE,
          'allow_duplicate' => FALSE,
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 113,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Education referrals: repeating education-class referrals with a per-item
    // Complete flag (feature 005). Modeled as paragraphs, wired up in the
    // renamed Education group (US4).
    $fields['education_referrals'] = BaseFieldDefinition::create('entity_reference_revisions')
      ->setLabel(new TranslatableMarkup('Education referrals'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'paragraph')
      ->setSetting('handler', 'default:paragraph')
      ->setSetting('handler_settings', [
        'target_bundles' => ['education_referral' => 'education_referral'],
        'negate' => 0,
      ])
      ->setDisplayOptions('form', ['type' => 'paragraphs', 'weight' => 60])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_revisions_entity_view',
        'weight' => 60,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // POS: referral to a local provider (feature 005). When checked, a
    // conditional text area (pos_details) is revealed in the Education group.
    $fields['pos'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('POS'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 70])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'boolean', 'weight' => 70])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['pos_details'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('POS details'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 71])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 71])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Discharge fields: the final station where the patient is checked out,
    // final paperwork is given, and vision aids are handed out. Editable only
    // by discharge_staff.
    $fields['discharge_sunglasses'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Sunglasses provided'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 80])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'boolean', 'weight' => 80])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['discharge_readers'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Reading glasses provided'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 81])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'boolean', 'weight' => 81])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['discharge_notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Discharge notes'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 82])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 82])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Default value callback for visit_date field.
   *
   * @return array<int, array<string, string>>
   *   Default visit date as today's date in the datetime field's ISO format.
   */
  public static function getDefaultVisitDate(): array {
    // Store using the datetime field's ISO storage format (UTC), not a raw Unix
    // timestamp, so the value parses, sorts, and renders correctly.
    return [
      ['value' => gmdate('Y-m-d\TH:i:s', \Drupal::time()->getRequestTime())],
    ];
  }

  /**
   * Default value callback for the allergies field.
   *
   * Allergies are required (feature 005); a new visit defaults to the "None"
   * term so the field is satisfied without implying an unrecorded allergy.
   *
   * @return array<int, array<string, int>>
   *   A default value referencing the "None" allergy term.
   */
  public static function getDefaultAllergies(): array {
    return [['target_id' => static::getNoneAllergyTermId()]];
  }

  /**
   * Returns the id of the "None" allergy term, creating it if absent.
   *
   * The id (not the label) is the stable handle used both for the default value
   * and for excluding "None" from the sticky allergy display, so the behaviour
   * survives translation of the term's name.
   *
   * @return int
   *   The "None" allergy term id.
   */
  public static function getNoneAllergyTermId(): int {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $existing = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'allergies')
      ->condition('name', 'None')
      ->range(0, 1)
      ->execute();
    if (!empty($existing)) {
      return (int) reset($existing);
    }
    $term = $storage->create(['vid' => 'allergies', 'name' => 'None']);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Seeds the starter specialty terms with their default colors.
   *
   * Idempotent: a starter term is created only when no term of that name
   * already exists in the `specialties` vocabulary, so re-running (fresh
   * install plus update hook) never duplicates terms. Existing terms are left
   * untouched so admin color edits are preserved.
   *
   * @see \Drupal\librechart_visit\Entity\Visit::SPECIALTY_SEED
   */
  public static function seedSpecialties(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    foreach (static::SPECIALTY_SEED as $name => $color) {
      $existing = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', 'specialties')
        ->condition('name', $name)
        ->range(0, 1)
        ->execute();
      if (!empty($existing)) {
        continue;
      }
      $storage->create([
        'vid' => 'specialties',
        'name' => $name,
        'field_color' => $color,
      ])->save();
    }
  }

}
