<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for InventoryReceipt entities.
 *
 * Maps granular permissions to entity CRUD operations, allowing pharmacy
 * staff roles access without requiring the administer permission.
 */
class InventoryReceiptAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, mixed $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer inventory_receipt entities')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view inventory_receipt entities'),
      'update', 'delete' => AccessResult::allowedIfHasPermission($account, 'administer inventory_receipt entities'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, mixed $entity_bundle = NULL): AccessResult {
    if ($account->hasPermission('administer inventory_receipt entities')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::allowedIfHasPermission($account, 'create inventory_receipt entities');
  }

}
