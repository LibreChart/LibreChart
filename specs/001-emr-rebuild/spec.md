# Feature Specification: Librechart EMR System

**Feature Branch**: `001-emr-rebuild`
**Created**: 2026-03-11
**Status**: Draft

## Overview

Librechart is a free, open-source Electronic Medical Record (EMR) system built on Drupal CMS. It is a ground-up rebuild of an existing clinic management system used by medical mission teams. The rebuild separates the patient identity from individual visit records, introduces a robust pharmacy inventory module, and ties role-based access control to specific clinic workflow stations.

The system serves multi-site medical mission clinics where patients may return across multiple visits. Staff at distinct stations — registration, triage, lab, clinical evaluation, physical therapy, pharmacy, and teaching — each interact with only the portions of the record relevant to their role.

---

## Clarifications

### Session 2026-03-15

- Q: What should happen if two users save the same visit simultaneously? → A: Optimistic locking — if the record changed since a user loaded it, they are warned and must reload before saving.
- Q: What should happen when a logged-in staff member leaves their device unattended? → A: Auto-logout after 30 minutes of inactivity, with a 2-minute warning prompt.
- Q: Should reports be exportable to a file? → A: Yes — CSV and PDF export for all reports.
- Q: Once a visit is marked "complete," should station sections be locked? → A: Soft lock — any user with station access can still edit their section after completion.
- Q: Should the Patient entity maintain revision history of demographic changes? → A: Yes — full revision history; every demographic change is tracked with timestamp and author.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Patient Registration & Check-In (Priority: P1)

A registration staff member creates a new patient record or looks up a returning patient and initiates a visit. The patient's demographic information is recorded once on the patient profile and reused across all future visits.

**Why this priority**: Every other workflow depends on a patient record existing. Without this, no visit data can be recorded. It is the entry point for all other stations.

**Independent Test**: Can be fully tested by creating a patient record and opening a new visit, confirming patient demographics persist across multiple visits without re-entry.

**Acceptance Scenarios**:

1. **Given** a new patient arrives, **When** the registration staff member searches by name or ID number (cedula), **Then** the system confirms no duplicate record exists and allows creation of a new patient.
2. **Given** a returning patient arrives, **When** the registration staff member searches by cedula or name, **Then** the system surfaces the existing patient record and allows a new visit to be started.
3. **Given** a patient record exists, **When** a new visit is created, **Then** the visit is linked to the patient and patient demographics are automatically carried forward without re-entry.
4. **Given** a patient has multiple visits, **When** staff view the patient profile, **Then** all past visits are listed chronologically.

---

### User Story 2 - Triage Station (Priority: P2)

A triage nurse opens the current visit record for a patient and records chief complaints, medical history, and vital signs (temperature, pulse, respiration, blood pressure, height, weight, BMI).

**Why this priority**: Triage data is required before clinical evaluation and lab ordering can take place. It establishes the clinical context for the rest of the visit.

**Independent Test**: Can be tested by completing the triage section for a visit and confirming the data is visible to subsequent stations without a clinician or pharmacist being involved.

**Acceptance Scenarios**:

1. **Given** a visit is open, **When** the triage nurse accesses the triage section, **Then** only triage-relevant fields are editable and all other station sections are read-only or hidden.
2. **Given** a patient has past visits, **When** a triage nurse opens a new visit, **Then** they can reference prior visit triage notes as read-only context.
3. **Given** a triage nurse records vital signs, **When** the record is saved, **Then** all values are stored against the visit and a calculated BMI is displayed.

---

### User Story 3 - Lab Orders & Results (Priority: P3)

A triage nurse or clinician orders lab tests for a patient visit. A lab technician later records results for each ordered test. Conditional result fields appear only for tests that were ordered.

**Why this priority**: Lab results may inform the clinical evaluation. Ordering and recording results are distinct actions by different staff members.

**Independent Test**: Can be tested end-to-end by a triage nurse ordering labs, then a lab technician entering results, and confirming only ordered tests show result fields.

**Acceptance Scenarios**:

1. **Given** a visit is in triage, **When** a triage nurse selects one or more lab tests, **Then** those tests are flagged as ordered and result fields become available to the lab technician.
2. **Given** a lab test has been ordered, **When** the lab technician enters the result, **Then** the result is stored against the visit and is visible to the clinician.
3. **Given** a test was not ordered, **When** the lab technician views the record, **Then** no result entry field is shown for that test.

