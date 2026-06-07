<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the NonFormularyMedication content entity.
 *
 * An order for a medication the clinic does not stock, to be filled at an
 * outside hospital or clinic. Embedded per-visit via Inline Entity Form. These
 * orders are deliberately separate from PrescriptionItem: they hold a free-text
 * drug name (not a stocked-drug reference), carry no inventory hooks or
 * constraints, and never appear in the inventory report.
 */
#[ContentEntityType(
  id: 'non_formulary_medication',
  label: new TranslatableMarkup('Non-Formulary Medication'),
  label_collection: new TranslatableMarkup('Non-Formulary Medications'),
  label_singular: new TranslatableMarkup('non-formulary medication'),
  label_plural: new TranslatableMarkup('non-formulary medications'),
  label_count: [
    'singular' => '@count non-formulary medication',
    'plural' => '@count non-formulary medications',
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
    'access' => 'Drupal\librechart_pharmacy\NonFormularyMedicationAccessControlHandler',
  ],
  base_table: 'non_formulary_medication',
  entity_keys: [
    'id' => 'id',
    'label' => 'drug_name',
    'uuid' => 'uuid',
  ],
  links: [
    'canonical' => '/non-formulary-medication/{non_formulary_medication}',
    'add-form' => '/non-formulary-medication/add',
    'edit-form' => '/non-formulary-medication/{non_formulary_medication}/edit',
    'delete-form' => '/non-formulary-medication/{non_formulary_medication}/delete',
    'collection' => '/admin/content/non-formulary-medications',
  ],
  admin_permission: 'administer non_formulary_medication entities',
)]
class NonFormularyMedication extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['drug_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Drug name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['quantity'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Quantity'))
      ->setSetting('min', 0)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 1])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_integer', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['strength'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Strength'))
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 2])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['frequency'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Frequency'))
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 3])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

}
