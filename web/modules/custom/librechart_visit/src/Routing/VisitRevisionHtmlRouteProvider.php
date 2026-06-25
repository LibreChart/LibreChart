<?php

declare(strict_types=1);

namespace Drupal\librechart_visit\Routing;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\librechart_visit\Controller\VisitRevisionHistoryController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds an editable revision route and retargets the revision overview links.
 *
 * Extends core's revision routes with an 'entity.visit.revision_edit' route
 * (a Visit edit form bound to a specific revision) and swaps the version
 * history controller for one that links revisions to that editable display.
 */
class VisitRevisionHtmlRouteProvider extends RevisionHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  public function getRoutes(EntityTypeInterface $entity_type): RouteCollection {
    $collection = parent::getRoutes($entity_type);

    if ($revision_edit_route = $this->getRevisionEditRoute($entity_type)) {
      $collection->add('entity.' . $entity_type->id() . '.revision_edit', $revision_edit_route);
    }

    return $collection;
  }

  /**
   * {@inheritdoc}
   *
   * Use the Visit-specific controller so revision dates link to the editable
   * display rather than the read-only revision view.
   */
  protected function getVersionHistoryRoute(EntityTypeInterface $entity_type): ?Route {
    $route = parent::getVersionHistoryRoute($entity_type);
    if ($route instanceof Route) {
      $route->setDefault('_controller', VisitRevisionHistoryController::class);
    }
    return $route;
  }

  /**
   * Builds the route for editing a specific revision.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   *
   * @return \Symfony\Component\Routing\Route|null
   *   The revision edit route, or NULL when the entity type defines no
   *   'revision-edit' link template.
   */
  protected function getRevisionEditRoute(EntityTypeInterface $entity_type): ?Route {
    if (!$entity_type->hasLinkTemplate('revision-edit')) {
      return NULL;
    }

    $entity_type_id = $entity_type->id();
    $revision_parameter_name = $entity_type_id . '_revision';
    return (new Route($entity_type->getLinkTemplate('revision-edit')))
      ->addDefaults([
        '_entity_form' => $entity_type_id . '.revision_edit',
        '_title' => 'Edit revision',
      ])
      // Editing a revision is an update to the visit, so gate on update access.
      ->setRequirement('_entity_access', $entity_type_id . '.update')
      ->setOption('_admin_route', TRUE)
      ->setOption('parameters', [
        $entity_type_id => [
          'type' => 'entity:' . $entity_type_id,
        ],
        $revision_parameter_name => [
          'type' => 'entity_revision:' . $entity_type_id,
        ],
      ]);
  }

}
