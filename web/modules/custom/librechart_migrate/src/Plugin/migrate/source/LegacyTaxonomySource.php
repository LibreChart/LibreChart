<?php

declare(strict_types=1);

namespace Drupal\librechart_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * Source plugin for importing taxonomy terms from the legacy OpenEMR database.
 *
 * Reads terms from a configurable table in the legacy database connection
 * (`$databases['migrate']['default']` in settings.php). Each migration YAML
 * configures the source_table and field mappings to handle vocabulary-specific
 * table structures in the legacy system.
 *
 * @code
 * source:
 *   plugin: librechart_legacy_taxonomy
 *   source_table: list_options
 *   id_field: option_id
 *   label_field: title
 *   filter_field: list_id
 *   filter_value: 'ALLERGY'
 * @endcode
 */
#[MigrateSource(
  id: 'librechart_legacy_taxonomy',
  source_module: 'librechart_migrate',
)]
class LegacyTaxonomySource extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query(): SelectInterface {
    $config = $this->configuration;
    $table = $config['source_table'] ?? 'list_options';
    $id_field = $config['id_field'] ?? 'id';
    $label_field = $config['label_field'] ?? 'title';

    $query = $this->select($table, 't')
      ->fields('t', [$id_field, $label_field]);

    if (!empty($config['filter_field']) && !empty($config['filter_value'])) {
      $query->condition('t.' . $config['filter_field'], $config['filter_value']);
    }

    if (!empty($config['order_field'])) {
      $query->orderBy('t.' . $config['order_field']);
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    $config = $this->configuration;
    return [
      $config['id_field'] ?? 'id' => $this->t('Term ID'),
      $config['label_field'] ?? 'title' => $this->t('Term label'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds(): array {
    $id_field = $this->configuration['id_field'] ?? 'id';
    return [
      $id_field => [
        'type' => 'integer',
        'alias' => 't',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row): bool {
    $config = $this->configuration;
    $label_field = $config['label_field'] ?? 'title';

    // Skip empty or whitespace-only terms from the legacy system.
    $label = trim((string) $row->getSourceProperty($label_field));
    if (empty($label)) {
      return FALSE;
    }

    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getDatabase(): Connection {
    return Database::getConnection('default', 'migrate');
  }

}
