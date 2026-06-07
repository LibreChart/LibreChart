<?php

declare(strict_types=1);

namespace Drupal\librechart_visit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\librechart_visit\Service\StationWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the column-per-station "Floor" board (FR-006, FR-007, FR-008).
 *
 * Each in-progress visit appears under the column matching its
 * `current_station`. Completed visits are excluded. Empty columns render
 * a placeholder so the board structure stays visible at all times.
 */
final class FloorController extends ControllerBase {

  /**
   * Constructs the floor controller.
   */
  public function __construct(
    private readonly StationWorkflow $workflow,
  ) {}

  /**
   * Service container factory.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('librechart_visit.station_workflow'),
    );
  }

  /**
   * Builds the board render array.
   */
  public function board(): array {
    $visit_storage = $this->entityTypeManager()->getStorage('visit');
    $patient_storage = $this->entityTypeManager()->getStorage('patient');

    // Single query for all in-progress visits, ordered by visit_date ASC
    // so the longest-waiting patient appears at the top of each column.
    $vids = $visit_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 'complete', '!=')
      ->sort('visit_date', 'ASC')
      ->execute();
    $visits = $vids ? $visit_storage->loadMultiple($vids) : [];

    // Group visits by current_station. Pre-seed an entry for each workflow
    // station so the column renders even when no visits are present.
    $by_station = array_fill_keys($this->workflow->workflowStations(), []);
    $patient_ids = [];
    foreach ($visits as $visit) {
      if (!$visit instanceof ContentEntityInterface) {
        continue;
      }
      $station = (string) $visit->get('current_station')->value;
      if (!isset($by_station[$station])) {
        // Defensive: a visit could carry the `complete` sentinel if its
        // status is somehow != complete; skip it from the board rather
        // than create an off-the-grid column.
        continue;
      }
      $by_station[$station][] = $visit;
      if ($pid = $visit->get('patient')->target_id) {
        $patient_ids[(int) $pid] = TRUE;
      }
    }

    // Bulk-load patients to avoid N+1 queries in the row rendering.
    /** @var array<int, \Drupal\Core\Entity\ContentEntityInterface> $patients */
    $patients = $patient_ids ? $patient_storage->loadMultiple(array_keys($patient_ids)) : [];

    $columns = [];
    foreach ($this->workflow->workflowStations() as $station) {
      $items = [];
      foreach ($by_station[$station] as $visit) {
        $items[] = $this->buildVisitRow($visit, $patients);
      }
      $columns[$station] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['floor-board__column'],
          'data-station' => $station,
        ],
        'header' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['floor-board__header']],
          'label' => ['#markup' => $this->workflow->label($station)],
          'count' => [
            '#markup' => sprintf('<span class="floor-board__count">%d</span>', count($items)),
          ],
        ],
        'body' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['floor-board__body']],
          'list' => $items
            ? ['#theme' => 'item_list', '#items' => $items]
            : ['#markup' => '<span class="floor-board__empty">' . $this->t('(empty)') . '</span>'],
        ],
      ];
    }

    return [
      '#attached' => ['library' => ['librechart_visit/station_strip']],
      '#cache' => [
        'tags' => ['visit_list', 'patient_list'],
        'contexts' => ['user.permissions'],
      ],
      'board' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['floor-board']],
      ] + $columns,
    ];
  }

  /**
   * Renders one visit row inside a station column.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $visit
   *   The visit.
   * @param array<int, \Drupal\Core\Entity\ContentEntityInterface> $patients
   *   Pre-loaded patient map keyed by patient id.
   *
   * @return array<string, mixed>
   *   Render array for one list item.
   */
  private function buildVisitRow(ContentEntityInterface $visit, array $patients): array {
    $pid = (int) ($visit->get('patient')->target_id ?? 0);
    $patient = $patients[$pid] ?? NULL;
    $last = $patient ? $patient->get('last_name')->value : $this->t('Unknown');
    $first = $patient ? $patient->get('first_name')->value : '';
    return [
      '#type' => 'link',
      '#title' => $this->t('@last, @first', [
        '@last' => $last,
        '@first' => $first,
      ]),
      '#url' => $patient ? $patient->toUrl('edit-form') : $visit->toUrl('edit-form'),
    ];
  }

}
