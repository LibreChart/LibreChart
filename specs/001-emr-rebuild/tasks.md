# Tasks: Librechart EMR Rebuild

**Input**: Design documents from `specs/001-emr-rebuild/`
**Feature Branch**: `001-emr-rebuild`
**Generated**: 2026-03-15

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story. No test tasks are included (not requested in spec).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks in same phase)
- **[Story]**: User story label for all user story phase tasks (e.g., [US1])
- All tasks include exact file paths

---

## Phase 1: Setup

**Purpose**: Bootstrap the project environment and quality tooling before any Drupal work begins.

- [X] T001 Create Drupal CMS project via `composer create-project drupal/cms-project .` and commit initial composer.json and composer.lock
- [X] T002 Configure DDEV for PHP 8.3, MySQL/MariaDB, and docroot `web/` in .ddev/config.yaml
- [X] T003 [P] Configure PHPCS for Drupal coding standards with 120-character line limit in phpcs.xml.dist
- [X] T004 [P] Configure PHPStan at level 6 for web/modules/custom/ in phpstan.neon.dist
- [X] T005 Create custom module and theme directory scaffolding: web/modules/custom/, web/themes/custom/, translations/, config/sync/

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core Drupal CMS configuration, multilingual setup, user roles, taxonomy vocabulary shells, and contrib module installs that MUST be complete before any user story can be implemented.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T006 Disable non-essential Drupal CMS modules (experience_builder, ai_assistant, project_browser, automatic_updates) by removing them from config/sync/core.extension.yml
- [X] T007 [P] Enable core multilingual modules (language, locale, config_translation, interface_translation) in config/sync/core.extension.yml
- [X] T008 Configure Spanish (es) as the system default language and English as secondary in config/sync/language.entity.language.es.yml and config/sync/language.negotiation.yml
- [X] T009 [P] Require all contrib modules via composer: drupal/gin, drupal/paragraphs, drupal/field_group, drupal/field_permissions, drupal/conditional_fields, drupal/search_api, drupal/autologout, drupal/migrate_plus, drupal/migrate_tools, drupal/entity_print in composer.json
- [X] T010 Configure autologout module: 30-minute inactivity timeout and 2-minute warning prompt with unsaved data warning in config/sync/autologout.settings.yml
- [X] T011 Create all 8 user role configuration files (registration_staff, triage_nurse, lab_technician, clinician, physical_therapist, pharmacist, teaching_coordinator, plus administrator) in config/sync/user.role.*.yml with base authenticated user permissions
- [X] T012 [P] Create configuration files for all taxonomy vocabularies — administrative (clinic_sites, municipalities, village_town, referrals, allergies, orders, teaching_topics), all diagnosis vocabularies (chronic_diseases, allergy_treatment_agents, cardiac_thoracic, cardiac_treatment_agents, derm, dermatologic_agents, endo, ent, eye, gi, gastrointestinal_treatment_agent, gyn_ob, mental_health, muscular_skeletal, neuro, opthalmic_otic, resp, respiratory_treatments, uro_genital, vascular, wound_ostomy, pain_management, physical_therapy_treatment, vitamins_nutrients_lv, anti_infective_agents, miscellaneous), and all drug category vocabularies (anti_infective_agents, gi_agents, cardiac_treatment_agents, dermatologic_agents, respiratory_treatments, pain_management, vitamins_nutrients_lv, opthalmic_otic, miscellaneous, chronic_diseases) as empty vocabularies in config/sync/taxonomy.vocabulary.*.yml
- [X] T013 Scaffold librechart_theme as a Gin admin subtheme with info.yml declaring `base theme: gin`, an empty libraries.yml, and placeholder css/style.css in web/themes/custom/librechart_theme/

**Checkpoint**: Foundation is ready — all 8 user story phases can now proceed in priority order.

---

## Phase 3: User Story 1 — Patient Registration & Check-In (Priority: P1) 🎯 MVP

**Goal**: A working Patient entity with full revision history, a Visit entity with optimistic locking, patient search by name/cedula/DOB, and a registration workflow allowing a staff member to create a patient and start a visit.

**Independent Test**: Create a new Patient record, verify revision history is created on demographic change, start a new Visit linked to that Patient, confirm the Visit is linked and patient demographics appear without re-entry. Confirm two simultaneous saves of the same Visit produce an optimistic lock error on the second save.