---

### User Story 4 - Clinical Evaluation (Priority: P4)

A clinician reviews triage notes and lab results, documents a clinical assessment, assigns diagnoses by body system, records orders and referrals, and signs the evaluation. The clinician's name is captured on the visit record.

**Why this priority**: The clinical evaluation is the core medical encounter. Diagnoses and treatment decisions made here drive pharmacy, PT, and referral actions.

**Independent Test**: Can be tested by a clinician completing an evaluation for a visit with pre-filled triage/lab data, confirming diagnoses are assigned from system-specific vocabularies and the record is attributable to the clinician.

**Acceptance Scenarios**:

1. **Given** triage and lab data is recorded, **When** a clinician opens the clinical evaluation section, **Then** triage notes and lab results are visible as read-only context.
2. **Given** a clinician is evaluating a patient, **When** they select diagnoses, **Then** diagnoses are chosen from categorized vocabulary lists organized by body system (cardiac, ENT, GI, dermatology, neuro, respiratory, GYN/OB, endocrine, musculoskeletal, vascular, wound/ostomy, mental health, uro-genital, ophthalmology).
3. **Given** a clinician adds referrals or additional orders, **When** the evaluation is saved, **Then** referrals are visible to teaching/referral staff and orders are visible to relevant station staff.
4. **Given** an evaluation is complete, **When** saved, **Then** the clinician's name and the visit timestamp are stored on the record.

---

### User Story 5 - Pharmacy Dispensing (Priority: P5)

A pharmacist reviews prescribed medications from the clinical evaluation, selects drugs from categorized inventory lists, records the dosage dispensed for each individual drug, and marks prescriptions as filled. Each drug prescribed has its own dosage field.

**Why this priority**: Pharmacy dispensing directly ties to patient care. Per-drug dosage tracking is a core improvement over the legacy system's single shared dosage field.

**Independent Test**: Can be tested by a pharmacist fulfilling a multi-drug prescription, verifying each drug has its own dosage field, and confirming inventory counts decrease accordingly.

**Acceptance Scenarios**:

1. **Given** a clinician has prescribed medications, **When** a pharmacist opens the pharmacy section, **Then** prescribed drug categories and individual drugs are displayed with individual dosage fields for each drug.
2. **Given** a pharmacist fills a prescription, **When** they save the dispensing record, **Then** the inventory count for each dispensed drug decreases by the dispensed quantity.
3. **Given** a drug is out of stock, **When** a pharmacist attempts to dispense it, **Then** the system warns that inventory is insufficient before allowing override or substitution.
4. **Given** multiple drug categories are prescribed, **When** the pharmacist views the section, **Then** each category is presented in a collapsible group for navigation clarity.

---

### User Story 6 - Pharmacy Inventory Management (Priority: P6)

A pharmacist adds drug stock to the inventory, views current stock levels across all drug categories, and generates an inventory report showing quantities on-hand and dispensed totals.

**Why this priority**: Inventory management is a significant new capability absent in the legacy system. It enables mission teams to plan drug resupply and track usage.

**Independent Test**: Can be tested independently of patient visits by adding drug stock entries and generating a report, with no clinical data required.

**Acceptance Scenarios**:

1. **Given** a drug shipment arrives, **When** a pharmacist records an inventory receipt, **Then** the on-hand quantity for that drug increases by the received amount.
2. **Given** drugs have been dispensed over time, **When** a pharmacist views the inventory report, **Then** each drug shows current on-hand quantity, total received, and total dispensed.
3. **Given** a pharmacist accesses the inventory report, **When** they filter by clinic site or date range, **Then** the report reflects only the selected scope.
4. **Given** stock falls below a configurable threshold, **When** a pharmacist views the report, **Then** low-stock drugs are visually flagged.

---

### User Story 7 - Physical Therapy (Priority: P7)

A physical therapist accesses the PT section of a visit record for a patient referred by the clinician, records treatment notes and interventions, and logs their name on the visit.

**Why this priority**: PT is a secondary station only activated by clinical referral, so it has lower baseline priority but is still core to the clinical workflow.

**Independent Test**: Can be tested by creating a visit with a PT referral from the clinical section and confirming the PT section is accessible to the PT role and not to other roles.

**Acceptance Scenarios**:

1. **Given** a clinician has referred a patient to PT, **When** a PT staff member opens the visit, **Then** the PT section is editable and pre-populated with the referral reason.
2. **Given** a PT staff member records treatment, **When** saved, **Then** the PT name and treatment notes are stored against the visit.

