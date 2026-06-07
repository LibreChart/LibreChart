# Phase 1 Data Model: Pharmacy Section Refinement

Scope: entities/fields touched by this feature, their validation rules, state behavior, and the
schema migrations required. Machine names match the existing codebase
(`web/modules/custom/librechart_visit`, `web/modules/custom/librechart_pharmacy`).

---

## 1. Visit entity (`visit`) — `librechart_visit`

### Fields ADDED

| Field | Type | Card. | Required | Notes |
|-------|------|-------|----------|-------|
| `medications` | entity_reference → `prescription_item` | unlimited | No | IEF "complex" widget in the Pharmacy group. Children get their `visit` back-reference set on save. |
| `education_referrals` | entity_reference_revisions → paragraph `education_referral` | unlimited | No | Paragraphs widget in the Education group. |
| `pos` | boolean | 1 | No | Default `FALSE`. Label "POS" (referral to a local provider). In Education group. |
| `pos_details` | string_long (plain) | 1 | No | `string_textarea` widget. Conditionally visible when `pos` is checked. |

### Fields CHANGED

| Field | Change |
|-------|--------|
| `allergies` | Now **required**; gains a **default-value callback** resolving the `allergies` term "None" (created if missing). Cardinality stays unlimited; widget stays `entity_reference_autocomplete_tags`. |
| `notes_to_pharmacist` | Type **`text_long` → `string_long`** (plain); widget `text_textarea` → `string_textarea`. Existing values migrated by copying `.value`. |
| `current_station` | Allowed-values key **`teaching` → `education`** (label "Education & Referrals"). Other stations unchanged. Existing rows with value `teaching` migrated to `education`. |

### Fields REMOVED

| Field | Notes |
|-------|-------|
| `pharmacist_name` | Removed from Visit (string base field) and from the Pharmacy form group. Storage uninstalled via update hook. Replaced by per-medication `fulfilled_by`. |

### Validation / behavior

- `allergies`: at least one value required; default "None" satisfies it (FR-017). "None" is excluded
  from the sticky display (FR-019) — exclusion is by term **label** `None`.
- `pos_details`: only meaningful when `pos = TRUE` (UI-enforced via `conditional_fields`; no hard
  server constraint required).
- Station order (after rename) — see StationWorkflow below.

---

## 2. PrescriptionItem entity (`prescription_item`) — `librechart_pharmacy`

Existing fields retained: `visit`, `drug`, `drug_category`, `dosage`, `quantity_dispensed`,
`prescription_filled`, `created`. (`dispensed_by` and `override_reason` remain in storage but are
dropped from the form/logic — deprecated.)

### Fields ADDED

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `fulfilled_by` | entity_reference → taxonomy `pharmacist` | No | Default = `pharmacist` term matching the logged-in pharmacist (editable). Edit access: Pharmacist role only. |

### Field semantics in this feature

| Field | Role in the medication line item |
|-------|----------------------------------|
| `drug` | The selected medication. Widget changes from `options_select` to the **stock-aware autocomplete** (D2), sourced from `DrugInventory`. |
| `quantity_dispensed` | "Quantity" to dispense. Positive integer ≥ 1. |
| `prescription_filled` | The "Fulfilled" checkbox. **Editable by Pharmacist role only** (any station). Drives inventory. |
| `fulfilled_by` | The "Fulfilled by" dropdown. Pharmacist role only; auto-filled, editable. |

### Validation rules

- **VR-1 (insufficient stock / FR-013)**: Setting `prescription_filled = TRUE` is **blocked** when
  `quantity_dispensed > DrugInventory.quantity_on_hand` for the selected drug. Violation message
  states the remaining stock quantity. Implemented as an entity validation constraint (surfaces on
  the Visit form via IEF). The checkbox stays unchecked; no decrement occurs.
- **VR-2 (quantity)**: `quantity_dispensed` must be a positive integer (≥ 1) for a fulfilled item.
- **VR-3 (authorization / FR-008/FR-009, Q1)**: `prescription_filled` and `fulfilled_by` are editable
  only by users with the Pharmacist role (`hook_entity_field_access`), regardless of station.

### State & inventory effect (fulfilled state machine)

Let `effective(item) = item.prescription_filled ? item.quantity_dispensed : 0`.

