<?php

declare(strict_types=1);

namespace Drupal\librechart_patient\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Swaps the controller on the patient edit route to render the chart page.
 */
class PatientRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.patient.edit_form');
    if ($route === NULL) {
      return;
    }

    $route->setDefaults([
      '_controller' => '\Drupal\librechart_patient\Controller\PatientChartController::edit',
      '_title_callback' => '\Drupal\librechart_patient\Controller\PatientChartController::title',
    ] + $route->getDefaults());

    // Remove the _entity_form default so Drupal's entity form controller
    // does not intercept the request.
    $defaults = $route->getDefaults();
    unset($defaults['_entity_form']);
    $route->setDefaults($defaults);
  }

}