---

### User Story 8 - Teaching & Referrals (Priority: P8)

Teaching/referral staff record educational sessions attended by the patient and note external referrals. Referral destinations are selected from a managed list.

**Why this priority**: Teaching and referrals are end-of-visit activities and can be developed after core clinical and pharmacy workflows.

**Independent Test**: Can be tested by recording a teaching session and external referral on a visit, independently of clinical evaluation results.

**Acceptance Scenarios**:

1. **Given** a patient receives health education, **When** teaching staff record the session, **Then** the session topic and date are stored against the visit.
2. **Given** a patient requires an external referral, **When** staff enter the referral destination, **Then** it is recorded against the visit and visible to administrative staff.

---

### User Story 9 - Patient Visit History (Priority: P9)

Any authorized staff member can view the complete clinical history of a patient across all visits — including triage notes, diagnoses, medications dispensed, and referrals — in a structured timeline.

**Why this priority**: Patient history is a read-only view that builds on all other data; it can be implemented once data entry workflows are complete.

**Independent Test**: Can be tested by reviewing a patient with 3+ visits and confirming all station data from each visit is accessible in a structured, chronological view.

**Acceptance Scenarios**:

1. **Given** a patient has multiple visits, **When** a clinician views the patient profile, **Then** each visit is listed with date, site, and summary of diagnoses and medications.
2. **Given** a clinician selects a specific past visit, **When** opened, **Then** all station data from that visit is visible in read-only mode.

---

### User Story 10 - Pediatric Visit Form Adaptation (Priority: P2)

A registration staff member marks a visit as "pediatric" and the form immediately adapts — hiding fields that are clinically inappropriate for pediatric patients and showing any fields specific to pediatric care. Staff at all subsequent stations see the same adapted form for that visit.

**Why this priority**: Showing adult-specific fields (e.g., pregnancy history, LMP, GYN/OB assessment) for a pediatric patient creates clinical confusion and increases the risk of erroneous data entry. This is a patient safety requirement and must be addressed early in the visit workflow.

**Independent Test**: Can be fully tested by creating two identical visits (one adult, one pediatric) and verifying the correct fields appear and are hidden for each, without any other station needing to be involved.

**Acceptance Scenarios**:

1. **Given** a new visit is being created, **When** the registration staff member selects "Pediatric" as the patient type, **Then** all adult-specific fields are immediately hidden and any pediatric-specific fields are shown across all station sections.
2. **Given** a visit is marked as pediatric, **When** a triage nurse opens the triage section, **Then** pregnancy history, last menstrual period, and breastfeeding fields are not visible or enterable.
3. **Given** a visit is marked as pediatric, **When** the lab section is opened, **Then** the pregnancy test ordering field and result field are hidden.
4. **Given** a visit is marked as pediatric, **When** a clinician opens the clinical evaluation section, **Then** the GYN/OB body system assessment is hidden.
5. **Given** a visit is marked as adult, **When** any station is opened, **Then** all adult-specific fields are visible as normal.
6. **Given** a visit type is changed from adult to pediatric (or vice versa) before any clinical data is entered, **When** the change is saved, **Then** the form re-adapts and any data entered in now-hidden fields is cleared and flagged to the user before saving.

---

### User Story 11 - Physician Dictation via Speech-to-Text (Priority: P4)

A clinician clicks a microphone button next to a long-form text field, speaks their clinical notes aloud, and the transcribed text is inserted into the field. The transcription runs entirely on the local server with no audio data leaving the premises.

**Why this priority**: Dictation significantly reduces documentation time for physicians working through high patient volumes at a mission clinic. It is an efficiency improvement rather than a core clinical requirement, so it sits below the primary station workflows in priority.

**Independent Test**: Can be fully tested by activating dictation on the clinical evaluation notes field, speaking a passage, and confirming the transcribed text appears in the field — without any internet connection active.

**Acceptance Scenarios**:

