<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inventory report: per-drug on-hand + total received + total dispensed
 * within a date range, with low-stock flagging and CSV export.
 *
 * Built as a custom controller (not a Views display) because the report
 * joins three tables (drug_inventory, inventory_receipt, prescription_item +
 * visit) and aggregates with date filters — easier to express in SQL than to
 * coerce out of the Views UI.
 */
class InventoryReportController extends ControllerBase {

  public function __construct(
    protected Connection $database,
  ) {}

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
        'tags' => ['drug_inventory_list', 'inventory_receipt_list', 'visit_list'],
        'contexts' => ['url.query_args', 'user.permissions'],
      ],
    ];

    $build['filters'] = $this->formBuilder()->getForm(
      'Drupal\librechart_pharmacy\Form\InventoryReportFilterForm',
      $filters,
    );

    $rows_data = $this->fetchReportRows($filters);
    $rows = [];
    foreach ($rows_data as $row) {
      $is_low = $row['on_hand'] <= $row['threshold'];
      $drug_cell = $row['drug_label'];
      if ($is_low) {
        $drug_cell .= '<span class="inventory-low-stock-flag">' . $this->t('Low') . '</span>';
      }
      $rows[] = [
        'data' => [
          ['data' => ['#markup' => $drug_cell]],
          $row['site_label'],
          (string) $row['on_hand'],
          (string) $row['threshold'],
          (string) $row['received'],
          (string) $row['dispensed'],
        ],
        'class' => $is_low ? ['inventory-row--low-stock'] : [],
      ];
    }

    $export_url = Url::fromRoute('librechart_pharmacy.report_csv', [], [
      'query' => array_filter([
        'clinic_site' => $filters['clinic_site'],
        'start' => $filters['start'],
        'end' => $filters['end'],
      ], static fn($v) => $v !== '' && $v !== NULL),
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
        ['data' => $this->t('Drug'),                 'class' => ['priority-high']],
        ['data' => $this->t('Clinic site'),          'class' => ['priority-low']],
        ['data' => $this->t('On hand'),              'class' => ['priority-high']],
        ['data' => $this->t('Low-stock threshold'),  'class' => ['priority-medium']],
        ['data' => $this->t('Received (in range)'),  'class' => ['priority-medium']],
        ['data' => $this->t('Dispensed (in range)'), 'class' => ['priority-medium']],
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
      fputcsv($out, [
        'Drug',
        'Clinic site',
        'On hand',
        'Low-stock threshold',
        'Received (in range)',
        'Dispensed (in range)',
        'Low stock',
      ]);
      foreach ($rows as $row) {
        fputcsv($out, [
          $row['drug_label'],
          $row['site_label'],
          $row['on_hand'],
          $row['threshold'],
          $row['received'],
          $row['dispensed'],
          $row['on_hand'] <= $row['threshold'] ? 'YES' : '',
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
   * @return array{clinic_site: string, start: string, end: string}
   */
  private function readFilters(Request $request): array {
    return [
      'clinic_site' => (string) $request->query->get('clinic_site', ''),
      'start' => (string) $request->query->get('start', ''),
      'end' => (string) $request->query->get('end', ''),
    ];
  }

  /**
   * Builds per-(drug, clinic_site) rows: on-hand + received + dispensed totals.
   *
   * @return array<int, array{drug_id: int, site_id: int, drug_label: string, site_label: string, on_hand: int, threshold: int, received: int, dispensed: int}>
   */
  private function fetchReportRows(array $filters): array {
    $clinic_site = $filters['clinic_site'] !== '' ? (int) $filters['clinic_site'] : NULL;
    // Inclusive boundaries; for datetime fields we compare against the start
    // of `start` and the end of `end` so a same-day range catches that day.
    $start = $filters['start'] !== '' ? $filters['start'] . 'T00:00:00' : NULL;
    $end = $filters['end'] !== '' ? $filters['end'] . 'T23:59:59' : NULL;

    $query = $this->database->select('drug_inventory', 'di');
    $query->fields('di', ['drug', 'clinic_site', 'quantity_on_hand', 'low_stock_threshold']);
    if ($clinic_site !== NULL) {
      $query->condition('di.clinic_site', $clinic_site);
    }
    $rows = $query->execute()->fetchAll();

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $needed_tids = [];
    foreach ($rows as $r) {
      $needed_tids[(int) $r->drug] = TRUE;
      $needed_tids[(int) $r->clinic_site] = TRUE;
    }
    $terms = $needed_tids ? $term_storage->loadMultiple(array_keys($needed_tids)) : [];

    $output = [];
    foreach ($rows as $r) {
      $drug_id = (int) $r->drug;
      $site_id = (int) $r->clinic_site;
      $output[] = [
        'drug_id' => $drug_id,
        'site_id' => $site_id,
        'drug_label' => $terms[$drug_id]?->label() ?? '#' . $drug_id,
        'site_label' => $terms[$site_id]?->label() ?? '#' . $site_id,
        'on_hand' => (int) $r->quantity_on_hand,
        'threshold' => (int) $r->low_stock_threshold,
        'received' => $this->sumReceived($drug_id, $site_id, $start, $end),
        'dispensed' => $this->sumDispensed($drug_id, $site_id, $start, $end),
      ];
    }

    usort($output, static function ($a, $b) {
      return [$a['site_label'], $a['drug_label']] <=> [$b['site_label'], $b['drug_label']];
    });

    return $output;
  }

  private function sumReceived(int $drug_id, int $site_id, ?string $start, ?string $end): int {
    $q = $this->database->select('inventory_receipt', 'ir')
      ->condition('ir.drug', $drug_id)
      ->condition('ir.clinic_site', $site_id);
    if ($start !== NULL) {
      $q->condition('ir.receipt_date', $start, '>=');
    }
    if ($end !== NULL) {
      $q->condition('ir.receipt_date', $end, '<=');
    }
    $q->addExpression('SUM(ir.quantity_received)', 'total');
    $result = $q->execute()->fetchField();
    return (int) ($result ?? 0);
  }

  private function sumDispensed(int $drug_id, int $site_id, ?string $start, ?string $end): int {
    // Dispensing events live on prescription_item; the visit they belong to
    // carries the date and the clinic_site. Filter on both.
    $q = $this->database->select('prescription_item', 'pi')
      ->condition('pi.drug', $drug_id)
      ->condition('pi.prescription_filled', 1);
    $q->innerJoin('visit', 'v', 'v.vid = pi.visit');
    $q->condition('v.clinic_site', $site_id);
    if ($start !== NULL) {
      $q->condition('v.visit_date', $start, '>=');
    }
    if ($end !== NULL) {
      $q->condition('v.visit_date', $end, '<=');
    }
    $q->addExpression('SUM(pi.quantity_dispensed)', 'total');
    $result = $q->execute()->fetchField();
    return (int) ($result ?? 0);
  }

}
