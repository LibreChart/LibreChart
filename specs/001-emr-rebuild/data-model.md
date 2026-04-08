# Data Model: Librechart EMR

**Branch**: `001-emr-rebuild` | **Date**: 2026-03-15

---

## Entity Overview

```
Patient (custom entity)
└── has many → Visit (custom entity)
                ├── has many → LabResult (paragraph)
                ├── has many → PrescriptionItem (custom entity)
                └── fields grouped by station (Field Group)

Drug (taxonomy term, per category vocab)
├── has one → DrugInventory per ClinicSite (custom entity)
└── referenced by → PrescriptionItem, InventoryReceipt

InventoryReceipt (custom entity)
└── references → Drug, ClinicSite
```

---

## Entity: Patient

**Module**: `librechart_patient`
**Type**: Custom content entity — **revisions enabled** (FR-001a). Every save creates a new revision storing the previous values, timestamp, and author.

| Field | Machine name | Type | Required | Notes |
|---|---|---|---|---|
| First name | `first_name` | string (128) | Yes | |
| Last name | `last_name` | string (128) | Yes | |
| Date of birth | `date_of_birth` | datetime (date only) | Yes | Used for age calculation and duplicate detection |
| Sex | `sex` | list_string | Yes | Values: male, female, other |
| Cedula (ID) | `cedula` | string (64) | No | Unique index; patients without cedula registered by name + DOB |
| Municipality | `municipality` | entity_reference | No | → taxonomy: `municipalities` |
| Village / Town | `village_town` | entity_reference | No | → taxonomy: `village_town` |
| Created | `created` | timestamp | Auto | |
| Changed | `changed` | timestamp | Auto | |

**Indexes**: Unique on `cedula` (when not null). Composite index on `last_name` + `first_name` + `date_of_birth` for duplicate detection.

**Access**: All authenticated roles can view. Only Registration Staff and Administrator can create/edit.

---

## Entity: Visit

**Module**: `librechart_visit`
**Type**: Custom content entity with revisions enabled and **optimistic locking** (FR-015a). The `changed` timestamp is checked on save; if the record was modified since the user loaded it, the save is rejected with a reload prompt. Visit `status = complete` is **informational only** — any user with appropriate station role may still edit their fields after completion (FR-015b).

### Core Visit Fields

| Field | Machine name | Type | Required | Notes |
|---|---|---|---|---|
| Patient | `patient` | entity_reference | Yes | → Patient entity |
| Visit date | `visit_date` | datetime | Yes | Default: now |
| Patient type | `patient_type` | list_string | Yes | Values: adult, pediatric. Controls conditional field visibility. |
| Clinic site | `clinic_site` | entity_reference | Yes | → taxonomy: `clinic_sites` |
| Status | `status` | list_string | Yes | Values: in_progress, complete |
| Created | `created` | timestamp | Auto | |
| Changed | `changed` | timestamp | Auto | |

### Station: Triage

*Editable by: Triage Nurse. Readable by all clinical roles.*

| Field | Machine name | Type | Notes |
|---|---|---|---|
| Chief complaint | `complaint` | text_long | |
| Past medical history | `past_medical_history` | text_long | Dictation-enabled |
| Allergies | `allergies` | entity_reference (multi) | → taxonomy: `allergies`; auto-create enabled |
| Current medications | `current_medications` | string_long | |
| Temperature (°C) | `vital_temperature` | decimal | |
| Pulse (bpm) | `vital_pulse` | integer | Range: 0–300 |
| Respiration (breaths/min) | `vital_respiration` | integer | |
| Systolic BP (mmHg) | `vital_systolic` | integer | Range: 0–300 |
| Diastolic BP (mmHg) | `vital_diastolic` | integer | Range: 0–300 |
| Height (cm) | `vital_height` | decimal | |
| Weight (kg) | `vital_weight` | decimal | |
| BMI | `vital_bmi` | decimal | Calculated on save from height/weight |
| Pregnancy history | `pregnancy_history` | text_long | **Hidden when patient_type = pediatric** |
| Last menstrual period | `lmp` | string (32) | **Hidden when patient_type = pediatric** |
| Breastfeeding | `breastfeeding` | list_string | Values: yes, no, n_a. **Hidden when patient_type = pediatric** |

### Station: Lab Orders & Results

*Lab ordering: Triage Nurse or Clinician. Results entry: Lab Technician.*

Implemented as `LabResult` Paragraph entities (see below). The Visit has an entity_reference_revisions field: `lab_results` → LabResult paragraph type.

### Station: Clinical Evaluation

*Editable by: Clinician. Readable by all clinical roles.*

| Field | Machine name | Type | Notes |
|---|---|---|---|
| Clinical notes | `clinical_notes` | text_long | Dictation-enabled (primary dictation field) |
| Clinician name | `clinician_name` | string (256) | |
| Diagnosis write-in | `dx_write_in` | text_long | Free-text diagnosis notes |
| Additional orders | `orders` | entity_reference (multi) | → taxonomy: `orders` |
| Referrals | `referrals` | entity_reference (multi) | → taxonomy: `referrals` |
| PT referral | `pt_referral` | boolean | Triggers PT section visibility |