1. **Given** a clinician is filling out a long-form text field, **When** they click the dictation button, **Then** the system begins recording audio from the device microphone and displays a visible recording indicator.
2. **Given** recording is active, **When** the clinician finishes speaking and stops the recording, **Then** the transcribed text is inserted into the field, either appending to existing content or replacing it based on cursor position.
3. **Given** the system processes the audio, **When** transcription is complete, **Then** the clinician can edit the inserted text before saving the record.
4. **Given** the LAN server is unavailable or transcription fails, **When** a clinician attempts dictation, **Then** the system displays a clear error and the text field remains editable by keyboard — dictation failure must never block manual data entry.
5. **Given** no internet connection is present, **When** a clinician uses dictation, **Then** the feature functions identically — all transcription is handled on the local server.

---

### User Story 12 - Spanish Language Interface (Priority: P3)

A Spanish-speaking clinic staff member logs in and uses the entire system — all field labels, buttons, navigation, error messages, and reports — in Spanish. English remains available as an alternate language for mission team members who prefer it.

**Why this priority**: The clinic is located at a Spanish-speaking mission site. Staff who are local nationals will use Spanish as their primary language. An untranslated English interface creates errors and slows clinical workflows. Priority P3 places it after core station workflows are complete but before go-live.

**Independent Test**: Can be fully tested by setting a user's language preference to Spanish and verifying every visible UI string on a complete visit workflow is in Spanish, with no English strings remaining in labels, buttons, or messages.

**Acceptance Scenarios**:

1. **Given** a staff member's account is set to Spanish, **When** they log in and navigate the system, **Then** all interface labels, field names, buttons, status messages, and navigation items are displayed in Spanish.
2. **Given** the system has two languages available, **When** a user with an English preference logs in, **Then** the interface displays in English without affecting other users' Spanish preference.
3. **Given** a custom error or validation message is displayed, **When** the user's language is Spanish, **Then** the message appears in Spanish.
4. **Given** a report is generated, **When** the user's language is Spanish, **Then** all column headers, labels, and filter options in the report are in Spanish.
5. **Given** the system is installed on the LAN server with no internet access, **When** Spanish is set as the default language, **Then** all Spanish translations are available without requiring an internet connection.

---

### Edge Cases

- What happens when a patient with no cedula (ID number) needs to be registered? System must support registration with name and date of birth as fallback identifiers.
- How does the system handle a visit started at one station if a prior station has not been completed? Downstream stations should be accessible but clearly indicate upstream data is missing.
- What happens when a pharmacist attempts to dispense a drug with zero inventory? System should warn and require override with a documented reason.
- How are duplicate patient records handled if a patient is registered twice? The system should surface potential duplicates during search before allowing new record creation.
- What happens if a visit is partially completed and the clinic closes? Partial visit records must be saveable in draft state and resumable.
- What happens if a visit type is changed from adult to pediatric after some adult-specific data (e.g., pregnancy history) has already been entered? The system must warn staff that changing patient type will remove data in hidden fields before confirming the change.
- What happens if a clinician's browser does not support microphone access (e.g., permission denied, no microphone hardware)? The dictation button must be hidden or disabled gracefully, with no impact on the rest of the form.
- What happens if the local transcription service is under load from simultaneous dictation requests? The system must queue or reject gracefully with a clear message rather than silently returning garbled text.
- What happens if two users save the same visit at the same moment? The system uses optimistic locking: if the record was modified since the user last loaded it, the save is rejected with a clear message instructing the user to reload and re-apply their changes. No silent data overwrite occurs.

---

## Requirements *(mandatory)*

### Functional Requirements

**Patient Management**

- **FR-001**: System MUST provide a distinct Patient entity to store demographic information (name, date of birth, cedula/ID number, municipality, village/town, sex) separately from visit records.
- **FR-001a**: The Patient entity MUST maintain full revision history — every change to demographic fields is stored with the timestamp and the staff member who made the change, and previous values must be restorable.
- **FR-002**: System MUST allow staff to search for patients by name, cedula, and date of birth.
- **FR-003**: System MUST surface potential duplicate patient records during registration based on name and date of birth similarity.
- **FR-004**: System MUST allow a patient to have an unlimited number of visit records over time.
- **FR-005**: System MUST associate each visit with a specific clinic site.

**Visit Records & Clinic Stations**

