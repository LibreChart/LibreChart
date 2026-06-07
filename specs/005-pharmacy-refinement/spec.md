# Feature Specification: Pharmacy Section Refinement

**Feature Branch**: `005-pharmacy-refinement`  
**Created**: 2026-05-31  
**Status**: Draft  
**Input**: User description: "Pharmacy section refinement, allergies sticky, education station, POS field, autosave"

## Clarifications

### Session 2026-05-31

- Q: Who may check a medication's "Fulfilled" checkbox (the action that decrements inventory)? → A: Only the Pharmacist role, at any station/time.
- Q: What happens when a pharmacist marks "Fulfilled" but the prescribed quantity exceeds available stock? → A: Block the fulfillment (stays unchecked, no decrement) and show an error that states the remaining stock quantity.
- Q: Is selecting a "Fulfilled by" pharmacist required when marking a medication fulfilled? → A: Auto-fill "Fulfilled by" to the logged-in pharmacist, editable (not a hard requirement to manually pick).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Clinician prescribes medications from live inventory (Priority: P1)

A clinician opens the Pharmacy fieldset on a patient's visit to prescribe medications. For each
medication, the clinician searches an autocomplete field that suggests up to the 10 closest matches
from the clinic's medication inventory as they type. Stock status is visible in the suggestions:
low-stock medications appear with a yellow background, out-of-stock medications appear with a red
background and cannot be selected. A "View inventory" link beside the field opens the full inventory
report in a new browser tab. The clinician sets a quantity to dispense for each chosen medication and
can add additional medications, each in its own fieldset.

**Why this priority**: This is the core prescribing workflow and the primary reason the pharmacy
section exists. Without an accurate, stock-aware medication picker, clinicians cannot prescribe
reliably, and every downstream step (pharmacist fulfillment, inventory accounting) depends on it.

**Independent Test**: Open a visit, expand the Pharmacy fieldset, type into the medication field, and
confirm typeahead returns ≤10 matches with correct color coding, that out-of-stock items are not
selectable, that a quantity can be entered, that additional medication fieldsets can be added, and
that the "View inventory" link opens the inventory report in a new tab.

**Acceptance Scenarios**:

1. **Given** a clinician has the Pharmacy fieldset open, **When** they type characters into the
   medication field, **Then** the field shows at most the 10 closest matching medications from
   inventory.
2. **Given** the typeahead results include a low-stock medication, **When** the results display,
   **Then** that medication's entry has a yellow background.
3. **Given** the typeahead results include an out-of-stock medication, **When** the results display,
   **Then** that medication's entry has a red background and cannot be selected.
4. **Given** a medication is selected, **When** the clinician views the medication fieldset, **Then**
   a quantity field is available in the same fieldset to set the amount to dispense.
5. **Given** the clinician needs to prescribe more than one medication, **When** they choose to add
   another, **Then** a new, independent medication fieldset is added.
6. **Given** the clinician wants to check availability, **When** they click "View inventory", **Then**
   the inventory report opens in a new browser tab.

---

### User Story 2 - Pharmacist fulfills medications and inventory adjusts automatically (Priority: P1)

When a patient arrives at the Pharmacy station, a pharmacist reviews the prescribed medications. For
each medication the pharmacist dispenses, they check the "Fulfilled" checkbox and select their name
from a "Fulfilled by" dropdown populated from a Pharmacist taxonomy. Checking "Fulfilled" decrements
that medication's quantity from inventory by the prescribed amount. If the pharmacist later unchecks
"Fulfilled", the same quantity is returned to inventory.

**Why this priority**: Accurate inventory is mission-critical for a clinic operating without internet
connectivity and with limited resupply. Automatic, reversible inventory adjustment tied to
fulfillment prevents stock drift and double-counting, and ties the clinical and pharmacy roles
together in one auditable workflow.

**Independent Test**: With a prescribed medication on a visit, check "Fulfilled" as a pharmacist,
confirm the inventory quantity for that medication decreases by the prescribed amount and a
"Fulfilled by" pharmacist can be recorded; then uncheck "Fulfilled" and confirm the quantity is
restored.

**Acceptance Scenarios**:

1. **Given** a medication with quantity 5 prescribed on a visit, **When** the pharmacist checks
   "Fulfilled", **Then** that medication's inventory quantity decreases by 5.
