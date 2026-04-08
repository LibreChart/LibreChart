<?php

declare(strict_types=1);

namespace Drupal\librechart_patient\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Checks for potential duplicate patients before creating a new record.
 *
 * Matches on exact date of birth AND last name similarity using a LIKE
 * query to catch phonetic and spelling variants during registration.
 */
class PatientDuplicateChecker {

  /**
   * Constructs a PatientDuplicateChecker.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Finds potential duplicate patients by last name and date of birth.
   *
   * @param string $last_name
   *   The last name to search for.
   * @param string $date_of_birth
   *   The date of birth string (YYYY-MM-DD).
   *
   * @return array<int|string, \Drupal\librechart_patient\Entity\PatientInterface>
   *   An array of potential duplicate Patient entities.
   *
   * @phpstan-return array<int|string, \Drupal\librechart_patient\Entity\PatientInterface>
   */
  public function findPotentialDuplicates(string $last_name, string $date_of_birth): array {
    if (empty($last_name) || empty($date_of_birth)) {
      return [];
    }

    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('patient');

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('date_of_birth', $date_of_birth)
      ->condition('last_name', '%' . $last_name . '%', 'LIKE');

    $ids = $query->execute();

    if (empty($ids)) {
      return [];
    }

    // @phpstan-ignore-next-line return.type — loadMultiple returns EntityInterface[] but all loaded items are PatientInterface.
    return $storage->loadMultiple($ids);
  }

}
