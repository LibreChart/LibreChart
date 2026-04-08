<?php

declare(strict_types=1);

namespace Drupal\librechart_patient;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for Patient entities.
 *
 * Maps granular permissions (create/edit/delete/view patient entities) to
 * entity CRUD operations, allowing role-based access without requiring the
 * administer permission.
 */
class PatientAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, mixed $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer patient entities')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view patient entities'),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit any patient entities'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete any patient entities'),
      'view all revisions', 'view revision' => AccessResult::allowedIfHasPermission($account, 'view patient entity revisions'),
      'revert revision', 'delete revision' => AccessResult::allowedIfHasPermission($account, 'administer patient entities'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, mixed $entity_bundle = NULL): AccessResult {
    if ($account->hasPermission('administer patient entities')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::allowedIfHasPermission($account, 'create patient entities');
  }

}
