# Contract: Medication Line-Item Form (IEF subform) & Pharmacy group

UI/form contract for the Pharmacy section of the Visit form (FR-005/006/007, FR-015/016).

## Pharmacy group (`group_pharmacy`) composition — after change

| Element | Source | Notes |
|---------|--------|-------|
| Medications (repeating line items) | `Visit.medications` via IEF complex widget | "Add another" + remove (FR-006). |
| "View inventory" link | injected in `hook_form_alter` | `target="_blank"` → `/reports/inventory` (FR-004). |
| Notes to pharmacist | `Visit.notes_to_pharmacist` (`string_long`) | Plain textarea, no WYSIWYG (FR-016). |
| ~~Pharmacist name~~ | removed | FR-015. |

## Each medication line item (one IEF subform = one `prescription_item`)

| Field shown | `prescription_item` field | Widget | Who edits |
|-------------|---------------------------|--------|-----------|
| Medication | `drug` | stock-aware autocomplete (see `medication-autocomplete.md`) | prescriber |
| Quantity | `quantity_dispensed` | number (≥1) | prescriber |
| Fulfilled | `prescription_filled` | checkbox | **Pharmacist role only** (any station) |
| Fulfilled by | `fulfilled_by` | select (taxonomy `pharmacist`) | **Pharmacist role only**; auto-filled, editable |

Behavior:

- A clinician can add/remove multiple medication line items, each rendered as its own fieldset
  (FR-005/006).
- On save, each child's `visit` reference is set to the parent visit (keeps inventory reports
  working) — handled in a submit/presave step, not shown to the user.
- Fulfilled / Fulfilled-by fields render read-only or hidden for non-pharmacists (D5/VR-3).
- Insufficient-stock block (VR-1) surfaces as an inline validation error on the offending row; the
  rest of the form state is preserved.

## Acceptance hooks (maps to spec)

- AS US1.4 — quantity field present in the same fieldset as the medication.
- AS US1.5 — "Add another" yields an independent fieldset.
- AS US2.1/2.2 — checking/unchecking Fulfilled adjusts inventory (see inventory-adjustment.md).
- AS US2.3 — Fulfilled-by options are `pharmacist` taxonomy terms.
- AS US5.1/5.2 — no Pharmacist-name field; Notes is a plain textarea.
