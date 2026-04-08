<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for PrescriptionItem entities.
 *
 * Maps granular permissions to entity CRUD operations, allowing pharmacy
 * staff roles access without requiring the administer permission.
 */
class PrescriptionItemAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, mixed $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer prescription_item entities')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view prescription_item entities'),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit any prescription_item entities'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete any prescription_item entities'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, mixed $entity_bundle = NULL): AccessResult {
    if ($account->hasPermission('administer prescription_item entities')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::allowedIfHasPermission($account, 'create prescription_item entities');
  }

}
