# Quickstart: Pharmacy Section Refinement

Build, enable, and verify the feature locally (DDEV). All assets are self-hosted (offline clinic
constraint) — no external CDNs. Commands assume repo root.

## 1. Dependencies

```bash
# Only new contrib dependency — embeds medication line items in the Visit form.
ddev composer require drupal/inline_entity_form
ddev drush en inline_entity_form -y

# autosave_form / conditional_fields / field_group / paragraphs are ALREADY enabled.
```

## 2. Apply schema + config

```bash
# Runs the librechart_visit / librechart_pharmacy update hooks:
#  - install new Visit base fields (medications, education_referrals, pos, pos_details)
#  - convert notes_to_pharmacist text_long -> string_long (copies existing values)
#  - remove pharmacist_name
#  - current_station allowed values + migrate rows 'teaching' -> 'education'
#  - seed allergies "None" term
#  - install prescription_item.fulfilled_by
ddev drush updatedb -y

# Import the new/changed config (vocabularies, paragraph type, form display, autosave settings):
ddev drush config:import -y      # or: config:import --partial --source=<module>/config/install
ddev drush cache:rebuild
```

## 3. Seed taxonomy content (admin-managed terms)

- **Pharmacist** vocabulary: add a term per pharmacist (names). For the auto-fill default to work,
  a term should match the logged-in pharmacist user's name.
- **Education** vocabulary: add a term per education class.
- **Allergies**: confirm a **"None"** term exists (the update hook seeds it).

## 4. Manual verification (maps to spec acceptance scenarios)

### Pharmacy — prescribing (US1)
1. Edit a visit → open **Pharmacy**. Type in the **Medication** field → ≤10 matches; low-stock rows
   yellow, out-of-stock rows red and **not selectable**.
2. Click **View inventory** → `/reports/inventory` opens in a new tab.
3. Set **Quantity**; click **Add another** → a second independent medication fieldset appears.

### Pharmacy — fulfillment + inventory (US2)
4. As a **Pharmacist**, check **Fulfilled** on a med (qty 5) → that drug's `quantity_on_hand` drops
   by 5. Uncheck → it returns to the starting value (toggle = 0 drift).
5. **Fulfilled by** defaults to your matching pharmacist term and is editable; options come from the
   Pharmacist vocabulary.
6. Try fulfilling a quantity greater than stock → **blocked**, checkbox stays unchecked, error states
   the remaining quantity.
7. As a **non-pharmacist**, confirm Fulfilled / Fulfilled-by are not editable.

### Allergies (US3)
8. New visit → **Allergies** is required and defaults to **None**; no pill shown for "None".
9. Add allergies → red pills appear stickied at the bottom and stay visible while scrolling; remove
   one → its pill disappears.

### Education station (US4) + POS (US7)
10. Confirm the former "Teaching" station now reads **Education** and still contains the existing
    Teaching & Referrals fields. Existing in-progress visits previously at `teaching` now show
    `education`.
11. Add one or more **Education** referrals, each with its own **Complete** checkbox.
12. In the Education group, check **POS** → a text area appears for the local-provider referral;
    uncheck → it hides.

### Pharmacy cleanup (US5) + report coloring (US6)
13. Pharmacy group has **no** "Pharmacist name" field; **Notes to pharmacist** is a plain textarea
    (no rich-text toolbar).
14. `/reports/inventory` shows low-stock rows yellow and out-of-stock rows red.

### Autosave (US8)
15. Start editing a visit, navigate away without saving, return → in-progress entries are restored.
    (Pay attention to the medication/education subforms; see research D11 risk note.)

## 5. Quality gates

```bash
ddev exec phpcs --standard=Drupal web/modules/custom/librechart_visit web/modules/custom/librechart_pharmacy
ddev exec phpstan analyse --level 6 web/modules/custom/librechart_visit web/modules/custom/librechart_pharmacy
ddev exec phpunit -c web/core/phpunit.xml.dist --filter Librechart web/modules/custom
ddev drush config:export --diff      # confirm config is captured and matches active
```

## 6. Rollback notes

- `notes_to_pharmacist` type change and `pharmacist_name` removal are destructive schema changes —
  back up the DB before `updatedb` in any environment with real data.
- The `teaching → education` data migration updates both the base and revision tables; verify visit
  counts per station before/after.
