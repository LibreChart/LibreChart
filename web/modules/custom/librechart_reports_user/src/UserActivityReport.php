<?php

declare(strict_types=1);

namespace Drupal\librechart_reports_user;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Aggregates a single user's editing activity across patient records.
 *
 * A user is considered to have "worked on" a patient when they authored any
 * revision of one of that patient's visits. Authorship is recorded on every
 * save in {visit_revision}.revision_uid, so this includes clinical edits as
 * well as station transitions.
 */
final class UserActivityReport {

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
   * Maximum rows shown in the "top diagnoses" chart.
   */
  private const TOP_LIMIT = 10;

  /**
   * Constructs the report service.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The active database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, used to compute patient ages.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the distinct patients a user has worked on.
   *
   * @param int $uid
   *   The user id to report on.
   *
   * @return array<int, array{
   *   pid: int,
   *   name: string,
   *   age: int|null,
   *   sex: string|null,
   *   visits_touched: int,
   *   last_edited: int,
   *   latest_vid: int,
   *   current_station: string|null,
   *   status: string|null,
   *   }>
   *   Patient rows ordered by most recently edited first.
   */
  public function patientsEditedBy(int $uid): array {
    if ($uid <= 0) {
      return [];
    }

    // Aggregate every visit the user has touched into one row per patient,
    // then join the patient demographics and the most recent touched visit
    // for that patient. The latest visit is approximated by the highest vid,
    // which is monotonic with visit creation.
    $result = $this->database->query(
      'SELECT p.pid AS pid,
              p.first_name AS first_name,
              p.last_name AS last_name,
              p.date_of_birth AS dob,
              p.sex AS sex,
              agg.last_edited AS last_edited,
              agg.visits_touched AS visits_touched,
              agg.latest_vid AS latest_vid,
              lv.current_station AS current_station,
              lv.status AS status
       FROM (
         SELECT patient AS pid,
                MAX(revision_timestamp) AS last_edited,
                COUNT(DISTINCT vid) AS visits_touched,
                MAX(vid) AS latest_vid
         FROM {visit_revision}
         WHERE revision_uid = :uid AND patient IS NOT NULL
         GROUP BY patient
       ) agg
       INNER JOIN {patient} p ON p.pid = agg.pid
       LEFT JOIN {visit} lv ON lv.vid = agg.latest_vid
       ORDER BY agg.last_edited DESC',
      [':uid' => $uid]
    );

    $now = $this->time->getRequestTime();
    $patients = [];
    foreach ($result as $row) {
      $patients[] = [
        'pid' => (int) $row->pid,
        'name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
        'age' => $this->ageFromDob($row->dob, $now),
        'sex' => $row->sex,
        'visits_touched' => (int) $row->visits_touched,
        'last_edited' => (int) $row->last_edited,
        'latest_vid' => (int) $row->latest_vid,
        'current_station' => $row->current_station,
        'status' => $row->status,
      ];
    }
    return $patients;
  }

  /**
   * Returns headline counts of a user's activity.
   *
   * @param int $uid
   *   The user id to report on.
   *
   * @return array{
   *   patients: int,
   *   today_patients: int,
   *   mission_day: string|null,
   *   }
   *   Distinct patients worked on, distinct patients touched on the current
   *   mission day, and that mission day (YYYY-MM-DD).
   */
  public function summaryStats(int $uid): array {
    $empty = [
      'patients' => 0,
      'today_patients' => 0,
      'mission_day' => NULL,
    ];
    if ($uid <= 0) {
      return $empty;
    }

    $totals = $this->database->query(
      'SELECT COUNT(DISTINCT patient) AS patients
       FROM {visit_revision}
       WHERE revision_uid = :uid AND patient IS NOT NULL',
      [':uid' => $uid]
    )->fetchObject();

    // The current mission day is the most recent date any visit is recorded
    // for, mirroring the Daily patient report's notion of a mission day.
    $mission_day = $this->database->query(
      'SELECT MAX(DATE(visit_date)) FROM {visit}'
    )->fetchField();

    $today_patients = 0;
    if ($mission_day) {
      $today_patients = (int) $this->database->query(
        'SELECT COUNT(DISTINCT vr.patient)
         FROM {visit_revision} vr
         INNER JOIN {visit} v ON v.vid = vr.vid
         WHERE vr.revision_uid = :uid AND DATE(v.visit_date) = :day',
        [':uid' => $uid, ':day' => $mission_day]
      )->fetchField();
    }

    return [
      'patients' => (int) ($totals->patients ?? 0),
      'today_patients' => $today_patients,
      'mission_day' => $mission_day ?: NULL,
    ];
  }

  /**
   * Aggregates chart datasets describing a user's activity.
   *
   * All datasets are scoped to the visits the user has touched (any visit for
   * which they authored a revision) or the distinct patients on those visits.
   *
   * @param int $uid
   *   The user id to report on.
   *
   * @return array<string, array{labels: array<int, string>, values: array<int, int>}>
   *   Chart-ready data keyed by chart id, each with "labels" and "values".
   */
  public function chartData(int $uid): array {
    if ($uid <= 0) {
      return [];
    }
    return [
      'activity' => $this->activityOverTime($uid),
      'specialty' => $this->specialtyForUser($uid),
      'diagnoses' => $this->diagnosesForUser($uid),
      'age' => $this->ageGroupsForUser($uid),
      'sex' => $this->sexForUser($uid),
    ];
  }

