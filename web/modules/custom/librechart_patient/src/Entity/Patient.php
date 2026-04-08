<?php

declare(strict_types=1);

namespace Drupal\librechart_patient\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the Patient content entity.
 *
 * Stores patient demographic information with full revision history.
 * Each demographic change creates a new revision, preserving audit trail.
 */
#[ContentEntityType(
  id: 'patient',
  label: new TranslatableMarkup('Patient'),
  label_collection: new TranslatableMarkup('Patients'),
  label_singular: new TranslatableMarkup('patient'),
  label_plural: new TranslatableMarkup('patients'),
  label_count: [
    'singular' => '@count patient',
    'plural' => '@count patients',
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
    'access' => 'Drupal\librechart_patient\PatientAccessControlHandler',
  ],
  base_table: 'patient',
  revision_table: 'patient_revision',
  revision_data_table: 'patient_field_revision',
  show_revision_ui: TRUE,
  translatable: FALSE,
  entity_keys: [
    'id' => 'pid',
    'revision' => 'vid',
    'label' => 'last_name',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log_message',
  ],
  links: [
    'canonical' => '/patient/{patient}',
    'add-form' => '/patient/add',
    'edit-form' => '/patient/{patient}/edit',
    'delete-form' => '/patient/{patient}/delete',
    'collection' => '/admin/content/patients',
    'revision' => '/patient/{patient}/revisions/{patient_revision}/view',
    'revision-revert-form' => '/patient/{patient}/revisions/{patient_revision}/revert',
    'revision-delete-form' => '/patient/{patient}/revisions/{patient_revision}/delete',
    'version-history' => '/patient/{patient}/revisions',
  ],
  admin_permission: 'administer patient entities',
)]
class Patient extends ContentEntityBase implements PatientInterface {

  use RevisionLogEntityTrait;

  /**
   * {@inheritdoc}
   */
  public function getFirstName(): string {
    return (string) $this->get('first_name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getLastName(): string {
    return (string) $this->get('last_name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCedula(): string {
    return (string) $this->get('cedula')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDateOfBirth(): string {
    return (string) $this->get('date_of_birth')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSex(): string {
    return (string) $this->get('sex')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getMunicipality(): ?int {
    $ref = $this->get('municipality')->target_id;
    return $ref !== NULL ? (int) $ref : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getVillage(): ?int {
    $ref = $this->get('village_town')->target_id;
    return $ref !== NULL ? (int) $ref : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);

    $fields['first_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('First Name'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['last_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Last Name'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 11,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['date_of_birth'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Date of Birth'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 12,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'weight' => 12,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['sex'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Sex'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        'male' => new TranslatableMarkup('Male'),
        'female' => new TranslatableMarkup('Female'),
        'other' => new TranslatableMarkup('Other'),
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 13,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 13,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['cedula'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Cédula'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 14,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 14,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['municipality'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Municipality'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['municipalities' => 'municipalities'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 15,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['village_town'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Village / Town'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['village_town' => 'village_town'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 16,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 16,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
