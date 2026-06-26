<?php

declare(strict_types=1);

namespace Drupal\librechart_visit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\librechart_visit\Entity\Visit;
use Drupal\librechart_visit\Service\StationWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Toggles the issue flag on a single station of a visit's station strip.
 *
 * The station strip lets any clinical user mark a station with a red flag to
 * signal an unresolved issue or that the patient must return there. The flag is
 * stored on the visit's multi-value `flagged_stations` field and persists until
 * toggled off again. This controller is the AJAX endpoint behind that toggle;
 * it writes only the flag set and leaves all other clinical fields untouched.
 */
final class StationFlagController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * @param \Drupal\librechart_visit\Service\StationWorkflow $workflow
   *   The station workflow service, used to validate the station name.
   */
  public function __construct(
    protected readonly StationWorkflow $workflow,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('librechart_visit.station_workflow'),
    );
  }

  /**
   * Toggles the flag for one station and returns its new state.
   *
   * @param \Drupal\librechart_visit\Entity\Visit $visit
   *   The visit whose station strip is being flagged.
   * @param string $station
   *   The station machine name to toggle.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON: {station, flagged} where `flagged` is the resulting state.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When the station is not a real workflow station.
   */
  public function toggle(Visit $visit, string $station): JsonResponse {
    if (!in_array($station, $this->workflow->workflowStations(), TRUE)) {
      throw new NotFoundHttpException();
    }

    $flagged = $this->flaggedStations($visit);
    if (in_array($station, $flagged, TRUE)) {
      $flagged = array_values(array_diff($flagged, [$station]));
      $now_flagged = FALSE;
    }
    else {
      $flagged[] = $station;
      $now_flagged = TRUE;
    }

    $visit->set('flagged_stations', $flagged);
    $visit->save();

    return new JsonResponse([
      'station' => $station,
      'flagged' => $now_flagged,
    ]);
  }

  /**
   * Reads the visit's current set of flagged station machine names.
   *
   * @param \Drupal\librechart_visit\Entity\Visit $visit
   *   The visit to read from.
   *
   * @return string[]
   *   The flagged station machine names.
   */
  private function flaggedStations(Visit $visit): array {
    $values = [];
    foreach ($visit->get('flagged_stations')->getValue() as $delta) {
      $values[] = (string) ($delta['value'] ?? '');
    }
    return $values;
  }

}
