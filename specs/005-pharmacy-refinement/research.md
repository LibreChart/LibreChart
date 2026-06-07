# Phase 0 Research: Pharmacy Section Refinement

All NEEDS CLARIFICATION items from the spec were resolved during `/speckit.clarify` (see the
spec's Clarifications section). This document records the **technical decisions** that ground the
plan, each driven by the existing codebase (`librechart_visit`, `librechart_pharmacy`) and the
project's offline / config-first / contrib-over-custom constraints.

---

## D1 — Embedding multiple medication line items in the Visit form

**Decision**: Reuse the existing `PrescriptionItem` content entity and embed multiple line items in
the Visit form's Pharmacy group with the **Inline Entity Form** (`inline_entity_form`) "complex"
widget, on a new `medications` entity-reference base field on `Visit`.

**Rationale**:
- `PrescriptionItem` already exists with the right shape (`drug`, `quantity_dispensed`,
  `prescription_filled`, `visit`) and already drives the inventory decrement hook and the inventory
  reports (`InventoryReportController` raw SQL on `prescription_item`; `views.view.visit_prescriptions`).
  Keeping it preserves reporting with near-zero change.
- IEF is the canonical Drupal way to create/edit child content entities inside a parent form, and it
  natively supports "add another" / remove — matching FR-005/FR-006 ("each medication in its own
  fieldset", add/remove multiple).
- Honors contrib-over-custom (one well-maintained module vs. hand-rolled subforms).

**Alternatives considered**:
- **Paragraphs** (already enabled, used for `lab_result`): rejected for medications because
  medications must remain queryable `prescription_item` rows for the existing inventory hook and the
  two reporting surfaces; switching to paragraphs would force a risky rewrite of both. (Paragraphs
  *is* used for the simpler Education referrals — see D8.)
- **Custom AJAX subform** in `hook_form_alter`: rejected as reimplementing IEF (violates
  contrib-over-custom; higher maintenance).

**Implications**: `composer require drupal/inline_entity_form` (D11-compatible release; pin in
`composer.json`, add to `core.extension.yml`). Add `medications` base field on Visit (update hook).
Set each child's `visit` back-reference on save (small submit/presave handler) so reports keep
working. Field-level access (D6) gates pharmacist-only fields within the IEF subform.

---

## D2 — Stock-aware medication picker (typeahead, color coding, non-selectable out-of-stock)

**Decision**: Replace the `drug` field's `options_select` widget (within the medication line item)
with a **custom autocomplete widget** backed by a **custom route/controller** that queries
`DrugInventory`, returns at most 10 matches, and tags each with a stock status; a small JS library
(extending Drupal core's autocomplete) colors rows (yellow=low, red=out) and prevents selecting
out-of-stock entries. A "View inventory" link (`target="_blank"` → `/reports/inventory`) is injected
next to the field.

**Rationale**:
- Core entity-reference autocomplete returns plain-text labels with no stock data and no per-row
  styling/selectability control — the spec needs all three (FR-001/002/003).
- Sourcing from `DrugInventory` (not the drug taxonomy) is correct because stock/threshold live
  there, and the picker should only offer medications that exist in inventory.
- Reuses Drupal core's bundled jQuery-UI autocomplete (already self-hosted) — no external assets,
  satisfying the offline constraint.

**Alternatives considered**:
- `select2`/`tagify` (both enabled): rejected — heavier, and per-option "disabled + colored by live
  stock" still needs custom data wiring; core autocomplete + a thin JS override is simpler and fully
  offline.
- Pre-rendered `<select>` with colored `<option>`s: rejected — no typeahead, and disabled options
  are inconsistent across browsers; doesn't meet the ≤10 typeahead requirement.

**Implications**: New route `librechart_pharmacy.medication_autocomplete`; controller returns JSON
`[{label, value, status, selectable}]`; widget + JS + CSS classes `.medication--low-stock` /
`.medication--out-of-stock`. Contract in `contracts/medication-autocomplete.md`.

---

## D3 — Reversible, idempotent inventory adjustment + insufficient-stock block

**Decision**: Rewrite the inventory logic in `librechart_pharmacy.module` to be **delta-based**:
compare the entity's original `(prescription_filled, quantity_dispensed)` to the new values and apply
the net change to `DrugInventory.quantity_on_hand`. Restore stock on un-fulfill and on delete. Add a
**validation constraint** that blocks setting `prescription_filled = TRUE` when
`quantity_dispensed > quantity_on_hand`, with a message stating the remaining quantity. Match the
`DrugInventory` record by **drug only** (single clinic-wide pool per the spec clarification).

**Rationale**:
- The current hook only decrements one-way when `prescription_filled` is TRUE and never restores on
  un-check — it fails FR-011 (restore on uncheck) and FR-012 (idempotency). A delta computed from
  `$entity->original` makes repeated saves a no-op and makes toggling exactly reversible (SC-003).
- The current "override_reason" path lets stock go to a floored value with a warning; the clarified
  requirement (FR-013) is to **block** instead and report remaining stock — a pre-save validation
  constraint surfaces the error on the Visit form (via IEF) and prevents the save.
- Single site → one `DrugInventory` row per drug, so matching by `drug` alone is unambiguous and
  removes the dependency on the child's `visit→clinic_site` chain (which IEF doesn't auto-populate).

**Alternatives considered**:
- Keep the override-reason flow: rejected — contradicts the FR-013 clarification (block, don't
  override).
- Recompute inventory from scratch by summing all filled prescriptions: rejected — O(n) per save,
  fragile against receipts, and unnecessary when a delta is exact.

**Implications**: Inventory adjust runs on `presave` (validate/block) + `postsave` (apply delta) +
`predelete` (restore). `override_reason` field is left in storage but removed from the form and
logic (deprecated). Contract in `contracts/inventory-adjustment.md`.

---

## D4 — "Fulfilled by" pharmacist field (auto-fill, editable)

**Decision**: Add a new `fulfilled_by` entity-reference field on `PrescriptionItem` targeting a new
`pharmacist` taxonomy vocabulary. Default it (via a default-value callback) to the `pharmacist` term
matching the logged-in user's name when the user has the Pharmacist role; keep it editable. The
legacy `dispensed_by` string is dropped from the form.

**Rationale**: FR-009 + Clarification Q3 (auto-fill to logged-in pharmacist, editable). A taxonomy
gives admins a managed list (FR-014) and a stable dropdown source.

**Alternatives considered**:
- Reference a user account instead of a taxonomy term: rejected — the spec explicitly says a
  "Pharmacist taxonomy" of names; not every pharmacist necessarily has a login.
- Hard-require manual selection: rejected by Q3 (auto-fill chosen).

**Implications**: New `pharmacist` vocabulary (config). User→term mapping by name; if no match, the
default is blank and the pharmacist picks manually (documented assumption). Field-access gated to the
Pharmacist role (D6).

---

## D5 — Fulfillment authorization (Pharmacist role only, any station)

**Decision**: Restrict editing of `prescription_filled` and `fulfilled_by` to users with the
`pharmacist` role via `hook_entity_field_access()` on `prescription_item`, independent of the visit's
current station.

**Rationale**: Clarification Q1 (only Pharmacist role may change fulfilled state; any station/time).
`hook_entity_field_access` is precise and composes with IEF (the fields render read-only/hidden for
non-pharmacists). The existing `librechart_visit` already uses `hook_entity_field_access` for
per-station gating, so the pattern is established.

**Alternatives considered**:
- `field_permissions` module (enabled): viable but coarser; a role check in code is clearer and keeps
  the rule beside the inventory logic.
- Station-gating (only at pharmacy station): rejected by Q1 (any station).

---

## D6 — Allergies: required, default "None", always-visible sticky pills

**Decision**: Make `allergies` required with a **default-value callback** that resolves (creating if
absent) a "None" term in the `allergies` vocabulary. Render a fixed-position sticky bar at the bottom
of the Visit form showing each allergy (except "None") as a red pill, via a small JS+CSS library that
reads the `entity_reference_autocomplete_tags` widget and refreshes on add/remove.

**Rationale**: FR-017–FR-020. The tags widget already holds the live values client-side; JS that
parses/refreshes pills satisfies "updates when allergies are added/deleted" without a server
round-trip. A default-value callback avoids hard-coding a term ID (term IDs are content, not stable
across environments).

**Alternatives considered**:
- A new theme region for the sticky bar: rejected — heavier; the bar only needs to exist on the Visit
  form, so attach it via `hook_form_alter` + a fixed-position element.
- Server-only rendering of pills: rejected — wouldn't update live as the user edits the field.

**Implications**: Seed/lookup of "None" term in an update hook + default-value callback. New
`allergy_sticky` library attached on `visit_add_form`/`visit_edit_form`. Contract in
`contracts/allergy-sticky-and-report-coloring.md`.

---

## D7 — `notes_to_pharmacist` as a plain text area (no WYSIWYG)

**Decision**: Convert `notes_to_pharmacist` from `text_long` to `string_long` with a
`string_textarea` widget (no text format → no CKEditor). Migrate existing values by copying the
stored `.value` in an update hook.

**Rationale**: FR-016. A `text_long` field exposes a format selector that can enable CKEditor;
`string_long` is inherently plain text, which is exactly "plain text area instead of a WYSIWYG."

**Alternatives considered**:
- Keep `text_long` but force the `plain_text` format with no editor: rejected — a format selector can
  still appear and the value is filtered HTML; `string_long` is unambiguous and simpler.

---

## D8 — Education station rename + education-class referrals

**Decision**: Rename the `teaching` station to `education` everywhere it is referenced
(`Visit::current_station` allowed values, `StationWorkflow`, the station→field-group map, the form
display group), keeping the existing Teaching & Referrals fields, and add a new **`education_referral`
Paragraph type** (`education` term ref + `complete` boolean) referenced by a new `education_referrals`
field on Visit inside the renamed group. Add a data-migration update hook to set existing visits with
`current_station = 'teaching'` to `'education'`. The owning role stays `teaching_coordinator`.

**Rationale**: Clarification (Education is the renamed Teaching & Referrals station; content remains;
role/permissions carry over). Paragraphs is already enabled and used for the analogous `lab_result`
repeating sub-content, so an `education_referral` paragraph (with a per-item Complete checkbox)
matches FR-023/FR-024 with config only and no new dependency.

**Alternatives considered**:
- A separate Education content entity: rejected — overkill for a term + a boolean; paragraphs fits.
- Reusing `teaching_topics` vocabulary: rejected — those are the existing teaching topics; the spec
  asks for a distinct Education taxonomy of *classes*.

**Implications**: New `education` vocabulary (config); `education_referral` paragraph type + fields +
displays (config); new `education_referrals` base field on Visit; `list_string` allowed-values change
on `current_station` (entity definition update) + data migration. Station **label** rendered as
"Education & Referrals" (literal word swap that preserves the referrals meaning) — easily adjustable.

---

## D9 — POS (local-provider referral) conditional field

**Decision**: Add a `pos` boolean and a `pos_details` plain text area (`string_long`) on Visit, placed
in the Education group, and wire `pos → pos_details` visibility with `conditional_fields`, mirroring
the existing `pt_referral → pt_notes` configuration.

**Rationale**: FR-025/FR-026. `conditional_fields` is already enabled and already drives identical
show/hide behavior; the boolean `#on_value` shim in `librechart_visit.module` already makes checkbox
dependees work. Reuse beats reinvention.

**Alternatives considered**: Custom `#states`: rejected — the project standard here is
`conditional_fields`, and `#states` wouldn't reuse the existing shim/pattern.

---

## D10 — Inventory report row coloring

**Decision**: Extend the existing `librechart_pharmacy_preprocess_views_view_table()` hook to add an
`inventory-row--out-of-stock` class when `quantity_on_hand == 0` and `inventory-row--low-stock` when
`0 < quantity_on_hand <= low_stock_threshold`; set the picker/report color scheme to **yellow=low,
red=out** in `pharmacy.css`, and reconcile the conflicting light-pink low-stock rule in the theme's
`style.css`.

**Rationale**: FR-021/FR-028 and SC-007. The hook already marks low-stock rows; this is an extension,
not new infrastructure. Centralizing the colors in `pharmacy.css` keeps the picker and report
consistent (FR-028).

**Alternatives considered**: A Views "global: custom text" row class via rewrite: rejected — can't
compute `qty<=threshold` cleanly in the Views UI; the preprocess hook already does the comparison.

---

## D11 — Autosave for the Visit form

**Decision**: Add `visit` to `allowed_content_entity_types` in `autosave_form.settings.yml` (the
module is already enabled; it currently lists only `node`).

**Rationale**: FR-027 + SC-010. `autosave_form` applies to content entity forms it is configured for;
adding the `visit` entity type is the supported, config-only way to cover the Visit form.

**Alternatives considered**: A custom autosave: rejected — the module exists, is enabled, and is the
contrib-over-custom choice. The spec's "install and enable" is already satisfied; only the per-entity
allowlist needs the `visit` entry.

**Risk noted**: `autosave_form` + IEF + paragraphs can interact awkwardly (restoring nested subforms).
Verify in quickstart; if nested restore misbehaves, scope autosave via `only_on_form_change` and test
the medication/education subforms explicitly.

---

## Cross-cutting: configuration workflow

Per `CLAUDE.md`, every config change is made in module `config/install` where applicable **and**
applied to active config, then exported with `ddev drush config:export -y`; schema-affecting changes
(new/removed base fields, `current_station` allowed values, `notes_to_pharmacist` type change) are
delivered via `librechart_visit.install` / `librechart_pharmacy` update hooks using the
`EntityDefinitionUpdateManager`, following the existing `current_station` precedent
(`librechart_visit_update_9001`). All assets (JS/CSS) are self-hosted; no external libraries.
