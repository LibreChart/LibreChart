<?php

declare(strict_types=1);

namespace Drupal\librechart_visit\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\Markup;
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
    private readonly TimeInterface $time,
  ) {}

  /**
   * Service container factory.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('librechart_visit.station_workflow'),
      $container->get('datetime.time'),
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
   * How long (in seconds) a visit may sit at a station before it is flagged.
   *
   * Once a patient has been in the same station queue longer than this, the
   * floor board marks the name with a ghost icon so staff can spot stalled
   * visits at a glance. Two hours.
   */
  private const STALE_AFTER_SECONDS = 7200;

  /**
   * How long (in seconds) before a visit is flagged as inactive.
   *
   * Once a patient has been in the same station queue longer than this (but not
   * yet past STALE_AFTER_SECONDS), the floor board marks the name with a
   * grimace icon as an early warning. One hour.
   */
  private const INACTIVE_AFTER_SECONDS = 3600;

  /**
   * Inline FontAwesome 5 "ghost" (solid) icon, flagging a stalled visit.
   *
   * Inlined as SVG rather than loaded from the FontAwesome webfont so it stays
   * self-hosted (the EMR runs on an offline LAN). `fill: currentColor` makes it
   * inherit the name's already contrast-corrected text color on every
   * background, so it meets the same contrast as the text.
   */
  private const GHOST_ICON = '<svg class="floor-ghost" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512"><path d="M186.1.09C81.01 3.24 0 94.92 0 200.05v263.92c0 14.26 17.23 21.39 27.31 11.31l24.92-18.53c6.66-4.95 16-3.99 21.51 2.21l42.95 48.35c6.25 6.25 16.38 6.25 22.63 0l40.72-45.85c6.37-7.17 17.56-7.17 23.92 0l40.72 45.85c6.25 6.25 16.38 6.25 22.63 0l42.95-48.35c5.51-6.2 14.85-7.17 21.51-2.21l24.92 18.53c10.08 10.08 27.31 2.94 27.31-11.31V192C384 84 294.83-3.17 186.1.09zM128 224c-17.67 0-32-14.33-32-32s14.33-32 32-32 32 14.33 32 32-14.33 32-32 32zm128 0c-17.67 0-32-14.33-32-32s14.33-32 32-32 32 14.33 32 32-14.33 32-32 32z"/></svg>';

  /**
   * Inline FontAwesome 6 "face-grimace" (regular) icon, flagging an early stall.
   *
   * Shown when a visit has been inactive for over an hour but under two hours;
   * at the two-hour mark it is replaced by the ghost icon (never both at once).
   * Inlined for the same self-hosted, contrast-inheriting reasons as the ghost.
   */
  private const GRIMACE_ICON = '<svg class="floor-grimace" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M256 48a208 208 0 1 0 0 416 208 208 0 1 0 0-416zM512 256a256 256 0 1 1 -512 0 256 256 0 1 1 512 0zM152 352c0 11.9 8.6 21.8 20 23.7l0-47.3c-11.4 1.9-20 11.8-20 23.7zm84 24l0-48-24 0 0 48 24 0zm64 0l0-48-24 0 0 48 24 0zm40-.3c11.4-1.9 20-11.8 20-23.7s-8.6-21.8-20-23.7l0 47.3zM176 288l160 0c35.3 0 64 28.7 64 64s-28.7 64-64 64l-160 0c-35.3 0-64-28.7-64-64s28.7-64 64-64zm0-112a32 32 0 1 1 0 64 32 32 0 1 1 0-64zm128 32a32 32 0 1 1 64 0 32 32 0 1 1 -64 0z"/></svg>';

  /**
   * Inline FontAwesome 6 "flag" (solid, f024) icon, marking a flagged visit.
   *
   * Shown after the name when a visit has any station flagged for an unresolved
   * issue or a required return.
   * Matches the red flag toggled from the visit's station strip. Inlined as SVG
   * for the same self-hosted reasons as the icons above, but with a fixed red
   * fill (not currentColor) so it reads as an alert regardless of the name's
   * background. Cleared automatically once every station flag is removed.
   */
  private const FLAG_ICON = '<svg class="floor-flag" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M64 32C64 14.3 49.7 0 32 0S0 14.3 0 32L0 64 0 368 0 480c0 17.7 14.3 32 32 32s32-14.3 32-32l0-128 64.3-16.1c41.1-10.3 84.6-5.5 122.5 13.4c44.2 22.1 95.5 24.4 141.4 6.4l8.1-3.2c12.2-4.9 20.2-16.7 20.2-29.7l0-247.7c0-23-24.2-38-44.8-27.7l-9.6 4.8c-46.3 23.2-100.8 23.2-147.1 0c-35.5-17.8-76.5-22.4-115.2-13l-58.4 14.6L64 32z"/></svg>';

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

    // Single query for all in-progress visits, ordered by station_entered ASC
    // so each column is a true arrival-at-station queue: the patient who has
    // been waiting at that station longest appears at the top. visit_date is a
    // stable tiebreaker (and a fallback for any visit without a stamp yet).
    $vids = $visit_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 'complete', '!=')
      ->sort('station_entered', 'ASC')
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

    // Stamp "now" once so every row is judged stale against the same instant.
    $now = $this->time->getRequestTime();

    $columns = [];
    foreach ($this->workflow->workflowStations() as $station) {
      $items = [];
      foreach ($by_station[$station] as $visit) {
        $items[] = $this->buildVisitRow($visit, $patients, $station, $specialties, $now);
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
      // A second key explaining the ghost flag, so wall-display viewers know
      // what the icon before a name means.
      'status_title' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['floor-legend__title', 'floor-legend__title--status']],
        '#value' => $this->t('Status'),
      ],
      'status_list' => [
        '#theme' => 'item_list',
        '#items' => [
          [
            '#wrapper_attributes' => ['class' => ['floor-legend__item']],
            'icon' => ['#markup' => Markup::create(self::GRIMACE_ICON)],
            'label' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#attributes' => ['class' => ['floor-legend__label']],
              '#value' => $this->t('Inactive for over 1 hour'),
            ],
          ],
          [
            '#wrapper_attributes' => ['class' => ['floor-legend__item']],
            'icon' => ['#markup' => Markup::create(self::GHOST_ICON)],
            'label' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#attributes' => ['class' => ['floor-legend__label']],
              '#value' => $this->t('Inactive for more than 2 hours'),
            ],
          ],
        ],
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
   * @param int $now
   *   The current request time, used to detect stalled visits.
   *
   * @return array<string, mixed>
   *   Render array for one list item.
   */
  private function buildVisitRow(ContentEntityInterface $visit, array $patients, string $station, array $specialties, int $now): array {
    $pid = (int) ($visit->get('patient')->target_id ?? 0);
    $patient = $patients[$pid] ?? NULL;
    $last = $patient ? $patient->get('last_name')->value : $this->t('Unknown');
    $first = $patient ? $patient->get('first_name')->value : '';
    $name = $this->t('@last, @first', [
      '@last' => $last,
      '@first' => $first,
    ]);
    $link = [
      '#type' => 'link',
      '#title' => $name,
      '#url' => $patient ? $patient->toUrl('edit-form') : $visit->toUrl('edit-form'),
    ];

    // Flag a visit that has sat in this station queue too long with an icon
    // before the name. Past two hours it gets the ghost; between one and two
    // hours the grimace (an early warning). The two are mutually exclusive —
    // never both at once. The icon lives inside the link so it inherits the
    // name's (contrast-corrected) text color.
    $entered = (int) ($visit->get('station_entered')->value ?? 0);
    $elapsed = $entered > 0 ? $now - $entered : 0;
    if ($entered > 0 && $elapsed > self::STALE_AFTER_SECONDS) {
      $link['#attributes']['class'][] = 'is-stale';
      $link['#attributes']['title'] = $this->t('Inactive for more than 2 hours');
      $link['#title'] = [
        'icon' => ['#markup' => Markup::create(self::GHOST_ICON)],
        'note' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['visually-hidden']],
          '#value' => $this->t('Inactive for more than 2 hours:'),
        ],
        'name' => ['#markup' => $name],
      ];
    }
    elseif ($entered > 0 && $elapsed > self::INACTIVE_AFTER_SECONDS) {
      $link['#attributes']['class'][] = 'is-inactive';
      $link['#attributes']['title'] = $this->t('Inactive for over 1 hour');
      $link['#title'] = [
        'icon' => ['#markup' => Markup::create(self::GRIMACE_ICON)],
        'note' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['visually-hidden']],
          '#value' => $this->t('Inactive for over 1 hour:'),
        ],
        'name' => ['#markup' => $name],
      ];
    }

    // In the Clinical Evaluation column, tint the name with the patient's
    // specialty colors. A single specialty fills the background; a patient
    // seeing several clinicians gets one equal band per specialty, left to
    // right (e.g. GYN + Wounds shows magenta and orange halves).
    if ($station === self::CLINICAL_STATION) {
      $colors = $this->visitSpecialtyColors($visit, $specialties);
      if (count($colors) === 1) {
        $link['#attributes']['class'][] = 'has-specialty';
        $link['#attributes']['style'] = sprintf(
          'background-color:%s;color:%s',
          $colors[0],
          $this->contrastColor($colors[0]),
        );
      }
      elseif (count($colors) > 1) {
        $link['#attributes']['class'][] = 'has-specialty';
        $link['#attributes']['class'][] = 'has-specialty--split';
        $link['#attributes']['style'] = $this->splitBackgroundStyle($colors);
      }
    }

    // Prepend a red flag inside the name link when any station on this visit
    // carries an issue flag (toggled from the station strip). Putting it in the
    // link's title — rather than alongside the link — keeps it within the
    // specialty-colored pill, and leads any stale ghost/grimace icon. Disappears
    // on its own once every station flag has been cleared.
    if (!$visit->get('flagged_stations')->isEmpty()) {
      // Fold the existing title (a plain name, or the stale-icon array) into an
      // array so the flag can lead it.
      $title = is_array($link['#title'])
        ? $link['#title']
        : ['name' => ['#markup' => $link['#title']]];
      $link['#title'] = [
        'flag_note' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['visually-hidden']],
          '#value' => $this->t('Has a flagged station issue'),
        ],
        'flag' => ['#markup' => Markup::create(self::FLAG_ICON)],
      ] + $title;
    }

    return $link;
  }

  /**
   * Collects a visit's specialty colors, in field order.
   *
   * Specialties without a color and repeated specialties are skipped so the
   * background shows one band per distinct specialty the patient is seeing.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $visit
   *   The visit.
   * @param array<int, array{name: string, color: string}> $specialties
   *   Specialty terms keyed by id.
   *
   * @return list<string>
   *   Ordered list of hex colors (may be empty).
   */
  private function visitSpecialtyColors(ContentEntityInterface $visit, array $specialties): array {
    $colors = [];
    $seen = [];
    foreach ($visit->get('specialties') as $item) {
      $tid = (int) ($item->target_id ?? 0);
      if ($tid === 0 || isset($seen[$tid])) {
        continue;
      }
      $seen[$tid] = TRUE;
      $color = $specialties[$tid]['color'] ?? '';
      if ($color !== '') {
        $colors[] = $color;
      }
    }
    return $colors;
  }

  /**
   * Builds a hard-stop gradient that splits the name background into bands.
   *
   * Each color gets an equal vertical band ordered left to right, so two
   * specialties show halves, three show thirds, and so on.
   *
   * @param list<string> $colors
   *   Two or more hex colors, in display order.
   *
   * @return string
   *   An inline style declaration for the split background.
   */
  private function splitBackgroundStyle(array $colors): string {
    $count = count($colors);
    $stops = [];
    foreach (array_values($colors) as $i => $color) {
      $start = round($i / $count * 100, 4);
      $end = round(($i + 1) / $count * 100, 4);
      $stops[] = sprintf('%s %s%% %s%%', $color, $start, $end);
    }
    return 'background:linear-gradient(90deg,' . implode(',', $stops) . ')';
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