  /**
   * Visits the user touched, grouped by mission day.
   */
  private function activityOverTime(int $uid): array {
    $result = $this->database->query(
      'SELECT DATE(v.visit_date) AS day, COUNT(DISTINCT v.vid) AS cnt
       FROM {visit} v
       WHERE v.vid IN (
         SELECT DISTINCT vid FROM {visit_revision} WHERE revision_uid = :uid
       )
       GROUP BY day
       ORDER BY day ASC',
      [':uid' => $uid]
    );
    $labels = [];
    $values = [];
    foreach ($result as $row) {
      $labels[] = $row->day ? date('M j', strtotime($row->day)) : (string) $row->day;
      $values[] = (int) $row->cnt;
    }
    return ['labels' => $labels, 'values' => $values];
  }

  /**
   * Visits the user touched, grouped by assigned specialty.
   */
  private function specialtyForUser(int $uid): array {
    $result = $this->database->query(
      'SELECT t.name AS name, COUNT(DISTINCT v.vid) AS cnt
       FROM {visit} v
       INNER JOIN {visit__specialties} s ON s.entity_id = v.vid AND s.deleted = 0
       INNER JOIN {taxonomy_term_field_data} t ON t.tid = s.specialties_target_id
       WHERE v.vid IN (
         SELECT DISTINCT vid FROM {visit_revision} WHERE revision_uid = :uid
       )
       GROUP BY t.name
       ORDER BY cnt DESC, name ASC',
      [':uid' => $uid]
    );
    return $this->labelValueRows($result);
  }

  /**
   * Most frequent diagnoses across the visits the user touched.
   */
  private function diagnosesForUser(int $uid): array {
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
       INNER JOIN {taxonomy_term_field_data} t ON t.tid = dx.tid
       WHERE dx.entity_id IN (
         SELECT DISTINCT vid FROM {visit_revision} WHERE revision_uid = :uid
       )
       GROUP BY t.name
       ORDER BY cnt DESC, name ASC
       LIMIT ' . self::TOP_LIMIT,
      [':uid' => $uid]
    );
    return $this->labelValueRows($result);
  }

  /**
   * Distinct patients the user touched, grouped into 5-year age bands.
   */
  private function ageGroupsForUser(int $uid): array {
    $result = $this->database->query(
      'SELECT FLOOR(TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) / 5) * 5 AS band,
              COUNT(*) AS cnt
       FROM {patient} p
       WHERE p.date_of_birth IS NOT NULL
         AND p.pid IN (
           SELECT DISTINCT patient FROM {visit_revision}
           WHERE revision_uid = :uid AND patient IS NOT NULL
         )
       GROUP BY band
       ORDER BY band ASC',
      [':uid' => $uid]
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
   * Distinct patients the user touched, grouped by sex.
   */
  private function sexForUser(int $uid): array {
    $result = $this->database->query(
      'SELECT p.sex AS name, COUNT(*) AS cnt
       FROM {patient} p
       WHERE p.pid IN (
         SELECT DISTINCT patient FROM {visit_revision}
         WHERE revision_uid = :uid AND patient IS NOT NULL
       )
       GROUP BY p.sex
       ORDER BY p.sex ASC',
      [':uid' => $uid]
    );
    $labels = [];
    $values = [];
    foreach ($result as $row) {
      $labels[] = $row->name ? ucfirst($row->name) : 'Unknown';
      $values[] = (int) $row->cnt;
    }
    return ['labels' => $labels, 'values' => $values];
  }

  /**
   * Converts a name/cnt result set into labels/values arrays.
   *
   * @param \Traversable<int, object> $result
   *   A query result yielding rows with "name" and "cnt" properties.
   *
   * @return array{labels: array<int, string>, values: array<int, int>}
   *   The labels and values arrays.
   */
  private function labelValueRows(\Traversable $result): array {
    $labels = [];
    $values = [];
    foreach ($result as $row) {
      $labels[] = (string) $row->name;
      $values[] = (int) $row->cnt;
    }
    return ['labels' => $labels, 'values' => $values];
  }

  /**
   * Computes whole-year age from a date-of-birth string.
   *
   * @param string|null $dob
   *   The date of birth (YYYY-MM-DD or a parseable datetime), or NULL.
   * @param int $now
   *   The reference timestamp.
   *
   * @return int|null
   *   The age in whole years, or NULL when the date is missing or invalid.
   */
  private function ageFromDob(?string $dob, int $now): ?int {
    if (empty($dob)) {
      return NULL;
    }
    try {
      $birth = new \DateTime($dob);
    }
    catch (\Exception) {
      return NULL;
    }
    $reference = (new \DateTime())->setTimestamp($now);
    if ($birth > $reference) {
      return NULL;
    }
    return (int) $birth->diff($reference)->y;
  }

}