2. **Given** a medication that was marked fulfilled, **When** the pharmacist unchecks "Fulfilled",
   **Then** that medication's inventory quantity increases by the prescribed amount (returns to its
   prior level).
3. **Given** the pharmacist is recording who dispensed a medication, **When** they open the "Fulfilled
   by" dropdown, **Then** the options are the terms of the Pharmacist taxonomy (pharmacist names).
4. **Given** a medication fieldset, **When** the pharmacist views it, **Then** each medication has its
   own independent "Fulfilled" checkbox and "Fulfilled by" selection.

---

### User Story 3 - Allergies are always visible and required at triage (Priority: P1)

During triage, the allergy field is required and defaults to "None". Any allergies recorded for the
patient are pinned ("stickied") to the bottom of the page as red pill-shaped badges so that
clinicians and pharmacists are aware of them at all times while scrolling through the visit. The value
"None" is never shown as a badge. Removing an allergy removes its badge from the sticky area.

**Why this priority**: Allergy awareness is a patient-safety requirement that directly affects safe
prescribing. Making the field required prevents missing data, and the persistent sticky display
ensures the warning cannot be scrolled out of view at the moment medications are chosen.

**Independent Test**: Add one or more allergies to a visit and confirm red pill badges appear stickied
at the bottom of the page; remove an allergy and confirm its badge disappears; confirm the field
cannot be left empty (defaults to "None") and that "None" produces no badge.

**Acceptance Scenarios**:

1. **Given** a new visit, **When** the triage form loads, **Then** the allergy field is required and
   defaults to "None".
2. **Given** a patient with one or more recorded allergies, **When** any visit page is displayed,
   **Then** each allergy appears as a red pill badge stickied to the bottom of the page.
3. **Given** the allergy value is "None", **When** the page is displayed, **Then** no allergy badge is
   shown in the sticky area.
4. **Given** an allergy badge is displayed, **When** that allergy is deleted from the visit, **Then**
   its badge is removed from the sticky area.

---

### User Story 4 - Education referrals at the renamed Education station (Priority: P2)

The existing "Teaching & Referrals" station is renamed from "teaching" to "education" (the station
identifier and its references change to "education"), and its existing Teaching & Referrals content is
retained. Within this section, staff can additionally refer a patient to one or more education classes
drawn from an Education taxonomy. Each referred education item has a "Complete" checkbox indicating the
class was completed.

**Why this priority**: Education is a defined part of the clinic's care workflow but is downstream of
prescribing and dispensing. It adds value but is not required for the core medication-safety
workflow to function.

**Independent Test**: At the Education station (formerly Teaching & Referrals), confirm the existing
referral content is still present, refer a patient to one or more education classes from the Education
taxonomy, and mark one as complete; confirm both the referral and completion state are recorded per
item.

**Acceptance Scenarios**:

1. **Given** the station previously identified as "teaching", **When** the workflow is displayed,
   **Then** it is identified as "education" and its existing Teaching & Referrals content remains
   present.
2. **Given** the Education station, **When** staff add an education referral, **Then** they can choose
   a class from the Education taxonomy.
3. **Given** an education referral on a visit, **When** staff view it, **Then** each referred item has
   its own "Complete" checkbox.
4. **Given** an education item, **When** its "Complete" checkbox is checked, **Then** that item is
   recorded as completed.

---

### User Story 5 - Pharmacy section cleanup and notes simplification (Priority: P2)

The legacy free-text "Pharmacist name" field is removed from the pharmacy section (replaced by the
per-medication "Fulfilled by" taxonomy dropdown). The "Notes to pharmacist" field remains but becomes
a plain text area rather than a rich-text (WYSIWYG) editor.

**Why this priority**: This is cleanup that aligns the form with the new per-medication fulfillment
model and simplifies note entry, but the new workflow can function before this cleanup is applied.

**Independent Test**: Open the pharmacy section and confirm there is no standalone "Pharmacist name"
field, and that "Notes to pharmacist" renders as a plain text area with no rich-text toolbar.

**Acceptance Scenarios**:

1. **Given** the pharmacy section, **When** it is displayed, **Then** the standalone "Pharmacist name"
   field is no longer present.
2. **Given** the pharmacy section, **When** the "Notes to pharmacist" field is displayed, **Then** it
   is a plain text area with no rich-text formatting toolbar.

