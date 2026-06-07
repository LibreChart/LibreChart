<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inventory report controller.
 *
 * Current on-hand stock per drug, searchable by drug name, with stock-level
 * colouring and CSV export. The clinic operates a single stock pool
 * (feature 005), so the report is keyed by drug only — there is no clinic-site
 * dimension.
 */
class InventoryReportController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static($container->get('database'));
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Renders the filter form, the report table, and a CSV download link.
   */
  public function report(Request $request): array {
    $filters = $this->readFilters($request);

    $build = [
      '#attached' => ['library' => ['librechart_pharmacy/pharmacy']],
      '#cache' => [
        'tags' => ['drug_inventory_list'],
        'contexts' => ['url.query_args', 'user.permissions'],
      ],
    ];

    $build['filters'] = $this->formBuilder()->getForm(
      'Drupal\librechart_pharmacy\Form\InventoryReportFilterForm',
      $filters,
    );

    $can_rename = $this->currentUser()->hasPermission('rename drug terms');

    $rows = [];
    foreach ($this->fetchReportRows($filters) as $row) {
      $drug_cell = $can_rename
        ? Link::fromTextAndUrl($row['drug_label'], Url::fromRoute(
          'librechart_pharmacy.rename_drug',
          ['taxonomy_term' => $row['drug_id']],
        ))->toRenderable()
        : ['#plain_text' => $row['drug_label']];
      $rows[] = [
        'data' => [
          ['data' => $drug_cell],
          $row['category'],
          (string) $row['on_hand'],
          (string) $row['threshold'],
        ],
        'class' => $this->stockRowClass($row['on_hand'], $row['threshold']),
      ];
    }

    $export_url = Url::fromRoute('librechart_pharmacy.report_csv', [], [
      'query' => array_filter([
        'drug' => $filters['drug'],
        'category' => $filters['category'],
      ], static fn(string $v) => $v !== ''),
    ]);

    $build['export'] = [
      '#type' => 'link',
      '#title' => $this->t('Download CSV'),
      '#url' => $export_url,
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['table'] = [
      '#theme' => 'table',
      '#attributes' => ['class' => ['lc-table', 'lc-table--current-inventory']],
      '#responsive' => TRUE,
      '#header' => [
        ['data' => $this->t('Drug'), 'class' => ['priority-high']],
        ['data' => $this->t('Category'), 'class' => ['priority-low']],
        ['data' => $this->t('On hand'), 'class' => ['priority-high']],
        ['data' => $this->t('Low-stock threshold'), 'class' => ['priority-medium']],
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No inventory records match the selected filters.'),
    ];

    return $build;
  }

  /**
   * Streams the same report as a CSV file.
   */
  public function exportCsv(Request $request): StreamedResponse {
    $filters = $this->readFilters($request);
    $rows = $this->fetchReportRows($filters);

    $response = new StreamedResponse(function () use ($rows): void {
      $out = fopen('php://output', 'w');
      fputcsv($out, ['Drug', 'Category', 'On hand', 'Low-stock threshold', 'Stock status']);
      foreach ($rows as $row) {
        fputcsv($out, [
          $row['drug_label'],
          $row['category'],
          $row['on_hand'],
          $row['threshold'],
          $this->stockStatusLabel($row['on_hand'], $row['threshold']),
        ]);
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="inventory-report-' . date('Y-m-d') . '.csv"',
    );
    return $response;
  }

  /**
   * Reads the report filters from the request query.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array{drug: string, category: string}
   *   The drug-name search term and the selected category (vocabulary) machine
   *   name, or an empty string for all categories.
   */
  private function readFilters(Request $request): array {
    return [
      'drug' => (string) $request->query->get('drug', ''),
      'category' => (string) $request->query->get('category', ''),
    ];
  }

  /**
   * Builds per-drug rows of current on-hand stock.
   *
   * One row per drug (single clinic-wide pool): the primary (lowest-id)
   * inventory record supplies on-hand and threshold. Filtered by a drug-name
   * substring search and/or an exact category (vocabulary) match.
   *
   * @param array{drug: string, category: string} $filters
   *   The active filters.
   *
   * @return array<int, array{drug_id: int, drug_label: string, category: string, on_hand: int, threshold: int}>
   *   The report rows, sorted by drug label.
   */
  private function fetchReportRows(array $filters): array {
    $needle = mb_strtolower(trim($filters['drug']));
    $category = $filters['category'];

    $rows = $this->database->select('drug_inventory', 'di')
      ->fields('di', ['id', 'drug', 'quantity_on_hand', 'low_stock_threshold'])
      ->orderBy('di.id', 'ASC')
      ->execute()
      ->fetchAll();

    // Keep the primary (lowest-id) record per drug.
    $primary = [];
    foreach ($rows as $r) {
      $drug_id = (int) $r->drug;
      if (!isset($primary[$drug_id])) {
        $primary[$drug_id] = $r;
      }
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $primary ? $term_storage->loadMultiple(array_keys($primary)) : [];
    // Human-readable category labels keyed by vocabulary machine name.
    $categories = librechart_pharmacy_drug_vocabularies();

    $output = [];
    foreach ($primary as $drug_id => $r) {
      $label = isset($terms[$drug_id]) ? (string) $terms[$drug_id]->label() : '#' . $drug_id;
      if ($needle !== '' && !str_contains(mb_strtolower($label), $needle)) {
        continue;
      }
      $vid = isset($terms[$drug_id]) ? $terms[$drug_id]->bundle() : '';
      if ($category !== '' && $vid !== $category) {
        continue;
      }
      $output[] = [
        'drug_id' => $drug_id,
        'drug_label' => $label,
        'category' => (string) ($categories[$vid] ?? ''),
        'on_hand' => (int) $r->quantity_on_hand,
        'threshold' => (int) $r->low_stock_threshold,
      ];
    }

    usort($output, static fn($a, $b) => $a['drug_label'] <=> $b['drug_label']);

    return $output;
  }

  /**
   * Returns the row class for a stock level: yellow (low), red (out).
   *
   * @return array<int, string>
   *   The row classes.
   */
  private function stockRowClass(int $on_hand, int $threshold): array {
    if ($on_hand <= 0) {
      return ['inventory-row--out-of-stock'];
    }
    if ($on_hand <= $threshold) {
      return ['inventory-row--low-stock'];
    }
    return [];
  }

  /**
   * Returns a plain-text stock-status label for CSV export.
   */
  private function stockStatusLabel(int $on_hand, int $threshold): string {
    if ($on_hand <= 0) {
      return 'Out of stock';
    }
    if ($on_hand <= $threshold) {
      return 'Low';
    }
    return 'OK';
  }

}
