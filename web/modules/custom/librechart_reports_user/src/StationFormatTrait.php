<?php

declare(strict_types=1);

namespace Drupal\librechart_reports_user;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Formats a visit's station and status into a human-readable label.
 *
 * Consuming classes must provide a t() method (e.g. via StringTranslationTrait,
 * which block plugins inherit through PluginBase).
 */
trait StationFormatTrait {

  /**
   * Builds a display label for a visit's current station and status.
   *
   * @param string|null $status
   *   The visit status (in_progress or complete).
   * @param string|null $station
   *   The visit current_station machine name.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   A human-readable status label.
   */
  protected function formatVisitStatus(?string $status, ?string $station): TranslatableMarkup|string {
    if ($status === 'complete') {
      return $this->t('Complete');
    }
    $labels = [
      'registration' => $this->t('Registration'),
      'triage' => $this->t('Triage'),
      'lab' => $this->t('Lab'),
      'clinical' => $this->t('Clinical'),
      'pt' => $this->t('Physical therapy'),
      'optometry' => $this->t('Optometry'),
      'education' => $this->t('Education'),
      'pharmacy' => $this->t('Pharmacy'),
      'discharge' => $this->t('Discharge'),
      'complete' => $this->t('Complete'),
    ];
    if ($station === NULL || $station === '') {
      return $this->t('In progress');
    }
    return $labels[$station] ?? ucfirst($station);
  }

}