---

### User Story 6 - Inventory report visually flags stock levels (Priority: P2)

In the inventory report, low-stock items have a yellow background and out-of-stock items have a red
background, so anyone reviewing inventory can spot shortages at a glance.

**Why this priority**: Consistent visual stock cues across both the medication picker and the
inventory report improve situational awareness, but the report is already usable without the color
coding.

**Independent Test**: Open the inventory report with at least one low-stock and one out-of-stock item
and confirm the rows are highlighted yellow and red respectively.

**Acceptance Scenarios**:

1. **Given** the inventory report, **When** a row represents a low-stock item, **Then** that row has a
   yellow background.
2. **Given** the inventory report, **When** a row represents an out-of-stock item, **Then** that row
   has a red background.

---

### User Story 7 - POS referral to a local provider in the Education section (Priority: P3)

Within the Education (formerly Teaching & Referrals) section there is a "POS" checkbox in its own
fieldset. POS indicates a referral to a local provider. When checked, a conditional text area appears
to capture the referral detail.

**Why this priority**: This is a small, self-contained addition that does not affect the medication or
safety workflows.

**Independent Test**: In the Education section, confirm an unchecked "POS" checkbox shows no text area,
and that checking it reveals a text area for the local-provider referral detail.

**Acceptance Scenarios**:

1. **Given** the Education section, **When** the "POS" checkbox is unchecked, **Then** the conditional
   text area is hidden.
2. **Given** the Education section, **When** the "POS" checkbox is checked, **Then** a text area
   appears to record the referral to a local provider.

---

### User Story 8 - Drafts are preserved automatically while editing a visit (Priority: P3)

The visit form autosaves in-progress work so that clinicians and pharmacists do not lose entries if
they navigate away or the session is interrupted.

**Why this priority**: A safety net against data loss that improves the editing experience, but the
core prescribing and dispensing logic works without it.

**Independent Test**: Begin editing a visit, leave the form without manually saving, return to it, and
confirm the in-progress entries are restored.

**Acceptance Scenarios**:

1. **Given** an in-progress visit edit, **When** the user navigates away without saving, **Then** the
   unsaved entries are retained and restored on return.

---

### Edge Cases

- **Insufficient stock at fulfillment**: When a pharmacist marks a medication fulfilled but the
  prescribed quantity exceeds available inventory, the fulfillment is blocked — the checkbox stays
  unchecked, no decrement occurs, and an error states the remaining stock quantity. The pharmacist
  must reduce the quantity or restock before fulfilling.
- **Out-of-stock between prescribing and fulfillment**: A medication that was in stock when prescribed
  may become out of stock before the pharmacist fulfills it. The fulfillment step must reflect the
  current inventory at the time of fulfillment.
- **Re-fulfillment toggling**: Rapidly checking/unchecking "Fulfilled" must not double-decrement or
  double-restore inventory; each medication's net effect on inventory must match its current
  fulfilled state and prescribed quantity.
- **Changing quantity after fulfillment**: If a medication's quantity is edited while already marked
  fulfilled, the inventory adjustment must remain consistent with the final fulfilled quantity.
- **Removing a fulfilled medication**: If a fulfilled medication fieldset is removed, the previously
  decremented quantity must be returned to inventory.
- **Empty/`None` allergies**: A required allergy field defaulting to "None" must satisfy the required
  validation while producing no sticky badge.
- **Duplicate medications**: A clinician may add the same medication in two fieldsets; the inventory
  effect must account for the combined fulfilled quantity.

## Requirements *(mandatory)*

### Functional Requirements

**Medication picker (clinician)**

- **FR-001**: The pharmacy section MUST provide a medication picker with typeahead autocomplete that
  returns at most the 10 closest matches from the medication inventory for the characters typed.
- **FR-002**: The medication picker MUST visually indicate low-stock medications with a yellow
  background and out-of-stock medications with a red background.
- **FR-003**: The medication picker MUST prevent selection of out-of-stock medications.
- **FR-004**: The pharmacy section MUST provide a "View inventory" link beside the medication field
  that opens the inventory report in a new browser tab.
- **FR-005**: Each medication MUST be entered in its own fieldset containing, at minimum, the
  medication, a quantity to dispense, a "Fulfilled" checkbox, and a "Fulfilled by" selection.