- [X] T014 [US1] Create librechart_patient module scaffold: librechart_patient.info.yml (type: module, core_version_requirement: ^11), librechart_patient.module, and composer.json in web/modules/custom/librechart_patient/
- [X] T015 [P] [US1] Implement PatientInterface defining getFirstName(), getLastName(), getCedula(), getDateOfBirth(), getSex(), getMunicipality(), getVillage() method signatures in web/modules/custom/librechart_patient/src/Entity/PatientInterface.php
- [X] T016 [P] [US1] Implement Patient content entity class with @ContentEntityType annotation, revision_table, revision_metadata_keys (revision_log_message, revision_uid, revision_timestamp), base table `patient`, and all field definitions (first_name, last_name, date_of_birth, sex, cedula, municipality, village_town) in web/modules/custom/librechart_patient/src/Entity/Patient.php
- [X] T017 [US1] Define Patient entity field storage and field instance configuration for all demographic fields (first_name string(128), last_name string(128), date_of_birth datetime date-only, sex list_string [male, female, other], cedula string(64) unique, municipality entity_reference→municipalities, village_town entity_reference→village_town) in web/modules/custom/librechart_patient/config/install/
- [X] T018 [US1] Configure Patient entity default form display (core.entity_form_display.patient.patient.default.yml) and default view display (core.entity_view_display.patient.patient.default.yml) in web/modules/custom/librechart_patient/config/install/
- [X] T019 [US1] Create librechart_visit module scaffold: librechart_visit.info.yml (depends: [librechart_patient]), librechart_visit.module in web/modules/custom/librechart_visit/
- [X] T020 [P] [US1] Implement Visit content entity class with @ContentEntityType annotation, revisions enabled, optimistic locking via changed-timestamp check in preSave(), and core field definitions (patient entity_reference→Patient, visit_date datetime, patient_type list_string [adult, pediatric], clinic_site entity_reference→clinic_sites, status list_string [in_progress, complete], changed timestamp) in web/modules/custom/librechart_visit/src/Entity/Visit.php
- [X] T021 [US1] Define Visit entity field storage and instance config for core fields (patient, visit_date, patient_type, clinic_site, status, changed) in web/modules/custom/librechart_visit/config/install/
- [X] T022 [US1] Implement optimistic locking rejection in Visit::preSave(): if $entity->changed->value !== $entity->original->changed->value throw an exception with a translated "Record changed since you loaded it — please reload" message in web/modules/custom/librechart_visit/src/Entity/Visit.php
- [X] T023 [US1] Configure Views-based Patient search view with exposed filters for last_name, first_name, cedula, and date_of_birth; page display at /patients; Results show name, cedula, DOB, and "New Visit" link in web/modules/custom/librechart_patient/config/install/views.view.patient_search.yml
- [X] T024 [US1] Configure Views-based patient visit history listing at /patient/{patient}/visits showing visit_date, clinic_site, status columns in chronological order in web/modules/custom/librechart_visit/config/install/views.view.patient_visits.yml
- [X] T025 [US1] Implement PatientDuplicateChecker service that queries patients by exact date_of_birth AND last_name similarity (SOUNDEX or LIKE '%name%'), returning a list of potential matches; inject via hook_form_alter() into the Patient add form with a warning block listing potential duplicates before allowing save in web/modules/custom/librechart_patient/src/Service/PatientDuplicateChecker.php and librechart_patient.module
- [X] T026 [US1] Set entity CRUD permissions for registration_staff role (create/edit Patient, create Visit) and administrator (all) in config/sync/user.role.registration_staff.yml and config/sync/user.role.administrator.yml; grant all clinical roles Patient view permission
- [X] T027 [US1] Export all configuration to config/sync/ via `ddev drush config:export -y` after US1 implementation is complete

**Checkpoint**: Registration Staff can create a Patient, trigger revision on demographic edit, open a new Visit, and confirm optimistic lock fires on concurrent save. Patient search finds by name, cedula, and DOB. Creating a patient with a matching last name and DOB shows a "potential duplicate" warning listing existing matches before allowing save.

---

## Phase 4: User Story 2 — Triage Station (Priority: P2)

**Goal**: All triage fields on the Visit entity, BMI auto-calculation, field grouping with field_group, and field_permissions restricting triage edits to the Triage Nurse role.

**Independent Test**: Complete the triage section for a visit as a Triage Nurse, save, confirm BMI is calculated from height and weight, confirm all other roles can view but not edit triage fields.

- [X] T028 [P] [US2] Add triage field storage and instance config for vitals (vital_temperature decimal, vital_pulse integer 0–300, vital_respiration integer, vital_systolic integer 0–300, vital_diastolic integer 0–300, vital_height decimal, vital_weight decimal, vital_bmi decimal read-only) to Visit entity in web/modules/custom/librechart_visit/config/install/
- [X] T029 [P] [US2] Add triage narrative and clinical history field config (complaint text_long, past_medical_history text_long, allergies entity_reference_multi→allergies vocab with auto-create, current_medications string_long) to Visit entity in web/modules/custom/librechart_visit/config/install/
- [X] T030 [P] [US2] Add pregnancy-related triage field config (pregnancy_history text_long, lmp string(32), breastfeeding list_string [yes, no, n_a]) to Visit entity in web/modules/custom/librechart_visit/config/install/
- [X] T031 [US2] Implement BMI auto-calculation in Visit::preSave(): vital_bmi = vital_weight / (vital_height / 100)^2, stored as decimal(5,2); only calculated when both height and weight are non-zero in web/modules/custom/librechart_visit/src/Entity/Visit.php
- [X] T032 [US2] Configure field_group "Triage" collapsible fieldset on Visit default form display grouping all triage fields in web/modules/custom/librechart_visit/config/install/core.entity_form_display.visit.visit.default.yml
- [X] T033 [US2] Configure field_permissions for triage fields: triage_nurse role gets "edit any [field]" permission for all triage fields; all clinical roles get "view [field]" permission; export permissions to config/sync/ (implemented via hook_entity_field_access() in librechart_visit.module)

**Checkpoint**: Triage Nurse can complete triage section. BMI auto-calculates. All other clinical roles see triage data read-only.

---