- **FR-006**: System MUST provide a Visit record type linked to a Patient, capturing the date of visit, patient type (adult/pediatric), and clinic site.
- **FR-007**: System MUST organize visit data into discrete station sections: Registration/Check-In, Triage, Lab Orders & Results, Clinical Evaluation, Physical Therapy, Pharmacy Dispensing, and Teaching & Referrals.
- **FR-008**: System MUST record vital signs (temperature, pulse, respiration, systolic BP, diastolic BP, height, weight) as part of the triage station and auto-calculate BMI.
- **FR-009**: System MUST support ordering lab tests with conditional result entry fields that appear only when a test has been ordered.
- **FR-010**: System MUST allow clinicians to assign diagnoses by body system using managed taxonomy vocabularies (cardiac, ENT, GI, dermatology, neurological, respiratory, GYN/OB, endocrine, musculoskeletal, vascular, wound/ostomy, mental health, uro-genital, ophthalmology/otic).
- **FR-011**: System MUST capture the name of the staff member responsible for each station (clinician, pharmacist, PT/OT).
- **FR-012**: System MUST support referrals and additional orders from the clinical evaluation section.
- **FR-013**: System MUST support recording physical therapy treatment notes and interventions when a patient is referred to PT.
- **FR-014**: System MUST support recording teaching/educational sessions and external referrals at the end of a visit.
- **FR-015**: System MUST maintain full revision history for all visit records.
- **FR-015a**: System MUST implement optimistic locking on Visit records — if a user attempts to save a visit that was modified by another user since it was loaded, the save MUST be rejected with a clear message instructing the user to reload before resubmitting.
- **FR-015b**: Visit completion status is informational (soft lock) — marking a visit "complete" does not prevent further edits by users with the appropriate station role. Edits to completed visits are tracked in the revision history.

**Pediatric / Adult Conditional Form Behavior**

- **FR-016**: System MUST display different sets of form fields depending on whether a visit is marked as "adult" or "pediatric" at the point of registration.
- **FR-017**: When a visit is marked as "pediatric", the following fields MUST be hidden across all station sections:
  - *Triage*: Pregnancy history, Last Menstrual Period (LMP), Breastfeeding
  - *Lab Orders*: Pregnancy test ordering and pregnancy test result
  - *Clinical Evaluation*: GYN/OB body system assessment
  - All other fields (including diabetic risk score and gestational diabetes) remain visible for both adult and pediatric visits.
  - **Note for implementation**: The complete field map must be reviewed and validated with clinical staff during development. Additional fields may need to be added to or removed from this list based on clinical workflow review.
- **FR-018**: When a visit is marked as "adult", all fields defined in the standard form MUST be visible and enterable.
- **FR-019**: The patient type selection (adult/pediatric) MUST be made at check-in before any clinical stations are accessed, and must propagate the correct field visibility to all downstream station sections for that visit.
- **FR-020**: If a user attempts to change the patient type after clinical data has been entered in fields that would be hidden by the new type, the system MUST warn the user that data will be lost and require explicit confirmation before proceeding.

**Pharmacy**

- **FR-021**: System MUST allow each prescribed drug to have its own individual dosage field (replacing the legacy single shared dosage field).
- **FR-022**: System MUST organize prescribed drugs into collapsible category groups (anti-infective agents, GI agents, cardiac agents, dermatologic agents, respiratory agents, pain management, vitamins/nutrients, ophthalmic/otic, miscellaneous, chronic disease medications).
- **FR-023**: System MUST maintain a drug inventory tracking on-hand quantity per drug per clinic site.
- **FR-024**: System MUST decrease inventory on-hand quantity when a drug is dispensed to a patient.
- **FR-025**: System MUST allow pharmacists to record inventory receipts (stock additions) with quantity and date.
- **FR-026**: System MUST provide an inventory report showing each drug's current on-hand quantity, cumulative quantity received, and cumulative quantity dispensed.
- **FR-027**: System MUST visually flag drugs with on-hand quantity below a configurable low-stock threshold.
- **FR-028**: System MUST warn pharmacists when attempting to dispense a drug with insufficient inventory, but allow override with documented reason.

**Role-Based Access Control**

- **FR-028a**: System MUST automatically log out authenticated users after 30 minutes of inactivity. A visible warning MUST be displayed 2 minutes before the session expires, giving the user the option to extend their session.
- **FR-028b**: Unsaved form data MUST be preserved (or the user warned of pending loss) before an inactivity logout is executed.

- **FR-029**: System MUST provide distinct user roles corresponding to each clinic station: Registration Staff, Triage Nurse, Lab Technician, Clinician, Physical Therapist, Pharmacist, Teaching Coordinator, and Administrator.
- **FR-030**: System MUST restrict edit access on each station section to the role responsible for that station.
- **FR-031**: System MUST allow all clinical roles to view (read-only) data from stations they do not own, to support clinical decision-making.
- **FR-032**: System MUST allow Administrators to manage taxonomy vocabularies, drug lists, clinic sites, and user accounts.

