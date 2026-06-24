<?php

declare(strict_types=1);

namespace Drupal\librechart_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the Daily patient report.
 *
 * Aggregates demographics, prescribing, and diagnoses for a single mission
 * day. A "mission day" is the calendar date of a visit's visit_date. Days are
 * discovered from existing visit data and presented as tabs.
 */
final class DailyPatientReportController extends ControllerBase {

  /**
   * Diagnosis field tables to aggregate, keyed by field machine name.
   *
   * Each multi-value diagnosis base field has its own data table named
   * visit__{field}, with the term id stored in {field}_target_id.
   */
  private const DX_FIELDS = [
    'dx_cardiac',
    'dx_derm',
    'dx_endo',
    'dx_ent',
    'dx_eye',
    'dx_gi',
    'dx_gyn_ob',
    'dx_mental_health',
    'dx_muscular_skeletal',
    'dx_neuro',
    'dx_resp',
    'dx_uro_genital',
    'dx_vascular',
    'dx_wound_ostomy',
  ];

  /**
   * Maximum rows shown in the "top" medication and diagnosis charts.
   */
  private const TOP_LIMIT = 10;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The active database connection.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
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
        '#markup' => '<p>' . $this->t('No visits have been recorded yet, so there is nothing to report.') . '</p>',
        '#cache' => ['tags' => ['visit_list'], 'max-age' => 0],
      ];
    }

    // Resolve the selected day: a valid "day" query param, else the latest.
    $available = array_column($days, 'day');
    $requested = $request->query->get('day');
    $selected = in_array($requested, $available, TRUE) ? $requested : end($available);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lc-daily-report']],
      '#attached' => [
        'library' => ['librechart_reports/daily_report'],
        'drupalSettings' => [
          'librechartReports' => [
            'daily' => $this->buildChartData($selected),
          ],
        ],
      ],
      '#cache' => ['tags' => ['visit_list'], 'max-age' => 0],
    ];

    $build['tabs'] = $this->buildTabs($days, $selected);
    $build['summary'] = $this->buildSummary($selected);
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
        '#url' => Url::fromRoute('librechart_reports.daily', [], ['query' => ['day' => $day['day']]]),
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
   * Builds the summary line for the selected day.
   *
   * @param string $day
   *   The selected mission day (YYYY-MM-DD).
   *
   * @return array
   *   A render array.
   */
  private function buildSummary(string $day): array {
    $patients = (int) $this->database->query(
      'SELECT COUNT(DISTINCT patient) FROM {visit} WHERE DATE(visit_date) = :day',
      [':day' => $day]
    )->fetchField();

    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['lc-daily-report__summary']],
      '#value' => $this->t('<strong class="lc-daily-report__summary-count">@count</strong> patients seen', [
        '@count' => $patients,
      ]),
    ];
  }

  /**
   * Builds the grid of chart cards (canvas elements populated by JS).
   *
   * @return array
   *   A render array.
   */
  private function buildChartGrid(): array {
    $cards = [
      'age' => $this->t('Patients by age group'),
      'sex' => $this->t('Patients by sex'),
      'municipality' => $this->t('Patients by municipality'),
      'specialty' => $this->t('Patients by specialty'),
      'medications' => $this->t('Top prescribed medications'),
      'diagnoses' => $this->t('Most frequent diagnoses'),
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
   * Aggregates all chart datasets for a mission day.
   *
   * @param string $day
   *   The mission day (YYYY-MM-DD).
   *
   * @return array
   *   Chart-ready data keyed by chart id, each with "labels" and "values".
   */
  private function buildChartData(string $day): array {
    return [
      'day' => $day,
      'age' => $this->ageGroups($day),
      'sex' => $this->sexBreakdown($day),
      'municipality' => $this->municipalityBreakdown($day),
      'specialty' => $this->specialtyBreakdown($day),
      'medications' => $this->topMedications($day),
      'diagnoses' => $this->topDiagnoses($day),
    ];
  }

  /**
   * Patients grouped into 5-year age bands (age at time of visit).
   */
  private function ageGroups(string $day): array {
    $result = $this->database->query(
      'SELECT FLOOR(TIMESTAMPDIFF(YEAR, p.date_of_birth, v.visit_date) / 5) * 5 AS band,
              COUNT(*) AS cnt
       FROM {visit} v
       INNER JOIN {patient} p ON p.pid = v.patient
       WHERE DATE(v.visit_date) = :day
         AND p.date_of_birth IS NOT NULL
       GROUP BY band
       ORDER BY band ASC',
      [':day' => $day]
    );
    $labels = [];
    $values = [];
    foreach ($result as $row) {
      $band = (int) $row->band;
      $labels[] = $band . '–' . ($band + 4);
      $values[] = (int) $row->cnt;
    }
    return ['labels' => $labels, 'values' => $values];
  }

  /**
   * Patients grouped by sex.
   */
  private function sexBreakdown(string $day): array {
    $result = $this->database->query(
      'SELECT p.sex AS sex, COUNT(*) AS cnt
       FROM {visit} v
       INNER JOIN {patient} p ON p.pid = v.patient
       WHERE DATE(v.visit_date) = :day
       GROUP BY p.sex
       ORDER BY p.sex ASC',
      [':day' => $day]
    );
    $labels = [];
    $values = [];
    foreach ($result as $row) {
      $labels[] = $row->sex ? ucfirst($row->sex) : (string) $this->t('Unknown');
      $values[] = (int) $row->cnt;
    }
    return ['labels' => $labels, 'values' => $values];
  }

  /**
   * Patients grouped by municipality (top 14, remainder rolled into "Other").
   */
  private function municipalityBreakdown(string $day): array {
    $result = $this->database->query(
      "SELECT COALESCE(t.name, :none) AS name, COUNT(*) AS cnt
       FROM {visit} v
       INNER JOIN {patient} p ON p.pid = v.patient
       LEFT JOIN {taxonomy_term_field_data} t ON t.tid = p.municipality
       WHERE DATE(v.visit_date) = :day
       GROUP BY name
       ORDER BY cnt DESC, name ASC",
      [':day' => $day, ':none' => (string) $this->t('(Not recorded)')]
    );
    $rows = [];
    foreach ($result as $row) {
      $rows[] = ['name' => $row->name, 'cnt' => (int) $row->cnt];
    }
    return $this->rollupTail($rows, 14);
  }

  /**
   * Visits grouped by assigned specialty.
   *
   * The specialties field is multi-value, so a visit assigned to several
   * specialties is counted once under each.
   */
  private function specialtyBreakdown(string $day): array {
    $result = $this->database->query(
      'SELECT t.name AS name, COUNT(*) AS cnt
       FROM {visit} v
       INNER JOIN {visit__specialties} s ON s.entity_id = v.vid AND s.deleted = 0
       INNER JOIN {taxonomy_term_field_data} t ON t.tid = s.specialties_target_id
       WHERE DATE(v.visit_date) = :day
       GROUP BY t.name
       ORDER BY cnt DESC, name ASC',
      [':day' => $day]
    );
    return $this->labelValueRows($result);
  }

  /**
   * Most-prescribed formulary medications for the day.
   */
  private function topMedications(string $day): array {
    $result = $this->database->query(
      'SELECT t.name AS name, COUNT(*) AS cnt
       FROM {visit} v
       INNER JOIN {visit__medications} m ON m.entity_id = v.vid AND m.deleted = 0
       INNER JOIN {prescription_item} pi ON pi.id = m.medications_target_id
       INNER JOIN {taxonomy_term_field_data} t ON t.tid = pi.drug
       WHERE DATE(v.visit_date) = :day
       GROUP BY t.name
       ORDER BY cnt DESC, name ASC
       LIMIT ' . self::TOP_LIMIT,
      [':day' => $day]
    );
    return $this->labelValueRows($result);
  }

  /**
   * Most frequent diagnoses for the day, across all diagnosis fields.
   */
  private function topDiagnoses(string $day): array {
    $unions = [];
    foreach (self::DX_FIELDS as $field) {
      $unions[] = sprintf(
        'SELECT entity_id, %s AS tid FROM {visit__%s} WHERE deleted = 0',
        $field . '_target_id',
        $field
      );
    }
    $dx = implode(' UNION ALL ', $unions);

    $result = $this->database->query(
      'SELECT t.name AS name, COUNT(*) AS cnt
       FROM (' . $dx . ') dx
       INNER JOIN {visit} v ON v.vid = dx.entity_id
       INNER JOIN {taxonomy_term_field_data} t ON t.tid = dx.tid
       WHERE DATE(v.visit_date) = :day
       GROUP BY t.name
       ORDER BY cnt DESC, name ASC
       LIMIT ' . self::TOP_LIMIT,
      [':day' => $day]
    );
    return $this->labelValueRows($result);
  }

  /**
   * Converts a name/cnt result set into labels/values arrays.
   *
   * @param \Traversable<int, object> $result
   *   A query result yielding rows with "name" and "cnt" properties.
   *
   * @return array
   *   ['labels' => string[], 'values' => int[]].
   */
  private function labelValueRows(\Traversable $result): array {
    $labels = [];
    $values = [];
    foreach ($result as $row) {
      $labels[] = $row->name;
      $values[] = (int) $row->cnt;
    }
    return ['labels' => $labels, 'values' => $values];
  }

  /**
   * Keeps the top N rows and rolls the remainder into a single "Other" bar.
   *
   * @param array<int, array{name: string, cnt: int}> $rows
   *   Rows of ['name' => string, 'cnt' => int], already sorted descending.
   * @param int $limit
   *   How many named rows to keep.
   *
   * @return array
   *   ['labels' => [...], 'values' => [...]].
   */
  private function rollupTail(array $rows, int $limit): array {
    $labels = [];
    $values = [];
    $other = 0;
    foreach ($rows as $i => $row) {
      if ($i < $limit) {
        $labels[] = $row['name'];
        $values[] = $row['cnt'];
      }
      else {
        $other += $row['cnt'];
      }
    }
    if ($other > 0) {
      $labels[] = (string) $this->t('Other');
      $values[] = $other;
    }
    return ['labels' => $labels, 'values' => $values];
  }

  /**
   * Formats a YYYY-MM-DD day as a human-readable label.
   */
  private function formatDayLabel(string $day): string {
    $date = DrupalDateTime::createFromFormat('Y-m-d', $day);
    return $date ? $date->format('M j, Y') : $day;
  }

}
