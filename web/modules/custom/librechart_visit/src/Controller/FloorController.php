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
   * The `current_station` value whose column gets specialty coloring.
   */
  private const CLINICAL_STATION = 'clinical';

  /**
   * How often (in seconds) the floor board reloads itself.
   *
   * The board is a passive display (typically a wall-mounted screen), so it
   * must refresh on its own to reflect patients moving between stations. A
   * meta refresh reloads the whole page without any JavaScript dependency.
   */
  private const REFRESH_SECONDS = 30;

  /**
   * Builds the board render array.
   */
  public function board(): array {
    $visit_storage = $this->entityTypeManager()->getStorage('visit');
    $patient_storage = $this->entityTypeManager()->getStorage('patient');

    // Load every specialty term once, keyed by id, carrying its name and
    // color. Serves both the per-patient color lookup and the legend, and
    // keeps the order admins set on the vocabulary.
    $specialties = $this->loadSpecialties();

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
        $items[] = $this->buildVisitRow($visit, $patients, $station, $specialties);
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
      '#attached' => [
        'library' => ['librechart_visit/station_strip'],
        // Auto-reload the board so it tracks patients moving between stations
        // without anyone touching the wall display.
        'html_head' => [
          [
            [
              '#tag' => 'meta',
              '#attributes' => [
                'http-equiv' => 'refresh',
                'content' => (string) self::REFRESH_SECONDS,
              ],
            ],
            'floor_board_refresh',
          ],
        ],
      ],
      '#cache' => [
        // The board must always reflect live station positions, so it is never
        // page-cached: each (auto-)reload rebuilds from the current data and no
        // stale copy is held by the browser or an intermediary.
        'max-age' => 0,
        'contexts' => ['user.permissions'],
      ],
      'board' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['floor-board']],
      ] + $columns,
      'legend' => $this->buildLegend($specialties),
    ];
  }

  /**
   * Loads specialty terms keyed by id, in vocabulary order.
   *
   * @return array<int, array{name: string, color: string}>
   *   Map of term id to its name and (possibly empty) hex color.
   */
  private function loadSpecialties(): array {
    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $tids = $term_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'specialties')
      ->sort('weight')
      ->sort('name')
      ->execute();
    $specialties = [];
    foreach ($tids ? $term_storage->loadMultiple($tids) : [] as $term) {
      $color = $term->hasField('field_color') ? trim((string) $term->get('field_color')->value) : '';
      $specialties[(int) $term->id()] = [
        'name' => (string) $term->label(),
        'color' => $color,
      ];
    }
    return $specialties;
  }

  /**
   * Builds the color key shown beneath the board.
   *
   * @param array<int, array{name: string, color: string}> $specialties
   *   Specialty terms keyed by id.
   *
   * @return array<string, mixed>
   *   Render array for the legend (empty when no specialties exist).
   */
  private function buildLegend(array $specialties): array {
    if (!$specialties) {
      return [];
    }
    $items = [];
    foreach ($specialties as $specialty) {
      // Build the swatch as an html_tag so the inline color survives rendering
      // (raw #markup is XSS-filtered, which strips the style attribute).
      $swatch_attributes = ['class' => ['floor-legend__swatch']];
      if ($specialty['color'] !== '') {
        $swatch_attributes['style'] = 'background-color:' . $specialty['color'];
      }
      else {
        $swatch_attributes['class'][] = 'floor-legend__swatch--none';
      }
      $items[] = [
        '#wrapper_attributes' => ['class' => ['floor-legend__item']],
        'swatch' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => $swatch_attributes,
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['floor-legend__label']],
          '#value' => $specialty['name'],
        ],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['floor-legend']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['floor-legend__title']],
        '#value' => $this->t('Specialties'),
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['floor-legend__list']],
      ],
    ];
  }

  /**
   * Renders one visit row inside a station column.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $visit
   *   The visit.
   * @param array<int, \Drupal\Core\Entity\ContentEntityInterface> $patients
   *   Pre-loaded patient map keyed by patient id.
   * @param string $station
   *   The column's station; coloring only applies to the clinical station.
   * @param array<int, array{name: string, color: string}> $specialties
   *   Specialty terms keyed by id.
   *
   * @return array<string, mixed>
   *   Render array for one list item.
   */
  private function buildVisitRow(ContentEntityInterface $visit, array $patients, string $station, array $specialties): array {
    $pid = (int) ($visit->get('patient')->target_id ?? 0);
    $patient = $patients[$pid] ?? NULL;
    $last = $patient ? $patient->get('last_name')->value : $this->t('Unknown');
    $first = $patient ? $patient->get('first_name')->value : '';
    $link = [
      '#type' => 'link',
      '#title' => $this->t('@last, @first', [
        '@last' => $last,
        '@first' => $first,
      ]),
      '#url' => $patient ? $patient->toUrl('edit-form') : $visit->toUrl('edit-form'),
    ];

    // In the Clinical Evaluation column, tint the name with the color of the
    // patient's primary (first) specialty. Multiple specialties take the first.
    if ($station === self::CLINICAL_STATION) {
      $primary = (int) ($visit->get('specialties')->target_id ?? 0);
      $color = $specialties[$primary]['color'] ?? '';
      if ($color !== '') {
        $link['#attributes']['class'][] = 'has-specialty';
        $link['#attributes']['style'] = sprintf(
          'background-color:%s;color:%s',
          $color,
          $this->contrastColor($color),
        );
      }
    }

    return $link;
  }

  /**
   * Picks a readable text color (#111 or #fff) for a hex background.
   *
   * Uses the standard relative-luminance threshold so dark specialty colors
   * get light text and light colors get dark text. Falls back to dark text
   * when the value is not a usable 3- or 6-digit hex color.
   *
   * @param string $hex
   *   A hex color such as "#2563eb" or "#abc".
   *
   * @return string
   *   Either "#111111" or "#ffffff".
   */
  private function contrastColor(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
      return '#111111';
    }
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    // Perceived luminance (sRGB coefficients).
    $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    return $luminance > 0.6 ? '#111111' : '#ffffff';
  }

}