- **FR-006**: A clinician MUST be able to add multiple medications, each in its own independent
  fieldset, and remove medication fieldsets.
- **FR-007**: Each medication fieldset MUST allow entry of a quantity to dispense.

**Fulfillment and inventory (pharmacist)**

- **FR-008**: Each medication fieldset MUST provide a "Fulfilled" checkbox. Only users with the
  Pharmacist role MUST be able to set/change "Fulfilled" (and thereby trigger the inventory
  adjustment); this is permitted regardless of the visit's current station. Non-pharmacist users MUST
  NOT be able to change the fulfilled state.
- **FR-009**: Each medication fieldset MUST provide a "Fulfilled by" dropdown whose options are the
  terms of the Pharmacist taxonomy. When a pharmacist marks a medication fulfilled, the "Fulfilled by"
  value MUST default to the Pharmacist term corresponding to the logged-in pharmacist, and MUST remain
  editable so another pharmacist can be selected.
- **FR-010**: When a medication's "Fulfilled" checkbox is checked, the system MUST decrement that
  medication's inventory quantity by the medication's prescribed quantity.
- **FR-011**: When a medication's "Fulfilled" checkbox is unchecked, the system MUST increment that
  medication's inventory quantity by the medication's prescribed quantity (restoring the prior
  level).
- **FR-012**: Inventory adjustments MUST be idempotent with respect to fulfilled state — repeated
  saves without a change in fulfilled state MUST NOT change inventory, and the net inventory effect of
  a medication MUST always match its current fulfilled state and quantity.
- **FR-013**: When the prescribed quantity exceeds the medication's available stock, the system MUST
  block the fulfillment — the "Fulfilled" checkbox MUST remain unchecked, no inventory decrement MUST
  occur, and the system MUST show an error that states the quantity of stock remaining for that
  medication. Inventory MUST never be reduced below zero as a result of fulfillment.

**Pharmacist taxonomy**

- **FR-014**: The system MUST provide a "Pharmacist" taxonomy whose terms are pharmacist names, used to
  populate the "Fulfilled by" dropdown.

**Pharmacy section cleanup**

- **FR-015**: The system MUST remove the standalone free-text "Pharmacist name" field from the pharmacy
  section.
- **FR-016**: The "Notes to pharmacist" field MUST remain in the pharmacy section and MUST be a plain
  text area (no rich-text/WYSIWYG editor).

**Allergies**

- **FR-017**: The allergy field captured during triage MUST be required and MUST default to "None".
- **FR-018**: All recorded allergies (other than "None") MUST be displayed as red pill-shaped badges
  stickied to the bottom of the page, remaining visible while the user scrolls the visit.
- **FR-019**: The value "None" MUST NOT appear as a sticky allergy badge.
- **FR-020**: Removing an allergy from the visit MUST remove its corresponding sticky badge.

**Inventory report**

- **FR-021**: The inventory report MUST display low-stock items with a yellow background and
  out-of-stock items with a red background.

**Education station**

- **FR-022**: The system MUST rename the existing "Teaching & Referrals" station identifier from
  "teaching" to "education" and update its references accordingly, while retaining the station's
  existing Teaching & Referrals content.
- **FR-023**: The system MUST provide an "Education" taxonomy whose terms represent education classes,
  and MUST allow a patient to be referred to one or more of them within the Education section.
- **FR-024**: Each referred education item MUST have its own "Complete" checkbox indicating whether the
  class was completed.

**POS**

- **FR-025**: The Education (formerly Teaching & Referrals) section MUST include a "POS" checkbox in
  its own fieldset, where POS denotes a referral to a local provider.
- **FR-026**: When the "POS" checkbox is checked, a conditional text area MUST appear to capture the
  local-provider referral detail; when unchecked, the text area MUST be hidden.

**Autosave**

- **FR-027**: The visit form MUST autosave in-progress entries so unsaved work is retained and
  restored if the user leaves and returns without manually saving.

**Stock-status definitions**

- **FR-028**: The system MUST classify a medication as "out of stock" when its available quantity is
  zero, and as "low stock" when its available quantity is at or below its configured low-stock
  threshold (and above zero). These definitions MUST be applied consistently in the medication picker
  and the inventory report.

### Key Entities *(include if feature involves data)*

