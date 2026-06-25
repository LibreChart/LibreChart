<?php

declare(strict_types=1);

namespace Drupal\librechart_patient\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\librechart_patient\Entity\PatientInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Renders the patient chart page: visit form + demographics + prior visits.
 *
 * Replaces the default ContentEntityForm controller on the patient edit
 * route. Composes two independent forms (latest Visit + Patient) plus a
 * link list to prior visits, all on a single page so doctors can update
 * the current encounter and patient identity from one screen.
 */
class PatientChartController extends ControllerBase {

  /**
   * Builds the patient chart render array.
   */
  public function edit(PatientInterface $patient): array {
    $visit_storage = $this->entityTypeManager()->getStorage('visit');
    $vids = $visit_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('patient', $patient->id())
      ->sort('visit_date', 'DESC')
      ->execute();
    /** @var \Drupal\Core\Entity\ContentEntityInterface[] $visits */
    $visits = $vids ? $visit_storage->loadMultiple($vids) : [];
    $latest = $visits ? reset($visits) : NULL;

    $build = [];

    $build['patient_demographics'] = [
      '#type' => 'details',
      '#title' => $this->t('Patient demographics'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['patient-chart__demographics']],
      'form' => $this->entityFormBuilder()->getForm($patient, 'edit'),
    ];

    if ($latest !== NULL) {
      $build['latest_visit'] = [
        '#type' => 'details',
        '#title' => $this->t('Most recent visit — @date', [
          '@date' => $this->formatVisitDate($latest),
        ]),
        '#open' => TRUE,
        '#attributes' => ['class' => ['patient-chart__latest-visit']],
        'form' => $this->entityFormBuilder()->getForm($latest, 'edit'),
      ];
    }
    else {
      $build['no_visit'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['patient-chart__no-visit']],
        'message' => [
          '#markup' => '<p>' . $this->t('No visits recorded for this patient yet.') . '</p>',
        ],
        'add_link' => [
          '#type' => 'link',
          '#title' => $this->t('Add visit for this patient'),
          '#url' => Url::fromRoute('librechart_patient.add_visit', [
            'patient' => $patient->id(),
          ]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ];
    }

    $prior = array_slice($visits, 1);
    if ($prior) {
      $items = [];
      foreach ($prior as $visit) {
        /** @var \Drupal\taxonomy\TermInterface|null $site */
        $site = $visit->get('clinic_site')->entity;
        $items[] = [
          '#type' => 'link',
          '#title' => $this->t('@date — @site (@status)', [
            '@date' => $this->formatVisitDate($visit),
            '@site' => $site?->label() ?? $this->t('Unknown site'),
            '@status' => $visit->get('status')->value === 'complete'
              ? $this->t('Complete')
              : $this->t('In Progress'),
          ]),
          '#url' => $visit->toUrl('edit-form'),
        ];
      }
      $build['prior_visits'] = [
        '#type' => 'details',
        '#title' => $this->t('Prior visits (@count)', ['@count' => count($prior)]),
        '#open' => FALSE,
        '#attributes' => ['class' => ['patient-chart__prior-visits']],
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ];
    }

    $build['#cache']['tags'] = array_merge(
      $patient->getCacheTags(),
      ['visit_list:' . $patient->id()],
    );

    return $build;
  }

  /**
   * Creates a new Visit attached to the patient at the Triage station and
   * redirects to the patient chart. The patient↔visit relationship is fixed
   * at creation time; the user opens the patient page and clicks "Add visit"
   * which lands here. No intermediate form: the Visit is saved with default
   * values (patient_type=adult, status=in_progress, visit_date=now, station=
   * triage). The triage nurse then fills clinic_site and triage data via the
   * Visit form embedded in the patient chart.
   *
   * @param \Drupal\librechart_patient\Entity\PatientInterface $patient
   *   The patient the new visit belongs to (resolved by the route's entity
   *   parameter converter).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the patient edit form (chart page).
   */
  public function addVisit(PatientInterface $patient): RedirectResponse {
    $visit = $this->entityTypeManager()
      ->getStorage('visit')
      ->create([
        'patient' => $patient->id(),
        'current_station' => 'triage',
      ]);
    $visit->save();

    $this->messenger()->addStatus(
      $this->t('New visit started for @first @last. Please complete triage.', [
        '@first' => $patient->getFirstName(),
        '@last' => $patient->getLastName(),
      ])
    );

    $url = Url::fromRoute('entity.patient.edit_form', [
      'patient' => $patient->id(),
    ])->toString();
    return new RedirectResponse($url);
  }

  /**
   * Page title callback.
   */
  public function title(PatientInterface $patient): string {
    return (string) $this->t('@last, @first', [
      '@first' => $patient->getFirstName(),
      '@last' => $patient->getLastName(),
    ]);
  }

  /**
   * Formats a visit_date value as YYYY-MM-DD for display.
   */
  private function formatVisitDate($visit): string {
    $raw = (string) $visit->get('visit_date')->value;
    if ($raw === '') {
      return '';
    }
    // visit_date may be stored as an ISO 'Y-m-d…' string or, for visits created
    // via the default-value callback, a Unix timestamp. Handle both.
    $ts = ctype_digit($raw) ? (int) $raw : strtotime(substr($raw, 0, 10));
    return $ts ? date('d/m/Y', $ts) : '';
  }

}
