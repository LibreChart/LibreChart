# Contract: Inventory Adjustment (reversible, idempotent, blocking)

Server-side behavior for `prescription_item` fulfillment against `drug_inventory`
(FR-010/011/012/013, SC-003/SC-004). Single clinic-wide pool → match `DrugInventory` by `drug` only.

## Definitions

- `effective(item) = item.prescription_filled ? (int) item.quantity_dispensed : 0`
- `original` = `$entity->original` (the pre-save state) on update; treated as effective `0` on insert.

## Validation (presave) — VR-1 / FR-013

Precondition to a fulfillment becoming/remaining TRUE with quantity `Q`:

```
available = DrugInventory(drug).quantity_on_hand           # the pool BEFORE this item's effect
required_additional = max(0, Q - effective(original))      # extra units this save would consume
IF required_additional > available:
    BLOCK SAVE
    violation message: "Insufficient stock: only {available} unit(s) of {drug} remain."
    result: prescription_filled stays unchecked; no decrement
```

- The message MUST state the remaining quantity (`available`).
- With IEF, the violation surfaces inline on the Visit form against the offending medication row.

## Mutation (postsave) — FR-010/011/012

```
delta = effective(new) - effective(original)
DrugInventory(drug).quantity_on_hand -= delta            # delta>0 decrements; delta<0 restores
clamp at >= 0 (defensive; VR-1 prevents underflow in normal flow)
```

| Scenario | delta | Net effect |
|----------|-------|------------|
| Fulfill qty 5 (was unfilled) | +5 | −5 |
| Unfulfill (was filled qty 5) | −5 | +5 |
| Change filled qty 5 → 8 | +3 | −3 |
| Re-save, no effective change | 0 | none (idempotent) |

## Deletion (predelete)

```
IF effective(item) > 0:
    DrugInventory(drug).quantity_on_hand += effective(item)   # restore on removal of a filled item
```

## Authorization — VR-3 / FR-008/009, Clarification Q1

`prescription_filled` and `fulfilled_by` are editable only by users with the `pharmacist` role
(`hook_entity_field_access`), independent of the visit's current station. Non-pharmacist users see
these fields read-only/disabled and cannot trigger an adjustment.

## Out of scope / deprecated

- The previous `override_reason` low-stock override path is removed (replaced by the hard block).
  The column may remain in storage but is unused by forms/logic.

## Invariants (testable)

- I-1: `quantity_on_hand` never goes negative (SC-004).
- I-2: Toggling fulfilled on then off returns `quantity_on_hand` to its starting value (SC-003).
- I-3: Saving an unchanged item does not alter inventory (idempotent).
- I-4: The sum of all `effective(item)` decrements for a drug equals the total reduction from its
  baseline (no drift across edits).
