<?php

declare(strict_types=1);

namespace Drupal\librechart_patient\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;

/**
 * Defines the interface for Patient entities.
 */
interface PatientInterface extends ContentEntityInterface, RevisionLogInterface {

  /**
   * Gets the patient's first name.
   *
   * @return string
   *   The first name.
   */
  public function getFirstName(): string;

  /**
   * Gets the patient's last name.
   *
   * @return string
   *   The last name.
   */
  public function getLastName(): string;

  /**
   * Gets the patient's cedula (national ID number).
   *
   * @return string
   *   The cedula.
   */
  public function getCedula(): string;

  /**
   * Gets the patient's date of birth.
   *
   * @return string
   *   The date of birth as a string (YYYY-MM-DD).
   */
  public function getDateOfBirth(): string;

  /**
   * Gets the patient's sex.
   *
   * @return string
   *   The sex value: male, female, or other.
   */
  public function getSex(): string;

  /**
   * Gets the patient's municipality reference ID.
   *
   * @return int|null
   *   The municipality term ID, or NULL if not set.
   */
  public function getMunicipality(): ?int;

  /**
   * Gets the patient's village/town reference ID.
   *
   * @return int|null
   *   The village_town term ID, or NULL if not set.
   */
  public function getVillage(): ?int;

}
