# Contract: Sticky Allergies + Inventory Report Coloring

Two presentation contracts: the always-visible allergy pills (FR-017–020, SC-005/006) and the
inventory-report row coloring (FR-021/028, SC-007).

## A. Sticky allergy pills (Visit form)

**Attachment**: library `librechart_visit/allergy_sticky` attached on `visit_add_form` /
`visit_edit_form` via `hook_form_alter`.

**Markup**: a fixed-position bar pinned to the bottom of the viewport, containing one red pill per
allergy.

```html
<div class="lc-allergy-sticky" aria-label="Patient allergies">
  <span class="lc-allergy-pill">Penicillin</span>
  <span class="lc-allergy-pill">Sulfa</span>
</div>
```

**Behavior**:

- Source of truth is the `allergies` `entity_reference_autocomplete_tags` widget value (client-side).
- On initial load and on every add/remove in that widget, the bar re-renders its pills (FR-020,
  SC-005).
- A value whose label is exactly `None` is **excluded** (FR-019, SC-006).
- When only "None" (or nothing) is present, the bar shows no pills (it may hide entirely).
- Pills are red (`.lc-allergy-pill` → red background, legible contrast).

**Required field**: `allergies` is required with default "None" (server-side; data-model §1). The
sticky component does not enforce required-ness — it only displays.

**States**:

| Widget value | Pills shown |
|--------------|-------------|
| empty (pre-default) / "None" | none |
| "Penicillin" | `Penicillin` |
| "None, Penicillin, Sulfa" | `Penicillin`, `Sulfa` |

## B. Inventory report row coloring

**Hook**: extend `librechart_pharmacy_preprocess_views_view_table()` for the `inventory_report` view.

For each row, compare `quantity_on_hand` vs `low_stock_threshold` (FR-028) and add a row class:

| Condition | Row class | Color |
|-----------|-----------|-------|
| `quantity_on_hand == 0` | `inventory-row--out-of-stock` | red |
| `0 < quantity_on_hand <= low_stock_threshold` | `inventory-row--low-stock` | yellow |
| otherwise | (none) | default |

**CSS**: defined in `librechart_pharmacy/css/pharmacy.css` (attached to the report); the conflicting
light-pink `.inventory-row--low-stock` rule in `laughh/css/style.css` is reconciled to yellow (or
removed in favor of the module rule) so low=yellow / out=red is consistent with the picker (FR-028).

**Invariants**:

- Same thresholds drive picker colors and report colors (single definition, FR-028).
- All colors self-hosted CSS; no external assets.