**Reporting**

- **FR-033**: System MUST provide demographic reports showing patient counts by age group, sex, and municipality.
- **FR-034**: System MUST provide a diagnosis frequency report showing most common diagnoses by system and visit period.
- **FR-035**: System MUST provide a pharmacy inventory report filterable by clinic site and date range.
- **FR-035a**: All reports (demographic, diagnosis frequency, pharmacy inventory) MUST be exportable to both CSV and PDF formats directly from the report interface, without requiring technical staff involvement.

**Spanish Language Translation**

- **FR-036**: System MUST provide a fully translated Spanish-language interface covering all field labels, buttons, navigation, validation messages, error messages, and report headings.
- **FR-037**: System MUST allow each user account to have an individual language preference (Spanish or English), with the interface rendering in that user's chosen language upon login.
- **FR-038**: Spanish MUST be configured as the system default language so that new user accounts and the login page display in Spanish out of the box.
- **FR-039**: All Spanish translation files MUST be bundled with the project and available without any internet connection, consistent with the LAN-only deployment model.
- **FR-040**: All custom module interface strings MUST be marked as translatable and included in the Spanish translation.
- **FR-041**: The dictation feature MUST support Spanish-language audio transcription as a configurable option, in addition to English.

**Speech-to-Text Dictation**

- **FR-042**: System MUST provide a dictation button on all long-form narrative text fields used by clinical staff (including but not limited to: triage chief complaint, past medical history, clinical evaluation notes, physical therapy treatment notes, and any other multi-line text field intended for clinician narrative).
- **FR-043**: The speech-to-text transcription engine MUST run entirely on the local LAN server with no audio data transmitted to any external service or cloud provider.
- **FR-044**: Transcribed text MUST be inserted into the target field at the current cursor position, appending to any existing content rather than replacing it, unless the field is empty.
- **FR-045**: The clinician MUST be able to edit transcribed text before saving the visit record.
- **FR-046**: Dictation failure (transcription error, service unavailable, microphone unavailable) MUST NOT prevent manual keyboard entry into the field — dictation is an enhancement only and must degrade gracefully.
- **FR-047**: The system MUST display a clear visual indicator while recording is active, and a clear status message when transcription is in progress or has failed.
- **FR-048**: The dictation feature MUST function without any active internet connection, consistent with the system's LAN-only deployment model.

### Key Entities

- **Patient**: Represents a unique individual. Holds demographics (name, DOB, cedula, sex, municipality, village/town). Has many Visits. Persists across clinic visits. Maintains full revision history of all demographic changes.
- **Visit**: Represents a single clinical encounter. Linked to a Patient and a Clinic Site. Contains all station data. Has a status (in-progress, complete). Has many Prescriptions and Lab Results.
- **Station Section**: A logical grouping of fields within a Visit, associated with a clinic role. Sections: Triage, Lab, Clinical Evaluation, Physical Therapy, Pharmacy, Teaching.
- **Drug**: A prescribable item within a category vocabulary. Has an associated inventory record per clinic site.
- **Drug Inventory Record**: Tracks on-hand quantity, receipts, and dispensing events for a Drug at a Clinic Site.
- **Dispensing Event**: Records a specific drug dispensed during a Visit, including drug, quantity, dosage, and pharmacist.
- **Inventory Receipt**: Records a stock addition event: drug, quantity received, date, and pharmacist.
- **Diagnosis Vocabulary**: A hierarchical taxonomy organizing diagnoses by body system. Terms represent individual diagnoses.
- **Drug Category Vocabulary**: A taxonomy organizing drugs by therapeutic category. Terms represent individual drugs.
- **Clinic Site**: A physical clinic location. Visits and inventory records are scoped to a clinic site.
- **User (Staff)**: A system user with one or more roles. Assigned to a clinic site. Attributed on Visit records for their station.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Registration staff can locate a returning patient and initiate a new visit in under 60 seconds.
- **SC-002**: All clinic station sections for a single visit can be completed by different staff members without data loss or overwrite conflicts.
- **SC-003**: Pharmacists can view accurate on-hand drug inventory at any time, with dispensing events reflected in real time.
- **SC-004**: Generating the pharmacy inventory report for a clinic site takes under 10 seconds regardless of visit volume.
- **SC-005**: A patient's complete visit history across all prior visits is accessible from the patient profile without navigating away.
- **SC-006**: Each clinic role can access only their station's editable fields, with zero ability to modify data owned by another station.
- **SC-007**: Demographic, diagnosis frequency, and pharmacy inventory reports can be generated and exported to CSV or PDF for any date range without requiring technical staff involvement.
- **SC-008**: The system supports operation across at least 3 simultaneous clinic sites with separate inventory tracking per site.
- **SC-009**: A new staff member assigned the correct role can complete their station workflow without training beyond their role, due to station-focused interface design.
- **SC-010**: Zero patient demographic data is lost or needs re-entry when a patient returns for a subsequent visit.
- **SC-011**: A clinician can dictate a one-minute passage of clinical notes and receive transcribed text in the field within 10 seconds, without an internet connection.
- **SC-012**: Dictation feature failure never blocks a clinician from completing their station via keyboard — manual entry must always be available regardless of dictation service status.
- **SC-013**: A Spanish-speaking staff member can complete a full visit workflow — from patient registration through teaching/referrals — with zero English strings visible in the interface.