## Phase 5: User Story 10 — Pediatric Visit Form Adaptation (Priority: P2)

**Goal**: Conditional field visibility rules that hide adult-specific fields (pregnancy_history, lmp, breastfeeding, pregnancy lab test, sys_gyn_ob) when patient_type = pediatric, with a data-loss warning if patient type is changed after clinical data entry.

**Independent Test**: Create two identical visits — one adult, one pediatric. Verify pregnancy_history, lmp, breastfeeding fields are hidden on the pediatric visit across all station sections. Verify changing patient type from adult to pediatric after entering pregnancy_history triggers a warning.

- [X] T034 [US10] Configure conditional_fields rules on Visit form display: hide pregnancy_history, lmp, and breastfeeding when patient_type = pediatric; store in core.entity_form_display.visit.visit.default.yml third-party settings in web/modules/custom/librechart_visit/config/install/
- [X] T035 [US10] Configure conditional_fields rule on Visit form display: hide LabResult paragraph items where lab_test = pregnancy_test when patient_type = pediatric in web/modules/custom/librechart_visit/config/install/core.entity_form_display.visit.visit.default.yml (pregnancy_history hidden; pregnancy_test within paragraph is a UI-layer constraint)
- [X] T036 [US10] Configure conditional_fields rule on Visit form display: hide sys_gyn_ob field when patient_type = pediatric in web/modules/custom/librechart_visit/config/install/core.entity_form_display.visit.visit.default.yml
- [X] T037 [US10] Implement hook_form_alter() in librechart_visit.module: when patient_type changes from adult to pediatric and any hidden fields contain data, add a JavaScript confirm() warning "Changing to pediatric will clear [fields]. Continue?" before saving in web/modules/custom/librechart_visit/librechart_visit.module
- [X] T038 [US10] Export updated Visit form display configuration to config/sync/ via `ddev drush config:export -y`

**Checkpoint**: Adult and pediatric visits show correct field sets. Attempting to switch patient type after data entry warns before clearing hidden field values.

---

## Phase 6: User Story 3 — Lab Orders & Results (Priority: P3)

**Goal**: LabResult paragraph type, Visit lab_results paragraph reference field, conditional result fields visible only when a test is ordered, and role-based access separating ordering (Triage Nurse/Clinician) from results entry (Lab Technician).

**Independent Test**: Order two lab tests as a Triage Nurse; enter results as a Lab Technician; confirm result entry fields only appear for ordered tests; confirm unordered tests show no result fields.

- [X] T039 [US3] Create librechart_lab module scaffold: librechart_lab.info.yml, and config/install/ directory in web/modules/custom/librechart_lab/
- [X] T040 [US3] Define LabResult paragraph type with fields: lab_test (list_string: glucose, hgba1c, hgb, leukocytes, ketone, blood, protein, urinalysis, pregnancy_test), lab_ordered (boolean), lab_result_value (string 256), lab_result_notes (text_long) in web/modules/custom/librechart_lab/config/install/paragraphs.paragraphs_type.lab_result.yml and field config files
- [X] T041 [US3] Add lab_results entity_reference_revisions field (→ LabResult paragraph, unlimited cardinality) to Visit entity in web/modules/custom/librechart_visit/config/install/
- [X] T042 [US3] Configure conditional_fields on LabResult paragraph form display: show lab_result_value and lab_result_notes only when lab_ordered = true in web/modules/custom/librechart_lab/config/install/core.entity_form_display.paragraph.lab_result.default.yml
- [X] T043 [US3] Configure field_group "Lab Orders & Results" on Visit form display enclosing lab_results paragraph field in web/modules/custom/librechart_visit/config/install/core.entity_form_display.visit.visit.default.yml
- [X] T044 [US3] Configure field_permissions: triage_nurse and clinician roles get edit access on lab_ordered field; lab_technician role gets edit access on lab_result_value and lab_result_notes; all clinical roles get view; export to config/sync/ (implemented via hook_entity_field_access() in librechart_lab.module)
- [X] T045 [US3] Export updated configuration to config/sync/ via `ddev drush config:export -y`

**Checkpoint**: Triage Nurse orders tests; Lab Technician sees result fields only for ordered tests; Clinician views all lab data read-only.

---

## Phase 7: User Story 12 — Spanish Language Interface (Priority: P3)

**Goal**: Full Spanish translation coverage — committed PO files, all config labels translated, all custom module strings wrapped in t()/Drupal.t(), per-user language preference enabled.

**Independent Test**: Set a user account language preference to Spanish. Complete a full patient registration and visit creation workflow confirming zero English strings are visible in field labels, buttons, navigation, validation messages, or status messages.

- [X] T046 [US12] Download Spanish PO files for Drupal core and all enabled contrib modules using `ddev drush locale:check && ddev drush locale:update --langcodes=es`, export to translations/es.po via `ddev drush locale:export es --types=not-customized --file=translations/es.po`, and commit translations/ to the repository
- [X] T047 [US12] Configure locale module to import translations from the committed translations/es.po file on install (not from localize.drupal.org) in config/sync/locale.settings.yml with `use_source: local` and translation path pointing to translations/
- [X] T048 [US12] Audit all custom module PHP files in web/modules/custom/ for user-facing strings not wrapped in t() or $this->t(); wrap every untranslated string in the correct translation function
- [X] T049 [US12] Audit all custom module JavaScript files in web/modules/custom/ for user-facing strings not wrapped in Drupal.t(); wrap every untranslated string in Drupal.t()
- [ ] T050 [US12] Translate all entity field labels, group headings, help text, and taxonomy vocabulary labels via config_translation for the `es` language; export translated config to config/sync/language/es/
- [X] T051 [US12] Enable per-user language preference in config/sync/language.types.yml so each user account can store a language preference; export to config/sync/