**Body system assessment fields** (boolean, one per system):

| Field label | Machine name | Hidden for pediatric? |
|---|---|---|
| Cardiac / Thoracic | `sys_cardiac` | No |
| Dermatologic | `sys_derm` | No |
| Endocrine | `sys_endo` | No |
| ENT | `sys_ent` | No |
| Eye | `sys_eye` | No |
| Gastrointestinal | `sys_gi` | No |
| GYN / OB | `sys_gyn_ob` | **Yes** |
| Mental Health | `sys_mental_health` | No |
| Musculoskeletal | `sys_musculoskeletal` | No |
| Neurological | `sys_neuro` | No |
| Respiratory | `sys_respiratory` | No |
| Uro-genital | `sys_uro_genital` | No |
| Vascular | `sys_vascular` | No |
| Wound / Ostomy | `sys_wound_ostomy` | No |

**Diagnosis entity reference fields** (one per body system vocabulary, multiple values):

Each maps to its corresponding taxonomy vocabulary from the legacy system (chronic_diseases, allergy_treatment_agents, cardiac_treatment_agents, dermatologic_agents, endo, ent, eye, gi, gyn_ob, mental_health, muscular_skeletal, neuro, opthalmic_otic, resp, respiratory_treatments, uro_genital, vascular, wound_ostomy, pain_management, physical_therapy_treatment, vitamins_nutrients_lv, anti_infective_agents, miscellaneous).

### Station: Physical Therapy

*Editable by: Physical Therapist. Readable by all clinical roles. Visible only when `pt_referral = true`.*

| Field | Machine name | Type | Notes |
|---|---|---|---|
| PT treatment notes | `pt_notes` | text_long | Dictation-enabled |
| PT interventions | `pt_interventions` | entity_reference (multi) | → taxonomy: `physical_therapy_treatment` |
| PT/OT name | `pt_name` | string (256) | |

### Station: Pharmacy Dispensing

*Editable by: Pharmacist. Readable by all clinical roles.*

| Field | Machine name | Type | Notes |
|---|---|---|---|
| Pharmacist name | `pharmacist_name` | string (256) | |
| Notes to pharmacist | `notes_to_pharmacist` | text_long | |

Dispensed drugs are stored as `PrescriptionItem` entities referencing this Visit (see below), not as fields directly on Visit. This allows independent inventory queries.

### Station: Teaching & Referrals

*Editable by: Teaching Coordinator. Readable by all clinical roles.*

| Field | Machine name | Type | Notes |
|---|---|---|---|
| Clinical teaching topics | `teaching_topics` | entity_reference (multi) | → taxonomy: `teaching_topics` (new vocabulary) |
| External referral destination | `external_referral` | string_long | Free text or entity_reference to referrals taxonomy |
| Diagnostic provider referral | `diagnostic_referral` | string (256) | |

---

## Paragraph Type: LabResult

**Module**: `librechart_lab`

One paragraph per lab test ordered per visit. The Visit holds a `lab_results` paragraph reference field.

| Field | Machine name | Type | Notes |
|---|---|---|---|
| Test | `lab_test` | list_string | Values: glucose, hgba1c, hgb, leukocytes, ketone, blood, protein, urinalysis, pregnancy_test |
| Ordered | `lab_ordered` | boolean | Set by Triage Nurse / Clinician |
| Result | `lab_result_value` | string (256) | Set by Lab Technician; only visible when `lab_ordered = true` |
| Result notes | `lab_result_notes` | text_long | Optional; Lab Technician |

---

## Entity: PrescriptionItem

**Module**: `librechart_pharmacy`
**Type**: Custom content entity

One record per drug prescribed and dispensed per visit. Multiple PrescriptionItems reference a single Visit.

| Field | Machine name | Type | Required | Notes |
|---|---|---|---|---|
| Visit | `visit` | entity_reference | Yes | → Visit entity |
| Drug | `drug` | entity_reference | Yes | → taxonomy term (drug name within a drug category vocab) |
| Drug category | `drug_category` | string (64) | Yes | Denormalised from vocabulary machine name for fast filtering |
| Dosage | `dosage` | string (256) | No | Individual dosage per drug (replaces legacy shared dosage field) |
| Quantity dispensed | `quantity_dispensed` | integer | No | Triggers inventory deduction on save |
| Filled | `prescription_filled` | boolean | No | Pharmacist marks when dispensed |
| Dispensed by | `dispensed_by` | string (256) | No | Pharmacist name |
| Override reason | `override_reason` | text_long | No | Required when dispensing with quantity_dispensed > DrugInventory.quantity_on_hand; stores the pharmacist's documented reason for the override (FR-028) |
| Created | `created` | timestamp | Auto | |

**Business logic on save**: When `prescription_filled` is set to true and `quantity_dispensed > 0`, decrement the corresponding `DrugInventory.quantity_on_hand` by `quantity_dispensed`. If `quantity_dispensed > DrugInventory.quantity_on_hand`, `override_reason` must be non-empty or the save is rejected with a validation error.