---

## Assumptions

- Patients do not have self-service accounts; all data entry is performed by clinic staff.
- The system will be operated by staff with basic computer literacy; complex technical workflows should be avoided.
- The existing taxonomy vocabulary content (diagnoses, drug names) from the legacy system will be migrated to the new system.
- The "cedula" (national ID number) is the primary unique patient identifier, but patients without a cedula can be registered using name and date of birth.
- Pharmacy inventory is tracked per clinic site, not globally, to support independent multi-site operations.
- The system operates on a LAN-hosted server with no internet dependency; this is the intended and confirmed deployment model.
- Age group calculations (5-year bands) will be retained from the legacy system for reporting consistency.
- All patient-identifying data is protected and access is restricted to authenticated staff; compliance with local health data regulations is assumed to be enforced at the hosting/deployment level.
- Sessions expire after 30 minutes of inactivity. A 2-minute warning prompt is displayed before automatic logout to prevent data loss on in-progress forms.
- The legacy "pain management" paragraph type will be flattened into a standard field group on the Pharmacy station to simplify the data model.
- Spanish is the primary operating language of the clinic staff. Spanish translation files for Drupal core, contrib modules, and all custom strings must be bundled with the project repository and committed to version control, since the production server has no internet access to download them at runtime.
- Speech-to-text transcription is provided by a self-hosted transcription service running on the LAN server. Browser-based cloud speech APIs (e.g., the Web Speech API) are explicitly excluded as they require external connectivity and transmit audio off-premises. Research into viable self-hosted options is documented in `specs/research/speech-to-text.md`.

---

## Deployment Context

The system is deployed on a small Linux web server hosted physically onsite at the mission location. Staff access the system over a local area network (LAN). The server does not depend on internet connectivity to function — it serves the application entirely from the local network. Internet access at the mission site is limited and cannot be relied upon for day-to-day clinic operations.

This means:
- All clinic workflows must function without any outbound internet connection.
- The system must not depend on external CDNs, cloud services, or third-party APIs for core functionality.
- Backups, software updates, and any external access are secondary concerns handled outside clinic hours.
- Staff devices (laptops, tablets) connect to the local server over WiFi or ethernet; if a device loses its LAN connection, that session is interrupted but the server and its data remain intact.

---

## Constraints & Dependencies

- Must be built on the latest stable release of Drupal CMS.
- Must follow Drupal coding standards and use Drupal's configuration management system for all content model definitions.
- Drug and diagnosis vocabulary content from the legacy system at `/Users/aaronellison/Sites/mission` should be migrated.
- Custom views-based reporting should leverage Drupal Views for maintainability.
- Role-based field access should use Drupal's native field access API or a contrib module rather than custom access logic where possible.
- All front-end assets (CSS, JavaScript, fonts) must be self-hosted; no external CDN dependencies.
- The system must be deployable and fully operational on a modest Linux server without internet access.

---

## Out of Scope

- Patient portal or patient-facing access to their own records.
- Electronic prescriptions, fax, or external pharmacy system integration.
- Billing, insurance, or payment processing.
- Appointment scheduling.
- Integration with external lab systems (results are entered manually).
- True offline-first operation on individual devices (e.g., PWA with local storage) — the onsite server is always the source of truth and must be reachable for data entry.
