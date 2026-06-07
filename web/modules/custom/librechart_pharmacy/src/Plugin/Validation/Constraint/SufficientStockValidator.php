<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\librechart_pharmacy\Entity\DrugInventory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the SufficientStock constraint on a prescription item.
 *
 * Compares the additional units a fulfillment would consume (the new quantity
 * minus any already-applied quantity, read via loadUnchanged so re-saves don't
 * double count) against the drug's single stock pool.
 */
final class SufficientStockValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof ContentEntityInterface || $entity->getEntityTypeId() !== 'prescription_item') {
      return;
    }
    if (!$constraint instanceof SufficientStockConstraint) {
      return;
    }
    // Only fulfilled items draw stock.
    if (!(bool) $entity->get('prescription_filled')->value) {
      return;
    }
    $drug_id = (int) $entity->get('drug')->target_id;
    $quantity = max(0, (int) $entity->get('quantity_dispensed')->value);
    if ($drug_id <= 0 || $quantity <= 0) {
      return;
    }

    // Units already deducted by this item's current persisted state.
    $already_applied = 0;
    if (!$entity->isNew()) {
      $stored = $this->entityTypeManager->getStorage('prescription_item')->loadUnchanged($entity->id());
      if (
        $stored instanceof ContentEntityInterface
        && (bool) $stored->get('prescription_filled')->value
        && (int) $stored->get('drug')->target_id === $drug_id
      ) {
        $already_applied = max(0, (int) $stored->get('quantity_dispensed')->value);
      }
    }

    $additional = $quantity - $already_applied;
    if ($additional <= 0) {
      return;
    }

    $inventory = DrugInventory::primaryForDrug($drug_id);
    $available = $inventory !== NULL ? $inventory->availableStock() : 0;
    if ($additional > $available) {
      $drug = $entity->get('drug')->entity;
      $this->context->buildViolation($constraint->message)
        ->setParameter('@qty', (string) $available)
        ->setParameter('@drug', $drug !== NULL ? (string) $drug->label() : (string) $drug_id)
        ->atPath('prescription_filled')
        ->addViolation();
    }
  }

}
