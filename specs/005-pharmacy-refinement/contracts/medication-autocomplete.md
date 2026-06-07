# Contract: Medication Autocomplete (stock-aware typeahead)

Custom HTTP endpoint + client behavior backing the medication picker (FR-001/002/003/004, FR-028).

## Route

- **Name**: `librechart_pharmacy.medication_autocomplete`
- **Path**: `/pharmacy/autocomplete/medications`
- **Method**: GET
- **Access**: a permission held by prescribers (e.g., `create prescription_item entities` or
  `view drug_inventory entities`); authenticated only.
- **Query params**:
  - `q` (string, required): the characters typed.

## Request → Response

The controller queries `DrugInventory`, label-matches the referenced `drug` term name against `q`
(CONTAINS, case-insensitive), orders best matches first, and returns **at most 10** items.

**Response**: `200 OK`, `application/json`, an array (max length 10):

```json
[
  { "value": "Amoxicillin (42)", "label": "Amoxicillin", "tid": 42,
    "status": "in_stock",     "qty": 120, "selectable": true },
  { "value": "Ibuprofen (7)",   "label": "Ibuprofen",   "tid": 7,
    "status": "low_stock",    "qty": 8,   "selectable": true },
  { "value": "Metformin (9)",   "label": "Metformin",   "tid": 9,
    "status": "out_of_stock", "qty": 0,   "selectable": false }
]
```

Field meanings:

| Field | Meaning |
|-------|---------|
| `tid` | Drug taxonomy term id (stored in the line item's `drug` field). |
| `status` | `in_stock` \| `low_stock` \| `out_of_stock`, per FR-028 (`qty==0` → out; `0<qty<=threshold` → low; else in). |
| `qty` | Current `quantity_on_hand` (for display/tooltip). |
| `selectable` | `false` only for `out_of_stock` (FR-003). |

## Client behavior (JS library `medication-autocomplete`)

Extends Drupal core's autocomplete (`core/drupal.autocomplete`, jQuery-UI based — self-hosted):

1. **Row coloring**: `low_stock` → yellow background (`.medication--low-stock`); `out_of_stock` → red
   background (`.medication--out-of-stock`). `in_stock` → default.
2. **Non-selectable**: rows with `selectable: false` are rendered disabled — clicking/Enter does not
   populate the field (FR-003).
3. **Selection** sets the visible label and stores `tid` for the line item's `drug` value.
4. **"View inventory" link**: rendered next to the field, `href="/reports/inventory"`,
   `target="_blank"`, `rel="noopener"` (FR-004).

## Invariants

- Never returns more than 10 items.
- `status`/`selectable` are computed server-side from live inventory (single source of truth);
  the client must not re-derive stock from stale data.
- All assets self-hosted (offline constraint) — no external autocomplete library.
