<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the InventoryReceipt content entity.
 *
 * Records a stock addition event for a drug at a clinic site. When saved,
 * a postsave hook increments the corresponding DrugInventory.quantity_on_hand.
 */
#[ContentEntityType(
  id: 'inventory_receipt',
  label: new TranslatableMarkup('Inventory Receipt'),
  label_collection: new TranslatableMarkup('Inventory Receipts'),
  label_singular: new TranslatableMarkup('inventory receipt'),
  label_plural: new TranslatableMarkup('inventory receipts'),
  label_count: [
    'singular' => '@count inventory receipt',
    'plural' => '@count inventory receipts',
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
    'access' => 'Drupal\librechart_pharmacy\InventoryReceiptAccessControlHandler',
  ],
  base_table: 'inventory_receipt',
  entity_keys: [
    'id' => 'id',
    'label' => 'drug',
    'uuid' => 'uuid',
  ],
  links: [
    'canonical' => '/inventory-receipt/{inventory_receipt}',
    'add-form' => '/inventory-receipt/add',
    'edit-form' => '/inventory-receipt/{inventory_receipt}/edit',
    'delete-form' => '/inventory-receipt/{inventory_receipt}/delete',
    'collection' => '/admin/content/inventory-receipts',
  ],
  admin_permission: 'administer inventory_receipt entities',
)]
class InventoryReceipt extends ContentEntityBase {

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

    $fields['quantity_received'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Quantity Received'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 3])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_integer', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['receipt_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Receipt Date'))
      ->setSetting('datetime_type', 'datetime')
      ->setDefaultValueCallback(static::class . '::getDefaultReceiptDate')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 4])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'datetime_default', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['received_by'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Received By'))
      ->setSetting('max_length', 256)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 5])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 6])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'text_default', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

  /**
   * Default value callback for receipt_date.
   *
   * @return array<int, array<string, int>>
   *   Default receipt date as current timestamp.
   */
  public static function getDefaultReceiptDate(): array {
    return [['value' => \Drupal::time()->getRequestTime()]];
  }

}
