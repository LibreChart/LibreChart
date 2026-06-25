<?php

declare(strict_types=1);

namespace Drupal\librechart_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;
use Drupal\librechart_visit\Service\StationWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the Traffic report.
 *
 * Surfaces patient-flow patterns for a single mission day: average time spent
 * in each station, arrivals versus completions by hour, how many patients were
 * in the clinic at once, and how many patients each station processed. Like the
 * Daily report, a "mission day" is the calendar date of a visit's visit_date,
 * and days are presented as tabs.
 *
 * All timing is derived from the Visit revision history (one revision per
 * save). The current station and its entry time are read from changes in
 * current_station across revisions ordered by revision_timestamp, which is
 * always present — the station_entered base field was added later and is NULL
 * on older revisions, so it cannot be relied on for historical dwell times.
 */
final class TrafficReportController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The active database connection.
   * @param \Drupal\librechart_visit\Service\StationWorkflow $workflow
   *   The station workflow service, for canonical station order and labels.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly StationWorkflow $workflow,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('librechart_visit.station_workflow'),
    );
  }

  /**
   * Builds the report render array for the selected mission day.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request; the optional "day" query parameter selects the
   *   mission day (YYYY-MM-DD).
   *
   * @return array
   *   The page render array.
   */
  public function report(Request $request): array {
    $days = $this->getMissionDays();

    if (empty($days)) {
      return [
        '#markup' => '<p>' . $this->t('No visits have been recorded yet, so there is no traffic to report.') . '</p>',
        '#cache' => ['tags' => ['visit_list'], 'max-age' => 0],
      ];
    }

    // Resolve the selected day: a valid "day" query param, else the latest.
    $available = array_column($days, 'day');
    $requested = $request->query->get('day');
    $selected = in_array($requested, $available, TRUE) ? $requested : end($available);

    $traffic = $this->computeTraffic($selected);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lc-daily-report', 'lc-traffic-report']],
      '#attached' => [
        'library' => ['librechart_reports/traffic_report'],
        'drupalSettings' => [
          'librechartReports' => [
            'traffic' => $traffic['charts'],
          ],
        ],
      ],
      '#cache' => ['tags' => ['visit_list'], 'max-age' => 0],
    ];

    $build['tabs'] = $this->buildTabs($days, $selected);
    $build['summary'] = $this->buildSummary($traffic['summary']);
    $build['charts'] = $this->buildChartGrid();

    return $build;
  }

  /**
   * Returns the mission days that have visits.
   *
   * @return array
   *   A list of ['day' => 'YYYY-MM-DD', 'count' => int] ordered ascending.
   */
  private function getMissionDays(): array {
    $result = $this->database->query(
      'SELECT DATE(visit_date) AS day, COUNT(*) AS cnt
       FROM {visit}
       GROUP BY DATE(visit_date)
       ORDER BY day ASC'
    );
    $days = [];
    foreach ($result as $row) {
      $days[] = ['day' => $row->day, 'count' => (int) $row->cnt];
    }
    return $days;
  }

  /**
   * Builds the mission-day tab bar.
   *
   * @param array<int, array{day: string, count: int}> $days
   *   The mission days from self::getMissionDays().
   * @param string $selected
   *   The currently selected day (YYYY-MM-DD).
   *
   * @return array
   *   A render array of tab links.
   */
  private function buildTabs(array $days, string $selected): array {
    $tabs = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lc-daily-report__tabs'], 'role' => 'tablist'],
    ];
    foreach ($days as $day) {
      $is_active = $day['day'] === $selected;
      $tabs[$day['day']] = [
        '#type' => 'link',
        '#title' => $this->formatDayLabel($day['day']),
        '#url' => Url::fromRoute('librechart_reports.traffic', [], ['query' => ['day' => $day['day']]]),
        '#attributes' => [
          'class' => array_filter(['lc-tab', $is_active ? 'is-active' : '']),
          'role' => 'tab',
          'aria-selected' => $is_active ? 'true' : 'false',
        ],
      ];
    }
    return $tabs;
  }

  /**
   * Builds the headline stats row for the selected day.
   *
   * @param array{patients: int, avg_duration: string|null, peak: int, peak_hour: string} $summary
   *   The summary figures from self::computeTraffic().
   *
   * @return array
   *   A render array.
   */
  private function buildSummary(array $summary): array {
    $stats = [
      [
        'value' => (string) $summary['patients'],
        'label' => $this->t('patients seen'),
      ],
      [
        'value' => $summary['avg_duration'] ?? '—',
        'label' => $this->t('average time on site'),
      ],
      [
        'value' => (string) $summary['peak'],
        'label' => $this->t('peak patients on site (@hour)', ['@hour' => $summary['peak_hour']]),
      ],
    ];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lc-traffic-report__stats']],
    ];
    foreach ($stats as $i => $stat) {
      $build[$i] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['lc-traffic-report__stat']],
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['lc-traffic-report__stat-value']],
          '#value' => $stat['value'],
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['lc-traffic-report__stat-label']],
          '#value' => $stat['label'],
        ],
      ];
    }
    return $build;
  }

  /**
   * Builds the grid of chart cards (canvas elements populated by JS).
   *
   * @return array
   *   A render array.
   */
  private function buildChartGrid(): array {
    $cards = [
      'dwell' => $this->t('Average time spent in station'),
      'flow' => $this->t('Arrivals vs. completions per hour'),
      'census' => $this->t('Patients on site over the day'),
      'throughput' => $this->t('Patients processed per station'),
    ];

    $grid = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lc-daily-report__grid']],
    ];
    foreach ($cards as $key => $title) {
      $grid[$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['lc-chart-card', 'lc-chart-card--' . $key]],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#attributes' => ['class' => ['lc-chart-card__title']],
          '#value' => $title,
        ],
        'canvas_wrap' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['lc-chart-card__canvas-wrap']],
          'canvas' => [
            '#type' => 'html_tag',
            '#tag' => 'canvas',
            '#attributes' => [
              'class' => ['lc-chart-card__canvas'],
              'data-lc-chart' => $key,
            ],
            // A canvas needs explicit closing for Drupal's html_tag.
            '#value' => '',
          ],
        ],
      ];
    }
    return $grid;
  }

  /**
   * Computes all traffic figures for a mission day in a single pass.
   *
   * @param string $day
   *   The mission day (YYYY-MM-DD).
   *
   * @return array
   *   ['charts' => chart-ready data keyed by chart id, 'summary' => figures].
   */
  private function computeTraffic(string $day): array {
    // Per-visit arrival and completion times. Arrival is the first revision;
    // completion is the first revision that reached the `complete` sentinel.
    $visits = [];
    $result = $this->database->query(
      "SELECT vr.vid AS vid,
              MIN(vr.revision_timestamp) AS arrived,
              MIN(CASE WHEN vr.current_station = 'complete' THEN vr.revision_timestamp END) AS completed
       FROM {visit_revision} vr
       INNER JOIN {visit} v ON v.vid = vr.vid
       WHERE DATE(v.visit_date) = :day
       GROUP BY vr.vid",
      [':day' => $day]
    );
    foreach ($result as $row) {
      $visits[] = [
        'arrived' => $row->arrived !== NULL ? (int) $row->arrived : NULL,
        'completed' => $row->completed !== NULL ? (int) $row->completed : NULL,
      ];
    }

    [$dwell, $throughput] = $this->stationDwell($day);

    // Arrivals and completions bucketed by hour of day; end-to-end durations.
    $arrivals = array_fill(0, 24, 0);
    $completions = array_fill(0, 24, 0);
    $durations = [];
    foreach ($visits as $visit) {
      if ($visit['arrived'] !== NULL) {
        $arrivals[(int) date('G', $visit['arrived'])]++;
      }
      if ($visit['completed'] !== NULL) {
        $completions[(int) date('G', $visit['completed'])]++;
        if ($visit['arrived'] !== NULL && $visit['completed'] >= $visit['arrived']) {
          $durations[] = $visit['completed'] - $visit['arrived'];
        }
      }
    }

    // The hour window spans the first to the last hour with any activity.
    $active_hours = [];
    for ($h = 0; $h < 24; $h++) {
      if ($arrivals[$h] > 0 || $completions[$h] > 0) {
        $active_hours[] = $h;
      }
    }
    $min_hour = $active_hours ? min($active_hours) : 0;
    $max_hour = $active_hours ? max($active_hours) : -1;

    // Concurrent census: how many visits were on site during each hour. A visit
    // counts for hour H if it had arrived by H and had not completed before H.
    $hour_labels = [];
    $arr_series = [];
    $comp_series = [];
    $census_series = [];
    $peak = 0;
    $peak_hour = $min_hour;
    for ($h = $min_hour; $h <= $max_hour; $h++) {
      $on_site = 0;
      foreach ($visits as $visit) {
        if ($visit['arrived'] === NULL) {
          continue;
        }
        $arrived_hour = (int) date('G', $visit['arrived']);
        $completed_hour = $visit['completed'] !== NULL ? (int) date('G', $visit['completed']) : NULL;
        if ($arrived_hour <= $h && ($completed_hour === NULL || $completed_hour >= $h)) {
          $on_site++;
        }
      }
      $hour_labels[] = $this->formatHourLabel($h);
      $arr_series[] = $arrivals[$h];
      $comp_series[] = $completions[$h];
      $census_series[] = $on_site;
      if ($on_site > $peak) {
        $peak = $on_site;
        $peak_hour = $h;
      }
    }

    // Average dwell per station and processed-count per station, in canonical
    // workflow order, omitting stations with no data for the day.
    $dwell_labels = [];
    $dwell_values = [];
    $tp_labels = [];
    $tp_values = [];
    foreach ($this->workflow->workflowStations() as $station) {
      if (!empty($dwell[$station]['count'])) {
        $dwell_labels[] = $this->workflow->label($station);
        $dwell_values[] = round($dwell[$station]['total'] / $dwell[$station]['count'] / 60, 1);
      }
      if (!empty($throughput[$station])) {
        $tp_labels[] = $this->workflow->label($station);
        $tp_values[] = $throughput[$station];
      }
    }

    $avg_duration = $durations
      ? $this->formatDuration((int) round(array_sum($durations) / count($durations)))
      : NULL;

    return [
      'charts' => [
        'dwell' => ['labels' => $dwell_labels, 'values' => $dwell_values],
        'flow' => [
          'labels' => $hour_labels,
          'arrivals' => $arr_series,
          'completions' => $comp_series,
        ],
        'census' => ['labels' => $hour_labels, 'values' => $census_series],
        'throughput' => ['labels' => $tp_labels, 'values' => $tp_values],
      ],
      'summary' => [
        'patients' => $this->patientCount($day),
        'avg_duration' => $avg_duration,
        'peak' => $peak,
        'peak_hour' => $this->formatHourLabel($peak_hour),
      ],
    ];
  }

  /**
   * Walks the revision history to total dwell time and visits per station.
   *
   * A "segment" is a continuous stay in one station, bounded by the revisions
   * where current_station changes. Dwell for a segment is the time from the
   * revision that entered the station to the revision that left it, so only
   * segments the visit has already left contribute to the average — a visit
   * still sitting in a station has no exit yet and is not counted. The terminal
   * `complete` sentinel is never a station and is excluded throughout.
   *
   * @param string $day
   *   The mission day (YYYY-MM-DD).
   *
   * @return array
   *   [0 => dwell totals keyed by station as ['total' => int, 'count' => int],
   *    1 => processed visit counts keyed by station].
   */
  private function stationDwell(string $day): array {
    $result = $this->database->query(
      "SELECT vr.vid AS vid, vr.current_station AS station, vr.revision_timestamp AS ts
       FROM {visit_revision} vr
       INNER JOIN {visit} v ON v.vid = vr.vid
       WHERE DATE(v.visit_date) = :day AND vr.revision_timestamp IS NOT NULL
       ORDER BY vr.vid ASC, vr.revision_id ASC",
      [':day' => $day]
    );

    $dwell = [];
    // Distinct visits seen per station, keyed station => [vid => TRUE].
    $seen = [];
    $current_vid = NULL;
    $segment_station = NULL;
    $segment_start = 0;

    foreach ($result as $row) {
      $vid = (int) $row->vid;
      $station = (string) $row->station;
      $ts = (int) $row->ts;

      // First revision of a visit: open its first segment.
      if ($vid !== $current_vid) {
        $current_vid = $vid;
        $segment_station = $station;
        $segment_start = $ts;
        if ($station !== StationWorkflow::COMPLETE_SENTINEL) {
          $seen[$station][$vid] = TRUE;
        }
        continue;
      }

      // Station unchanged: still in the same segment.
      if ($station === $segment_station) {
        continue;
      }

      // Station changed: close the segment we are leaving, open the new one.
      if ($segment_station !== StationWorkflow::COMPLETE_SENTINEL) {
        $duration = $ts - $segment_start;
        if ($duration >= 0) {
          $dwell[$segment_station]['total'] = ($dwell[$segment_station]['total'] ?? 0) + $duration;
          $dwell[$segment_station]['count'] = ($dwell[$segment_station]['count'] ?? 0) + 1;
        }
      }
      $segment_station = $station;
      $segment_start = $ts;
      if ($station !== StationWorkflow::COMPLETE_SENTINEL) {
        $seen[$station][$vid] = TRUE;
      }
    }

    $throughput = [];
    foreach ($seen as $station => $vids) {
      $throughput[$station] = count($vids);
    }

    return [$dwell, $throughput];
  }

  /**
   * Counts the distinct patients seen on a mission day.
   */
  private function patientCount(string $day): int {
    return (int) $this->database->query(
      'SELECT COUNT(DISTINCT patient) FROM {visit} WHERE DATE(visit_date) = :day',
      [':day' => $day]
    )->fetchField();
  }

  /**
   * Formats a 24-hour hour-of-day as a 12-hour label (e.g. "9 AM", "1 PM").
   */
  private function formatHourLabel(int $hour): string {
    $period = $hour < 12 ? 'AM' : 'PM';
    $hour12 = $hour % 12;
    if ($hour12 === 0) {
      $hour12 = 12;
    }
    return $hour12 . ' ' . $period;
  }

  /**
   * Formats a span of seconds as a compact duration (e.g. "2h 15m", "45 min").
   */
  private function formatDuration(int $seconds): string {
    $minutes = (int) round($seconds / 60);
    if ($minutes < 60) {
      return (string) $this->t('@m min', ['@m' => $minutes]);
    }
    $hours = intdiv($minutes, 60);
    $remainder = $minutes % 60;
    return $remainder > 0
      ? (string) $this->t('@hh @mm', ['@hh' => $hours . 'h', '@mm' => $remainder . 'm'])
      : (string) $this->t('@hh', ['@hh' => $hours . 'h']);
  }

  /**
   * Formats a YYYY-MM-DD day as a human-readable label.
   */
  private function formatDayLabel(string $day): string {
    $date = DrupalDateTime::createFromFormat('Y-m-d', $day);
    return $date ? $date->format('M j, Y') : $day;
  }

}
