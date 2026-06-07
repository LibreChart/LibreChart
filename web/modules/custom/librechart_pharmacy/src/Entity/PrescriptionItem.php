<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the PrescriptionItem content entity.
 *
 * One record per drug dispensed in a clinical visit. Multiple PrescriptionItems
 * can be attached to a single Visit, each with its own dosage, quantity, and
 * fill status. Inventory is decremented on fill via postsave hook.
 */
#[ContentEntityType(
  id: 'prescription_item',
  label: new TranslatableMarkup('Prescription Item'),
  label_collection: new TranslatableMarkup('Prescription Items'),
  label_singular: new TranslatableMarkup('prescription item'),
  label_plural: new TranslatableMarkup('prescription items'),
  label_count: [
    'singular' => '@count prescription item',
    'plural' => '@count prescription items',
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
    'access' => 'Drupal\librechart_pharmacy\PrescriptionItemAccessControlHandler',
  ],
  base_table: 'prescription_item',
  entity_keys: [
    'id' => 'id',
    'label' => 'drug',
    'uuid' => 'uuid',
  ],
  links: [
    'canonical' => '/prescription-item/{prescription_item}',
    'add-form' => '/prescription-item/add',
    'edit-form' => '/prescription-item/{prescription_item}/edit',
    'delete-form' => '/prescription-item/{prescription_item}/delete',
    'collection' => '/admin/content/prescription-items',
  ],
  admin_permission: 'administer prescription_item entities',
)]
class PrescriptionItem extends ContentEntityBase {

  /**
   * {@inheritdoc}
   *
   * The `drug` entity key is an entity reference, so the default label logic
   * would surface the raw term id. Resolve the referenced drug term's name
   * instead so the medication reads clearly in the Visit form and listings.
   */
  public function label() {
    $drug = $this->get('drug')->entity;
    if ($drug !== NULL) {
      return $drug->label();
    }
    return parent::label();
  }

  /**
   * Default value callback for the `fulfilled_by` field.
   *
   * Pre-selects the Pharmacist term whose name matches the logged-in
   * pharmacist, so the dispenser is recorded with one fewer click while
   * remaining editable (feature 005, Clarification Q3).
   *
   * @return array<int, array<string, int>>
   *   A default value structure for the field, or an empty array when no
   *   matching pharmacist term exists.
   */
  public static function getDefaultFulfilledBy(): array {
    $account = \Drupal::currentUser();
    if (!in_array('pharmacist', $account->getRoles(), TRUE)) {
      return [];
    }
    $tids = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'pharmacist')
      ->condition('name', $account->getDisplayName())
      ->range(0, 1)
      ->execute();
    return $tids ? [['target_id' => (int) reset($tids)]] : [];
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Not required at the field level: when a prescription is created through
    // the Visit form (Inline Entity Form), the parent visit sets this
    // back-reference on save (feature 005). Standalone creation may leave it
    // empty until linked.
    $fields['visit'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Visit'))
      ->setSetting('target_type', 'visit')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 1])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['drug'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Drug'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 2])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['drug_category'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Drug Category'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 3])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Dosage and frequency are patient-instruction fields only: the pharmacist
    // records how the patient should take the medication. They carry no
    // inventory effect.
    $fields['dosage'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Dosage'))
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 4])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['frequency'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Frequency'))
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 5])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['quantity_dispensed'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Quantity'))
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 5])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_integer', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['prescription_filled'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Fulfilled'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 6])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'boolean', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // The dispensing pharmacist (feature 005). Defaults to the logged-in
    // pharmacist's matching term, editable. Edit access is restricted to the
    // Pharmacist role via hook_entity_field_access().
    $fields['fulfilled_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Fulfilled by'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['pharmacist' => 'pharmacist']])
      ->setDefaultValueCallback(static::class . '::getDefaultFulfilledBy')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 7])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 7])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['dispensed_by'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Dispensed By'))
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 7])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 7])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['override_reason'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Override Reason'))
      ->setDescription(new TranslatableMarkup('Required when dispensing a drug with insufficient inventory.'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 8])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'text_default', 'weight' => 8])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

}
