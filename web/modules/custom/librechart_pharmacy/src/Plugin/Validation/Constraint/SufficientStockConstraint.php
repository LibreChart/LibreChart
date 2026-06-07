<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Blocks fulfilling a prescription beyond the drug's available stock.
 *
 * See specs/005-pharmacy-refinement/contracts/inventory-adjustment.md (VR-1).
 */
#[Constraint(
  id: 'SufficientStock',
  label: new TranslatableMarkup('Sufficient drug stock', [], ['context' => 'Validation']),
)]
final class SufficientStockConstraint extends SymfonyConstraint {

  /**
   * Violation message; states the remaining stock quantity (FR-013).
   *
   * @var string
   */
  public string $message = 'Insufficient stock: only @qty unit(s) of @drug remain. Reduce the quantity or restock before fulfilling.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return SufficientStockValidator::class;
  }

}