---

## Entity: DrugInventory

**Module**: `librechart_pharmacy`
**Type**: Custom content entity

One record per drug per clinic site. Represents current stock level.

| Field | Machine name | Type | Required | Notes |
|---|---|---|---|---|
| Drug | `drug` | entity_reference | Yes | → taxonomy term (drug name) |
| Clinic site | `clinic_site` | entity_reference | Yes | → taxonomy: `clinic_sites` |
| Quantity on hand | `quantity_on_hand` | integer | Yes | Updated by save hooks on PrescriptionItem and InventoryReceipt |
| Low stock threshold | `low_stock_threshold` | integer | No | Default: 10. Alert shown in report when on_hand ≤ threshold |
| Unit | `unit` | string (64) | No | e.g., "tablets", "vials", "mL" |

**Unique constraint**: One DrugInventory per (`drug`, `clinic_site`) pair.

---

## Entity: InventoryReceipt

**Module**: `librechart_pharmacy`
**Type**: Custom content entity

Records each stock addition event.

| Field | Machine name | Type | Required | Notes |
|---|---|---|---|---|
| Drug | `drug` | entity_reference | Yes | → taxonomy term |
| Clinic site | `clinic_site` | entity_reference | Yes | → taxonomy: `clinic_sites` |
| Quantity received | `quantity_received` | integer | Yes | Increments DrugInventory.quantity_on_hand on save |
| Receipt date | `receipt_date` | datetime | Yes | Default: now |
| Received by | `received_by` | string (256) | Yes | Pharmacist name |
| Notes | `notes` | text_long | No | Batch number, supplier, etc. |
| Created | `created` | timestamp | Auto | |

---

## Taxonomy Vocabularies

### Administrative
- `clinic_sites` — Clinic locations (migrated from legacy)
- `municipalities` — Geographic municipalities (migrated)
- `village_town` — Villages/towns within municipalities (migrated)
- `referrals` — External referral destinations (migrated)
- `allergies` — Patient allergies; auto-create enabled (migrated)
- `orders` — Medical order types (migrated)
- `teaching_topics` — Health education session topics (new)

### Diagnosis Vocabularies (migrated from legacy)
chronic_diseases, allergy_treatment_agents, cardiac_thoracic, cardiac_treatment_agents, derm, dermatologic_agents, endo, ent, eye, gi, gastrointestinal_treatment_agent, gyn_ob, id, muscular_skeletal, neuro, opthalmic_otic, resp, respiratory_treatments, uro_genital, vascular, wound_ostomy, mental_health, pain_management, physical_therapy_treatment, vitamins_nutrients_lv, anti_infective_agents, miscellaneous

### Drug Category Vocabularies (migrated from legacy)
Each vocabulary's terms are individual drug names. Used by PrescriptionItem.drug reference and DrugInventory.drug reference.

anti_infective_agents, gi_agents, cardiac_treatment_agents, dermatologic_agents, respiratory_treatments, pain_management, vitamins_nutrients_lv, opthalmic_otic, miscellaneous, chronic_diseases

---

## User Roles & Station Ownership

| Role | Machine name | Owns (edit) | Can view |
|---|---|---|---|
| Administrator | `administrator` | All | All |
| Registration Staff | `registration_staff` | Patient entity, Visit core fields | All |
| Triage Nurse | `triage_nurse` | Triage station fields, Lab ordering | All |
| Lab Technician | `lab_technician` | Lab result entry | All |
| Clinician | `clinician` | Clinical evaluation fields | All |
| Physical Therapist | `physical_therapist` | PT station fields | All |
| Pharmacist | `pharmacist` | Pharmacy station, PrescriptionItem, DrugInventory, InventoryReceipt | All |
| Teaching Coordinator | `teaching_coordinator` | Teaching station fields | All |

---

## State Transitions: Visit Status

```
in_progress → complete   (any user with station access)
complete → in_progress   (any user with station access)
```

**Note**: Status is informational. Both states allow full edit access to users with the appropriate station role. The transition exists to signal workflow completion to other staff but does not restrict data entry.

---

## Conditional Field Visibility Rules (patient_type)

When `patient_type = pediatric`, the following fields are hidden on the Visit edit form:

- `pregnancy_history` (Triage)
- `lmp` (Triage)
- `breastfeeding` (Triage)
- `lab_results` items where `lab_test = pregnancy_test` (Lab)
- `sys_gyn_ob` (Clinical Evaluation)

*Note*: The complete field map should be validated with clinical staff during implementation. Additional fields may be added to or removed from this list.

---

## Calculated Fields

| Field | Entity | Calculation | Trigger |
|---|---|---|---|
| `vital_bmi` | Visit | weight(kg) / (height(m))² | On Visit presave |
| `quantity_on_hand` | DrugInventory | SUM(receipts) − SUM(dispensed) | On PrescriptionItem/InventoryReceipt save |