- **Visit**: The patient encounter being documented. Carries the pharmacy section (prescribed
  medications), the required allergies entered at triage, education referrals, the POS checkbox and
  conditional note, and the current station.
- **Prescribed medication (per-visit line item)**: One per medication on a visit. Attributes:
  selected medication, quantity to dispense, fulfilled state, and the fulfilling pharmacist
  ("Fulfilled by").
- **Medication inventory record**: Tracks available quantity and low-stock threshold for a medication
  in the single, clinic-wide stock pool. Source of typeahead suggestions, stock-status color coding,
  and the target of fulfillment decrements/restores.
- **Pharmacist taxonomy term**: A pharmacist's name, selectable as "Fulfilled by".
- **Allergy term**: An allergy recorded on the visit; rendered as a red sticky badge (except "None").
- **Education term**: An education class a patient can be referred to, each referral carrying a
  "Complete" flag.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A clinician can find and select an in-stock medication via typeahead in under 5 seconds,
  with suggestions limited to the 10 closest matches.
- **SC-002**: 100% of out-of-stock medications are non-selectable and visibly flagged red in the
  picker; 100% of low-stock medications are visibly flagged yellow.
- **SC-003**: After a pharmacist toggles "Fulfilled" on and off for a medication, the medication's
  inventory quantity returns to exactly its starting value (0% drift across toggles).
- **SC-004**: Inventory never records a negative quantity as a result of fulfillment (0 occurrences).
- **SC-005**: 100% of recorded allergies (excluding "None") are visible as red badges at the bottom of
  the page at all scroll positions, and removing an allergy removes its badge within the same edit.
- **SC-006**: The required allergy field cannot be submitted empty; new visits default to "None" with
  no badge shown.
- **SC-007**: In the inventory report, 100% of low-stock rows are yellow and 100% of out-of-stock rows
  are red.
- **SC-008**: A patient can be referred to one or more education classes at the Education station, each
  with an independently settable "Complete" state.
- **SC-009**: Checking the "POS" checkbox reveals the text area in 100% of cases; unchecking hides it.
- **SC-010**: In-progress visit edits are retained and restored after navigating away without manual
  save (no loss of entered data).

## Assumptions

- **Stock thresholds**: "Out of stock" means available quantity is zero; "low stock" means available
  quantity is at or below the medication's configured low-stock threshold and greater than zero.
  Existing inventory records already carry a per-medication low-stock threshold.
- **Roles**: Clinicians prescribe medications (select medication, set quantity, add/remove fieldsets);
  pharmacists fulfill them (set "Fulfilled" and "Fulfilled by"). Only the Pharmacist role may change a
  medication's fulfilled state, and may do so regardless of the visit's current station (not limited
  to the Pharmacy station). Existing station role permissions otherwise govern who can edit which
  section.
- **"Fulfilled by" population**: The Pharmacist taxonomy is maintained by staff/administrators; the
  dropdown reflects current terms. The auto-fill default assumes each pharmacist user maps to a single
  corresponding Pharmacist taxonomy term (e.g., by matching name); if no match exists the default is
  left blank and the pharmacist selects manually.
- **Education station is the renamed Teaching station**: There is no brand-new station. The existing
  "Teaching & Referrals" station is renamed from "teaching" to "education", keeps its existing content
  and position in the workflow, and gains the education-class referrals and the POS fieldset. Its
  existing role/permissions carry over.
- **Notes to pharmacist**: Converting to a plain text area applies to new and existing visits; any
  previously stored rich-text markup is rendered/edited as plain text going forward.
- **Sticky allergies scope**: The sticky allergy display applies to the visit edit/view page(s) where
  clinicians and pharmacists work, anchored to the bottom of the viewport.
- **Single clinic site**: The clinic operates at a single site, so inventory is one clinic-wide stock
  pool. Stock is not separated by clinic site, and fulfillment adjusts that single pool.
- **Autosave**: A form-autosave capability is enabled for the visit form to satisfy FR-027.

## Dependencies

- Existing visit workflow and station model (registration → triage → … → pharmacy → education
  [renamed from teaching] → complete), including station-based role permissions.
- Existing medication inventory tracking (available quantity and low-stock threshold per medication in
  a single clinic-wide stock pool) and the inventory report.
- Existing allergy capture at triage and the allergies taxonomy.
- A conditional-field capability for the POS show/hide behavior.
- A form-autosave capability for the visit form.
