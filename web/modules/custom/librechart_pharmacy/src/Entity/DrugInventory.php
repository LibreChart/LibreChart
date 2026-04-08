<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the DrugInventory content entity.
 *
 * Tracks current on-hand quantity of each drug at each clinic site.
 * One record per unique (drug, clinic_site) combination. Updated by
 * PrescriptionItem fill hooks and InventoryReceipt save hooks.
 */
#[ContentEntityType(
  id: 'drug_inventory',
  label: new TranslatableMarkup('Drug Inventory'),
  label_collection: new TranslatableMarkup('Drug Inventory Records'),
  label_singular: new TranslatableMarkup('drug inventory record'),
  label_plural: new TranslatableMarkup('drug inventory records'),
  label_count: [
    'singular' => '@count drug inventory record',
    'plural' => '@count drug inventory records',
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
    'access' => 'Drupal\librechart_pharmacy\DrugInventoryAccessControlHandler',
  ],
  base_table: 'drug_inventory',
  entity_keys: [
    'id' => 'id',
    'label' => 'drug',
    'uuid' => 'uuid',
  ],
  links: [
    'canonical' => '/drug-inventory/{drug_inventory}',
    'add-form' => '/drug-inventory/add',
    'edit-form' => '/drug-inventory/{drug_inventory}/edit',
    'delete-form' => '/drug-inventory/{drug_inventory}/delete',
    'collection' => '/admin/content/drug-inventory',
  ],
  admin_permission: 'administer drug_inventory entities',
)]
class DrugInventory extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['drug'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Drug'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 1])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['clinic_site'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Clinic Site'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['clinic_sites' => 'clinic_sites'],
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 2])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['quantity_on_hand'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Quantity On Hand'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 3])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_integer', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['low_stock_threshold'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Low Stock Threshold'))
      ->setDefaultValue(10)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 4])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_integer', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['unit'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Unit'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 5])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
