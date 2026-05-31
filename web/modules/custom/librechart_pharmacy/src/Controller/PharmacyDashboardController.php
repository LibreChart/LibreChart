<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Pharmacy dashboard: action cards, low-stock summary, recent receipts.
 *
 * Lands the pharmacist on a focused page that fans out to the four inventory
 * workflow paths from the pharmacy workflow doc: record receipt, view
 * inventory, run report, review past receipts.
 */
class PharmacyDashboardController extends ControllerBase {

  /**
   * Builds the pharmacy dashboard render array.
   */
  public function dashboard(): array {
    $inventory_storage = $this->entityTypeManager()->getStorage('drug_inventory');
    $receipt_storage = $this->entityTypeManager()->getStorage('inventory_receipt');

    // Low-stock count: load all inventory and compare on_hand vs threshold.
    // Cardinality is per-(drug, clinic_site), so the total set stays small
    // even for sites with dozens of drugs.
    $inventory_ids = $inventory_storage->getQuery()
      ->accessCheck(TRUE)
      ->execute();
    $low_stock = 0;
    $total_drugs = count($inventory_ids);
    if ($inventory_ids) {
      foreach ($inventory_storage->loadMultiple($inventory_ids) as $inventory) {
        $on_hand = (int) $inventory->get('quantity_on_hand')->value;
        $threshold = (int) $inventory->get('low_stock_threshold')->value;
        if ($on_hand <= $threshold) {
          $low_stock++;
        }
      }
    }

    $recent_receipt_ids = $receipt_storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->range(0, 10)
      ->execute();
    $recent_receipts = $recent_receipt_ids ? $receipt_storage->loadMultiple($recent_receipt_ids) : [];

    $build = [
      '#attached' => ['library' => ['librechart_pharmacy/pharmacy']],
      '#cache' => [
        'tags' => [
          'drug_inventory_list',
          'inventory_receipt_list',
        ],
        'contexts' => ['user.permissions'],
      ],
    ];

    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Manage drug stock receipts, monitor on-hand inventory, and generate dispensing reports.') . '</p>',
    ];

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['pharmacy-dashboard__summary']],
      'tracked' => $this->buildMetric(
        (string) $total_drugs,
        $this->t('Drugs tracked'),
      ),
      'low_stock' => $this->buildMetric(
        (string) $low_stock,
        $this->t('Low-stock items'),
        $low_stock > 0,
      ),
      'recent_receipts' => $this->buildMetric(
        (string) count($recent_receipts),
        $this->t('Receipts (recent 10)'),
      ),
    ];

    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['pharmacy-dashboard__actions']],
      'add_receipt' => $this->buildCard(
        $this->t('Record inventory receipt'),
        $this->t('Log incoming stock: drug, clinic site, quantity, and date.'),
        Url::fromRoute('entity.inventory_receipt.add_form'),
      ),
      'view_inventory' => $this->buildCard(
        $this->t('Current inventory'),
        $this->t('On-hand quantities by drug and clinic site, with low-stock highlighting.'),
        Url::fromRoute('view.inventory_report.page_inventory_report'),
      ),
      'run_report' => $this->buildCard(
        $this->t('Inventory report'),
        $this->t('Filter by clinic site and date range; see received and dispensed totals; export CSV.'),
        Url::fromRoute('librechart_pharmacy.report'),
      ),
      'view_receipts' => $this->buildCard(
        $this->t('Receipt history'),
        $this->t('Browse all past stock receipts, filterable by clinic site and date.'),
        Url::fromRoute('view.inventory_receipts.page_1'),
      ),
    ];

    if ($recent_receipts) {
      $rows = [];
      foreach ($recent_receipts as $receipt) {
        $rows[] = [
          $receipt->get('drug')->entity?->label() ?? $this->t('Unknown'),
          $receipt->get('clinic_site')->entity?->label() ?? $this->t('Unknown'),
          (int) $receipt->get('quantity_received')->value,
          $this->formatReceiptDate($receipt),
          $receipt->get('received_by')->value,
        ];
      }
      $build['recent_table'] = [
        '#type' => 'details',
        '#title' => $this->t('Recent receipts'),
        '#open' => TRUE,
        'table' => [
          '#theme' => 'table',
          '#attributes' => ['class' => ['lc-table', 'lc-table--recent-receipts']],
          '#responsive' => TRUE,
          '#header' => [
            ['data' => $this->t('Drug'),        'class' => ['priority-high']],
            ['data' => $this->t('Clinic site'), 'class' => ['priority-low']],
            ['data' => $this->t('Quantity'),    'class' => ['priority-high']],
            ['data' => $this->t('Date'),        'class' => ['priority-high']],
            ['data' => $this->t('Received by'), 'class' => ['priority-low']],
          ],
          '#rows' => $rows,
        ],
      ];
    }

    return $build;
  }

  private function buildMetric(string $value, $label, bool $alert = FALSE): array {
    $classes = ['pharmacy-dashboard__metric'];
    if ($alert) {
      $classes[] = 'pharmacy-dashboard__metric--alert';
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => $classes],
      'value' => [
        '#markup' => '<span class="pharmacy-dashboard__metric-value">' . $value . '</span>',
      ],
      'label' => [
        '#markup' => '<span class="pharmacy-dashboard__metric-label">' . $label . '</span>',
      ],
    ];
  }

  private function buildCard($title, $description, Url $url): array {
    return [
      '#type' => 'link',
      '#title' => [
        'title' => ['#markup' => '<span class="pharmacy-dashboard__card-title">' . $title . '</span>'],
        'desc' => ['#markup' => '<span class="pharmacy-dashboard__card-desc">' . $description . '</span>'],
      ],
      '#url' => $url,
      '#attributes' => ['class' => ['pharmacy-dashboard__card']],
    ];
  }

  private function formatReceiptDate($receipt): string {
    $raw = (string) $receipt->get('receipt_date')->value;
    if ($raw === '') {
      return '';
    }
    $ts = strtotime($raw);
    return $ts ? date('d/m/Y', $ts) : '';
  }

}