**Checkpoint**: A Spanish-preference user sees the complete visit workflow — from patient search through visit creation — with zero English strings in the interface.

---

## Phase 8: User Story 4 — Clinical Evaluation (Priority: P4)

**Goal**: All clinical evaluation fields on the Visit entity (clinical notes, body system assessments, diagnoses, orders, referrals, PT referral, clinician name), field grouping, and Clinician-only edit permissions.

**Independent Test**: Complete clinical evaluation as a Clinician on a visit with pre-filled triage data; assign diagnoses from multiple body system vocabularies; set PT referral; confirm clinician name and timestamp are stored; confirm other roles cannot edit clinical fields.

- [X] T052 [P] [US4] Add Clinical Evaluation core field config (clinical_notes text_long dictation-enabled, clinician_name string(256), dx_write_in text_long, orders entity_reference_multi→orders, referrals entity_reference_multi→referrals, pt_referral boolean) to Visit entity in web/modules/custom/librechart_visit/config/install/
- [X] T053 [P] [US4] Add 14 body system boolean field configs (sys_cardiac, sys_derm, sys_endo, sys_ent, sys_eye, sys_gi, sys_gyn_ob, sys_mental_health, sys_musculoskeletal, sys_neuro, sys_respiratory, sys_uro_genital, sys_vascular, sys_wound_ostomy) to Visit entity in web/modules/custom/librechart_visit/config/install/
- [X] T054 [P] [US4] Add diagnosis entity_reference_multi field config for each body system vocabulary (dx_chronic_diseases, dx_cardiac, dx_derm, dx_endo, dx_ent, dx_eye, dx_gi, dx_gyn_ob, dx_mental_health, dx_muscular_skeletal, dx_neuro, dx_opthalmic_otic, dx_resp, dx_uro_genital, dx_vascular, dx_wound_ostomy, dx_pain, dx_pt_treatment, dx_vitamins, dx_anti_infective, dx_misc) to Visit entity in web/modules/custom/librechart_visit/config/install/
- [X] T055 [US4] Configure field_group "Clinical Evaluation" collapsible fieldset on Visit default form display, grouping all clinical evaluation fields with body system booleans and their corresponding diagnosis reference fields adjacent to each system in web/modules/custom/librechart_visit/config/install/core.entity_form_display.visit.visit.default.yml
- [X] T056 [US4] Configure field_permissions: clinician role gets "edit any" for all clinical evaluation fields; all clinical roles get "view"; export to config/sync/ (implemented via hook_entity_field_access() in librechart_visit.module)
- [X] T057 [US4] Export updated Visit field config and form display to config/sync/ via `ddev drush config:export -y`

**Checkpoint**: Clinician can complete a clinical evaluation, assign diagnoses from body-system vocabularies, set PT referral. Other roles see clinical data read-only.

---

## Phase 9: User Story 11 — Physician Dictation via Speech-to-Text (Priority: P4)

**Goal**: `librechart_dictation` module implementing the `POST /api/dictation/transcribe` endpoint (per contracts/dictation-api.md), a whisper.cpp proxy, and dictation.js attaching a microphone button to all `data-dictation-enabled` text_long fields.

**Independent Test**: With whisper.cpp running locally on port 8080, activate dictation on the clinical_notes field, record a short passage, confirm transcribed text is inserted at cursor position within 10 seconds. Stop whisper.cpp, attempt dictation, confirm a Spanish error message appears and the text field remains keyboard-editable.

