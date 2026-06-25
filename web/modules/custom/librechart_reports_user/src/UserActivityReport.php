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
