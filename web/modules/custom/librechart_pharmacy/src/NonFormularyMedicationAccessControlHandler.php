<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for NonFormularyMedication entities.
 *
 * Maps granular permissions to entity CRUD operations so pharmacy and clinical
 * staff can record outside-hospital medication orders without the administer
 * permission.
 */
class NonFormularyMedicationAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, mixed $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer non_formulary_medication entities')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view non_formulary_medication entities'),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit any non_formulary_medication entities'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete any non_formulary_medication entities'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, mixed $entity_bundle = NULL): AccessResult {
    if ($account->hasPermission('administer non_formulary_medication entities')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::allowedIfHasPermission($account, 'create non_formulary_medication entities');
  }

}
