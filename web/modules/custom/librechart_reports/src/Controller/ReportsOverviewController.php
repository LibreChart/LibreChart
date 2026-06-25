<?php

declare(strict_types=1);

namespace Drupal\librechart_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Lists the available reports.
 */
final class ReportsOverviewController extends ControllerBase {

  /**
   * Builds the reports landing page.
   *
   * @return array
   *   A render array listing the available reports.
   */
  public function overview(): array {
    $reports = [
      [
        'title' => $this->t('My Dashboard'),
        'description' => $this->t('Patients you have worked on, with quick links to revisit their records.'),
        'url' => Url::fromRoute('entity.dashboard.canonical', ['dashboard' => 'my_dashboard']),
      ],
      [
        'title' => $this->t('Daily patient report'),
        'description' => $this->t('Demographics, top prescribed medications, and most frequent diagnoses for a single mission day.'),
        'url' => Url::fromRoute('librechart_reports.daily'),
      ],
      [
        'title' => $this->t('Traffic report'),
        'description' => $this->t('Average time spent in each station, arrivals versus completions by hour, and how many patients were on site at once.'),
        'url' => Url::fromRoute('librechart_reports.traffic'),
      ],
    ];

    $items = [];
    foreach ($reports as $report) {
      $items[] = [
        '#type' => 'link',
        '#title' => $report['title'],
        '#url' => $report['url'],
        '#prefix' => '<div class="lc-report-card"><h2 class="lc-report-card__title">',
        '#suffix' => '</h2><p class="lc-report-card__desc">' . $report['description'] . '</p></div>',
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['lc-reports-overview']],
      '#attached' => ['library' => ['librechart_reports/daily_report']],
      'list' => $items,
    ];
  }

}