| Transition | Inventory effect on the drug's `quantity_on_hand` |
|------------|----------------------------------------------------|
| unfilled → filled (qty Q) | `−Q` (after VR-1 passes) |
| filled (qty Q) → unfilled | `+Q` (restore) |
| filled qty A → filled qty B | `−(B − A)` (apply the difference; VR-1 re-checked) |
| save with no change to effective(item) | `0` (idempotent — FR-012) |
| delete a filled item | `+effective(item)` (restore — edge case) |

Implemented as `delta = effective(new) − effective(original)`, then
`quantity_on_hand = quantity_on_hand − delta`, floored at 0 defensively (VR-1 prevents reaching the
floor through normal flow). `original` comes from `$entity->original` on update; for inserts the
prior effective value is 0; for deletes the new effective value is 0.

---

## 3. DrugInventory entity (`drug_inventory`) — unchanged schema

No field changes. Matched by **`drug` only** in this feature (single clinic-wide pool — spec
clarification). Existing fields: `drug`, `clinic_site`, `quantity_on_hand`, `low_stock_threshold`,
`unit`.

### Stock-status definition (FR-028) — applied in picker AND report

| Status | Condition | Picker color | Report row color |
|--------|-----------|--------------|------------------|
| Out of stock | `quantity_on_hand == 0` | red, **not selectable** | red (`inventory-row--out-of-stock`) |
| Low stock | `0 < quantity_on_hand <= low_stock_threshold` | yellow | yellow (`inventory-row--low-stock`) |
| In stock | `quantity_on_hand > low_stock_threshold` | default | default |

---

## 4. Paragraph type `education_referral` — `paragraphs` (NEW)

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `education` (e.g., `field_education`) | entity_reference → taxonomy `education` | Yes | The education class referred to. |
| `complete` (e.g., `field_complete`) | boolean | No | Default `FALSE`. The per-item "Complete" checkbox (FR-024). |

Referenced from `Visit.education_referrals` (unlimited). Mirrors the existing `lab_result` paragraph
pattern.

---

## 5. Taxonomy vocabularies (NEW, config)

| Vocabulary (vid) | Purpose | Terms (content, admin-managed) |
|------------------|---------|--------------------------------|
| `pharmacist` | "Fulfilled by" options (FR-014) | Pharmacist names |
| `education` | Education classes for referrals (FR-023) | Class names |

`allergies` vocabulary (existing): gains a content term **"None"** (seeded/looked-up by the
default-value callback / update hook) used as the allergy default.

---

## 6. StationWorkflow (`librechart_visit/src/Service/StationWorkflow.php`)

Rename `teaching` → `education` across:

- `WORKFLOW_STATIONS`: `[registration, triage, lab, clinical, pt, pharmacy, education]`
- `label('education')` → "Education & Referrals"
- `ownerRole('education')` → `teaching_coordinator` (role machine name unchanged — permissions carry
  over per clarification)
- `nextStation()`: `pharmacy → education`, `education → NULL`
- Station→field-group map (`librechart_visit.module`): `education → group_education`

---

## 7. Migrations / update hooks (delivery)

Delivered via `librechart_visit.install` / `librechart_pharmacy` update hooks
(`EntityDefinitionUpdateManager`), following the `current_station` precedent:

1. **Install new Visit base fields**: `medications`, `education_referrals`, `pos`, `pos_details`.
2. **Convert `notes_to_pharmacist`** from `text_long` to `string_long`, copying existing `.value`
   into the new column before swapping (avoid data loss).
3. **Remove `pharmacist_name`** field storage.
4. **`current_station` allowed values**: update the field's allowed values; **data-migrate** rows
   where `current_station = 'teaching'` → `'education'` (also revision table).
5. **Seed "None"** allergy term (create if absent) for the default-value callback.
6. **Install `fulfilled_by`** on `prescription_item`.
7. New config imported on deploy: `pharmacist` + `education` vocabularies, `education_referral`
   paragraph type + fields/displays, `autosave_form.settings` (`visit` added), updated Visit form
   display, `inline_entity_form` in `core.extension`.

> Config-affecting items also land in module `config/install` and are exported to `config/sync` via
> `ddev drush config:export -y`; schema items run through update hooks so existing databases migrate
> cleanly.