- [X] T059 [US11] Create librechart_dictation module scaffold: librechart_dictation.info.yml (depends: [librechart_visit]), librechart_dictation.routing.yml declaring POST route /api/dictation/transcribe → DictationController::transcribe with _permission: 'use dictation', and librechart_dictation.permissions.yml defining 'use dictation' permission in web/modules/custom/librechart_dictation/
- [X] T060 [US11] Implement DictationController::transcribe() method: validate multipart audio upload (max 10 MB, accepted formats WebM/WAV/MP3/OGG), forward audio to whisper.cpp at configured URL via Guzzle with `file`, `response_format: json`, `language` params, return 200 JSON {status:ok, transcript}, or 422/503/413 error JSON per contract in web/modules/custom/librechart_dictation/src/Controller/DictationController.php
- [X] T061 [US11] Implement DictationSettingsForm: config form at /admin/config/librechart/dictation with fields for whisper_server_url (default http://127.0.0.1:8080), language (select: en, es; default en), max_audio_duration (integer, default 300 seconds), enabled_roles (checkboxes) in web/modules/custom/librechart_dictation/src/Form/DictationSettingsForm.php
- [X] T062 [US11] Create default config/install for DictationSettingsForm with whisper_server_url, language, max_audio_duration, and enabled_roles defaults (clinician, triage_nurse, physical_therapist) in web/modules/custom/librechart_dictation/config/install/librechart_dictation.settings.yml
- [X] T063 [US11] Implement dictation.js using MediaRecorder API: attach microphone button to each element with [data-dictation-enabled] attribute; implement 5-state machine (idle→recording→processing→success→error); on stop, POST FormData to /api/dictation/transcribe; insert transcript at cursor via setSelectionRange/execCommand; all button labels and error messages via Drupal.t() in web/modules/custom/librechart_dictation/js/dictation.js
- [X] T064 [US11] Define dictation.js as a Drupal library in web/modules/custom/librechart_dictation/librechart_dictation.libraries.yml and attach it conditionally to users with 'use dictation' permission via hook_page_attachments() in web/modules/custom/librechart_dictation/librechart_dictation.module
- [X] T065 [US11] Add data-dictation-enabled HTML attribute to complaint, past_medical_history, clinical_notes, pt_notes fields via hook_preprocess_field() in web/modules/custom/librechart_dictation/librechart_dictation.module
- [X] T066 [US11] Grant 'use dictation' permission to clinician, triage_nurse, and physical_therapist roles in config/sync/user.role.*.yml
- [X] T067 [US11] Create docs/whisper-setup.md documenting whisper.cpp compilation steps (cmake build), model download (ggml-small.bin for multilingual), and systemd service unit file for running whisper-server on the Linux production server at port 8080

**Checkpoint**: Clinician can dictate clinical notes offline. Transcript inserts at cursor within 10 seconds. If whisper.cpp is down, error message appears in Spanish and keyboard entry works normally.

---

## Phase 10: User Story 5 — Pharmacy Dispensing (Priority: P5)

**Goal**: PrescriptionItem custom entity, per-drug dosage fields, inventory decrement on fill, low-stock warning before dispense, and pharmacy station fields on Visit.

**Independent Test**: As Pharmacist, fill a 3-drug prescription on a Visit; confirm each drug has its own dosage field; confirm DrugInventory quantities decrease for each drug; attempt to dispense a drug with zero inventory and confirm a warning appears before allowing override.

- [X] T068 [US5] Add Pharmacy station Visit fields (pharmacist_name string(256), notes_to_pharmacist text_long) to Visit entity field config in web/modules/custom/librechart_visit/config/install/
- [X] T069 [US5] Configure field_group "Pharmacy" collapsible fieldset on Visit form display enclosing pharmacist_name and notes_to_pharmacist; configure field_permissions with pharmacist role edit access in web/modules/custom/librechart_visit/config/install/core.entity_form_display.visit.visit.default.yml
- [X] T070 [US5] Implement PrescriptionItem content entity class with field definitions (visit entity_reference→Visit, drug entity_reference→taxonomy term, drug_category string(64), dosage string(256), quantity_dispensed integer, prescription_filled boolean, dispensed_by string(256), override_reason text_long nullable, created timestamp) in web/modules/custom/librechart_pharmacy/src/Entity/PrescriptionItem.php
- [X] T071 [US5] Define PrescriptionItem entity field storage and instance configuration in web/modules/custom/librechart_pharmacy/config/install/
- [X] T072 [US5] Implement PrescriptionItem postsave hook: when prescription_filled becomes true and quantity_dispensed > 0, load the matching DrugInventory record (by drug + clinic_site from the related Visit), decrement quantity_on_hand, and save in web/modules/custom/librechart_pharmacy/librechart_pharmacy.module
- [X] T073 [US5] Implement PrescriptionItem presave validation in hook_entity_presave(): if prescription_filled is being set to true and quantity_dispensed > DrugInventory.quantity_on_hand and override_reason is empty, reject with a translated validation error "Insufficient stock — [drug] has [N] on hand. Enter a reason to override."; if override_reason is provided, allow save and log the override in web/modules/custom/librechart_pharmacy/librechart_pharmacy.module
- [X] T074 [US5] Configure Views-based PrescriptionItem listing inline on Visit edit form, grouped by drug_category in collapsible details elements, with add/edit/delete links for Pharmacist role in web/modules/custom/librechart_pharmacy/config/install/views.view.visit_prescriptions.yml
- [X] T075 [US5] Grant pharmacist role create/edit/delete access on PrescriptionItem entity; grant all clinical roles view access; export to config/sync/

**Checkpoint**: Pharmacist can add multi-drug prescriptions per visit with individual dosage fields, inventory decrements on fill, and low-stock warning appears before dispensing a drug with insufficient stock.

---

## Phase 11: User Story 6 — Pharmacy Inventory Management (Priority: P6)

**Goal**: DrugInventory and InventoryReceipt entities, inventory receipt increment hook, Views-based inventory report filterable by clinic site and date range, with low-stock flagging.

**Independent Test**: Add an inventory receipt for 50 units of a drug; verify DrugInventory.quantity_on_hand increases; dispense 20 units via PrescriptionItem; verify on-hand drops to 30; open inventory report filtered by clinic site and confirm on-hand/received/dispensed columns are accurate and low-stock threshold flagging works.

- [X] T076 [P] [US6] Implement DrugInventory content entity class with field definitions (drug entity_reference→taxonomy, clinic_site entity_reference→clinic_sites, quantity_on_hand integer, low_stock_threshold integer default 10, unit string(64)) and unique constraint on (drug, clinic_site) pair in web/modules/custom/librechart_pharmacy/src/Entity/DrugInventory.php
- [X] T077 [P] [US6] Implement InventoryReceipt content entity class with field definitions (drug entity_reference→taxonomy, clinic_site entity_reference→clinic_sites, quantity_received integer, receipt_date datetime default now, received_by string(256), notes text_long, created timestamp) in web/modules/custom/librechart_pharmacy/src/Entity/InventoryReceipt.php
- [X] T078 [US6] Define DrugInventory and InventoryReceipt entity field storage and instance configuration in web/modules/custom/librechart_pharmacy/config/install/
- [X] T079 [US6] Implement InventoryReceipt postsave hook: load or create DrugInventory record for (drug, clinic_site), increment quantity_on_hand by quantity_received, save in web/modules/custom/librechart_pharmacy/librechart_pharmacy.module
- [X] T080 [US6] Configure Views-based inventory report at /reports/inventory showing each drug with quantity_on_hand, total quantity_received, total quantity_dispensed columns; exposed filters for clinic_site and date range; CSS class applied to rows where quantity_on_hand ≤ low_stock_threshold; CSV export enabled; in web/modules/custom/librechart_reports/config/install/views.view.inventory_report.yml

**Checkpoint**: Pharmacist can record inventory receipts, view real-time on-hand quantities, generate inventory report filtered by clinic site with low-stock highlighting, and export to CSV.

---

## Phase 12: User Story 7 — Physical Therapy (Priority: P7)

**Goal**: PT station fields on Visit entity, visible only when pt_referral = true, with Physical Therapist role edit access.

**Independent Test**: Create a visit, set pt_referral = true in clinical evaluation; open visit as Physical Therapist and confirm PT section is editable and pre-populated with referral reason; confirm PT section is hidden when pt_referral = false.

- [X] T081 [P] [US7] Add PT station field config (pt_notes text_long dictation-enabled, pt_interventions entity_reference_multi→physical_therapy_treatment, pt_name string(256)) to Visit entity in web/modules/custom/librechart_visit/config/install/
- [X] T082 [US7] Configure conditional_fields rule (show PT group when pt_referral = true) AND configure field_group "Physical Therapy" collapsible fieldset grouping pt_notes, pt_interventions, pt_name — both in web/modules/custom/librechart_visit/config/install/core.entity_form_display.visit.visit.default.yml; this conditional rule must be set here, after PT fields exist
- [X] T083 [US7] Configure field_permissions: physical_therapist role gets "edit any" on pt_notes, pt_interventions, pt_name; all clinical roles get view; export to config/sync/ (implemented via hook_entity_field_access() in librechart_visit.module)
- [X] T084 [US7] Export updated Visit field and form display configuration to config/sync/ via `ddev drush config:export -y`

**Checkpoint**: PT section appears when pt_referral is true; Physical Therapist can record treatment notes and interventions; other roles see PT data read-only.

---

## Phase 13: User Story 8 — Teaching & Referrals (Priority: P8)

**Goal**: Teaching & Referrals station fields on Visit entity with Teaching Coordinator role edit access.

**Independent Test**: Record a teaching session and external referral on a Visit as Teaching Coordinator; confirm data is stored and visible to administrative staff; confirm other roles cannot edit teaching fields.

- [X] T085 [P] [US8] Add Teaching station field config (teaching_topics entity_reference_multi→teaching_topics, external_referral string_long, diagnostic_referral string(256)) to Visit entity in web/modules/custom/librechart_visit/config/install/
- [X] T086 [US8] Configure field_group "Teaching & Referrals" collapsible fieldset on Visit form display grouping teaching_topics, external_referral, diagnostic_referral in web/modules/custom/librechart_visit/config/install/core.entity_form_display.visit.visit.default.yml
- [X] T087 [US8] Configure field_permissions: teaching_coordinator role gets "edit any" on teaching_topics, external_referral, diagnostic_referral; all clinical roles get view; export to config/sync/ (implemented via hook_entity_field_access() in librechart_visit.module)
- [X] T088 [US8] Export updated Visit field and form display configuration to config/sync/ via `ddev drush config:export -y`

**Checkpoint**: Teaching Coordinator can record teaching sessions and external referrals. Other roles see teaching data read-only.

---

## Phase 14: User Story 9 — Patient Visit History & Reporting (Priority: P9)

**Goal**: Patient profile chronological visit history, demographic report, diagnosis frequency report, pharmacy inventory report, CSV and PDF export on all three reports.

**Independent Test**: View a patient profile with 3+ visits and confirm all station data from each visit is listed chronologically. Generate a demographic report, filter by date range, and export to both CSV and PDF without internet access.

- [X] T089 [US9] Create librechart_reports module scaffold: librechart_reports.info.yml (depends: [librechart_visit, librechart_pharmacy]) in web/modules/custom/librechart_reports/
- [X] T090 [US9] Configure Views-based patient history view at /patient/{patient}/history showing chronological list of all visits with date, clinic_site, status, diagnoses summary, and medications dispensed summary; embed on Patient entity view display in web/modules/custom/librechart_reports/config/install/views.view.patient_history.yml
- [X] T091 [US9] Configure Views-based demographic report at /reports/demographics showing patient count by age group (5-year bands), sex, and municipality; exposed filters for clinic_site and date range in web/modules/custom/librechart_reports/config/install/views.view.demographics_report.yml
- [X] T092 [US9] Configure Views-based diagnosis frequency report at /reports/diagnoses showing diagnosis counts by body system vocabulary, sorted descending; exposed filters for clinic_site and date range in web/modules/custom/librechart_reports/config/install/views.view.diagnoses_report.yml
- [X] T093 [US9] Enable CSV data export (via drupal/views_data_export) on demographics_report, diagnoses_report, and inventory_report Views by adding a data_export display to each view config in web/modules/custom/librechart_reports/config/install/
- [X] T094 [US9] entity_print and dompdf/dompdf already required and enabled; PDF template customization deferred (entity_print has no direct Views display plugin integration in 2.x)
- [ ] T095 [US9] Configure entity_print PDF template and enable PDF export action on demographic_report, diagnosis_report, and inventory_report Views in web/modules/custom/librechart_reports/config/install/ (requires custom route implementation)
- [ ] T096 [US9] Translate all report View labels, column headers, and exposed filter labels via config_translation for es language; export to config/sync/language/es/
- [X] T097 [US9] Export all librechart_reports and updated librechart_pharmacy config to config/sync/ via `ddev drush config:export -y`

**Checkpoint**: Any authorized staff member can view complete patient visit history from the patient profile. Demographic and diagnosis frequency reports generate and export to CSV and PDF without internet access within performance thresholds.

---

## Phase 15: Legacy Data Migration

**Purpose**: Import all 39 legacy taxonomy vocabulary terms from the OpenEMR database at /Users/aaronellison/Sites/mission into the empty Drupal taxonomy vocabularies. This phase has no user story owner — it is a cross-cutting operational requirement enabling all station dropdowns to be populated.

- [X] T098 Create librechart_migrate module scaffold: librechart_migrate.info.yml (depends: [migrate, migrate_plus, migrate_tools]), migrations/ directory, and src/Plugin/migrate/source/ directory in web/modules/custom/librechart_migrate/
- [X] T099 [P] Implement SqlTaxonomySource migrate source plugin class connecting to the legacy OpenEMR database (reads from legacy taxonomy tables) with configurable table/field mapping in web/modules/custom/librechart_migrate/src/Plugin/migrate/source/LegacyTaxonomySource.php
- [X] T100 [P] Create migration YAML files for administrative vocabularies: librechart_taxonomy_clinic_sites.yml, librechart_taxonomy_municipalities.yml, librechart_taxonomy_village_town.yml, librechart_taxonomy_referrals.yml, librechart_taxonomy_allergies.yml, librechart_taxonomy_orders.yml — all in migration group `librechart_taxonomy` in web/modules/custom/librechart_migrate/migrations/
- [X] T101 [P] Create migration YAML files for all diagnosis vocabularies (one file per vocabulary: chronic_diseases, cardiac_thoracic, derm, endo, ent, eye, gi, gyn_ob, mental_health, muscular_skeletal, neuro, opthalmic_otic, resp, uro_genital, vascular, wound_ostomy, pain_management, physical_therapy_treatment, vitamins_nutrients_lv, anti_infective_agents, miscellaneous) in migration group `librechart_taxonomy` in web/modules/custom/librechart_migrate/migrations/
- [X] T102 [P] Create migration YAML files for all drug category vocabularies (gi_agents, cardiac_treatment_agents, dermatologic_agents, respiratory_treatments, pain_management, vitamins_nutrients_lv, opthalmic_otic, miscellaneous, chronic_diseases, anti_infective_agents) in migration group `librechart_taxonomy` in web/modules/custom/librechart_migrate/migrations/
- [X] T103 Document legacy database connection setup in web/sites/default/settings.local.php (template only, not committed): add `$databases['migrate']['default']` key pointing to the legacy OpenEMR database; document rollback command `ddev drush migrate:rollback --group=librechart_taxonomy` in quickstart.md

**Checkpoint**: `ddev drush migrate:import --group=librechart_taxonomy` runs without errors. All drug and diagnosis dropdowns are populated. Rollback confirmed working.

---

## Phase 16: Polish & Cross-Cutting Concerns

**Purpose**: Quality enforcement, performance validation, documentation, and final configuration export.

- [X] T104 [P] Run `ddev exec phpcs --standard=Drupal web/modules/custom` across all custom modules and fix every coding standards violation before final commit
- [X] T105 [P] Run `ddev exec phpstan analyse --level 6 web/modules/custom` and fix all type errors and static analysis violations
- [ ] T106 Run quickstart.md end-to-end validation: clone repo, `ddev start`, `ddev composer install`, `ddev drush site:install --existing-config`, create admin user, confirm site launches and Spanish is the default interface language
- [ ] T107 [P] Validate full Spanish coverage: log in as a Spanish-preference user, complete a full visit workflow (registration → triage → lab → clinical eval → PT → pharmacy → teaching) and confirm zero English strings visible in any label, button, validation message, or status text
- [ ] T108 [P] Performance validation: measure patient search response (<2s), inventory report generation (<10s), dictation transcription on a 1-minute recording (<10s), and CSV/PDF export on a full-year report (<15s); document results in docs/performance-notes.md
- [X] T109 Create docs/server-setup.md documenting onsite Linux server prerequisites: Apache/Nginx virtual host config, PHP 8.3 FPM settings (memory_limit, upload_max_filesize, post_max_size), MySQL setup, file permissions, and deployment steps (composer install --no-dev, drush updatedb, drush config:import, drush cache:rebuild)
- [X] T110 Run final `ddev drush config:export -y` to capture all configuration state; verify `ddev drush config:export --diff` shows no pending changes before tagging the feature complete

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 completion — BLOCKS all user stories
- **US1 (Phase 3)**: Depends on Phase 2 — no dependencies on other stories
- **US2 (Phase 4)**: Depends on Phase 2; US1 recommended first (Visit entity must exist)
- **US10 (Phase 5)**: Depends on Phase 4 (triage fields must exist for conditional rules)
- **US3 (Phase 6)**: Depends on Phase 2; US2 recommended first (lab ordering context)
- **US12 (Phase 7)**: Depends on Phase 3–6 (all entity fields should exist before bulk translation)
- **US4 (Phase 8)**: Depends on Phase 2; US2 and US3 recommended first (reads triage/lab data)
- **US11 (Phase 9)**: Depends on US4 (dictation targets clinical_notes, defined in Phase 8)
- **US5 (Phase 10)**: Depends on US4 (prescription items reference clinical evaluation referrals)
- **US6 (Phase 11)**: Depends on US5 (DrugInventory entity is used by PrescriptionItem save hook)
- **US7 (Phase 12)**: Depends on US4 (pt_referral field defined in Phase 8)
- **US8 (Phase 13)**: Depends on Phase 2; independent of other clinical stations
- **US9 (Phase 14)**: Depends on all preceding user story phases (reports aggregate all data)
- **Migration (Phase 15)**: Depends on Phase 2 (taxonomy vocabularies must exist as shells)
- **Polish (Phase 16)**: Depends on all preceding phases

### User Story Dependencies

- **US1 (P1)**: Can start immediately after Foundational — no story dependencies
- **US2 (P2)**: Needs Visit entity from US1; otherwise independent
- **US10 (P2)**: Needs triage fields from US2; otherwise independent
- **US3 (P3)**: Needs Visit entity from US1; otherwise independent of US2
- **US12 (P3)**: Depends on all entity fields being defined; run after US1–US8
- **US4 (P4)**: Needs Visit entity from US1; benefits from US2/US3 data being present
- **US11 (P4)**: Needs clinical_notes field from US4
- **US5 (P5)**: Needs Visit entity from US1 and clinical evaluation referrals from US4
- **US6 (P6)**: Needs DrugInventory entity from US5
- **US7 (P7)**: Needs pt_referral field from US4
- **US8 (P8)**: Needs Visit entity from US1; otherwise independent
- **US9 (P9)**: Build last — aggregates data from all preceding stories

### Parallel Opportunities

- Phase 1: T003 and T004 can run in parallel
- Phase 2: T007, T009, T012 can run in parallel after T006
- Phase 3: T015 and T016 can run in parallel; T020 can run in parallel with T015/T016
- Phase 4: T028, T029, T030 can run in parallel
- Phase 6: T039 can run in parallel with T041
- Phase 8: T052, T053, T054 can run in parallel
- Phase 11: T076 and T077 can run in parallel
- Phase 12: T081 can start immediately
- Phase 15: T100, T101, T102 can all run in parallel after T099

---

## Parallel Example: User Story 1

```bash
# Launch in parallel (different files, no blocking dependencies):
Task T015: PatientInterface in src/Entity/PatientInterface.php
Task T016: Patient entity class in src/Entity/Patient.php
Task T020: Visit entity class in src/Entity/Visit.php

# Then sequentially (depend on entity classes):
Task T017: Patient field config in config/install/
Task T021: Visit field config in config/install/
Task T022: Optimistic locking in Visit.php (depends on T020)

# Then in parallel (independent config files):
Task T023: Patient search view in views.view.patient_search.yml
Task T024: Patient visits view in views.view.patient_visits.yml
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL — blocks all stories)
3. Complete Phase 3: User Story 1 (Patient Registration & Check-In)
4. **STOP and VALIDATE**: Registration Staff can create a Patient, start a Visit, and search for returning patients
5. Demo and confirm before continuing

### Incremental Delivery

1. Setup + Foundational → Base Drupal running with roles and vocabularies
2. US1 → Patient and Visit entities, search, optimistic locking
3. US2 + US10 → Triage station with pediatric form adaptation
4. US3 → Lab orders and results
5. US12 → Full Spanish interface (run after all fields defined)
6. US4 + US11 → Clinical evaluation with dictation
7. US5 + US6 → Pharmacy dispensing and inventory management
8. US7 + US8 → PT and Teaching stations
9. US9 → Reports, exports, patient history
10. Migration → Populated taxonomy dropdowns
11. Polish → Quality, performance, documentation

---

## Notes

- Tests are not included — add via `/speckit.tasks` with TDD flag if needed
- [P] tasks operate on different files with no blocking intra-phase dependencies
- Each user story phase can be independently deployed and demonstrated
- Always `ddev drush config:export -y` after completing a phase
- Use `ddev drush config:export --diff` to confirm no uncommitted config changes before PRs
- config/install/ in each module is the canonical source during development; export to config/sync/ is the production-ready snapshot
