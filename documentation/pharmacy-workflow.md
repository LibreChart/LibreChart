# Pharmacy Workflow Diagrams

## 1. Pharmacy Dispensing Workflow

This diagram covers the end-to-end flow from clinical prescription through drug dispensing and inventory deduction.

```mermaid
flowchart TD
    A([Clinician completes<br/>clinical evaluation]) --> B[Clinician prescribes medications<br/>organized by drug category]
    B --> C([Pharmacist opens pharmacy<br/>section of the visit])
    C --> D[System displays prescribed<br/>drug categories and individual drugs<br/>with per-drug dosage fields]
    D --> E{All drugs<br/>reviewed?}
    E -- No --> F[Pharmacist selects<br/>next prescribed drug]
    F --> G[Pharmacist enters dosage<br/>for this drug]
    G --> H{Sufficient inventory<br/>on-hand?}

    H -- Yes --> I[Mark drug as dispensed]
    I --> J[System decrements<br/>inventory on-hand<br/>by dispensed quantity]
    J --> E

    H -- No --> K[System warns:<br/>insufficient inventory]
    K --> L{Pharmacist<br/>decision}
    L -- Override with<br/>documented reason --> I
    L -- Substitute or<br/>do not dispense --> M[Drug recorded<br/>as not dispensed<br/>with reason]
    M --> E

    E -- Yes --> N[Pharmacist saves<br/>dispensing record]
    N --> O[Prescription marked<br/>as filled]
    O --> P([Visit record updated<br/>with pharmacist name<br/>and dispensing details])
```

---

## 2. Pharmacy Inventory Management Workflow

This diagram covers stock intake, low-stock monitoring, and inventory reporting — independent of patient visits.

```mermaid
flowchart TD
    A([Drug shipment<br/>arrives]) --> B[Pharmacist records<br/>inventory receipt:<br/>drug · quantity · date]
    B --> C[System increases<br/>on-hand quantity<br/>for drug at clinic site]
    C --> D{On-hand quantity<br/>above low-stock<br/>threshold?}
    D -- Yes --> E([Stock level normal])
    D -- No --> F[System visually flags<br/>drug as low stock<br/>in inventory view]

    G([Pharmacist requests<br/>inventory report]) --> H[Pharmacist selects<br/>filters: clinic site<br/>and/or date range]
    H --> I[System generates report<br/>showing per drug:<br/>• Current on-hand qty<br/>• Total received<br/>• Total dispensed]
    I --> J[Low-stock drugs<br/>visually flagged<br/>in report]
    J --> K{Export<br/>needed?}
    K -- CSV --> L([Download CSV file])
    K -- PDF --> M([Download PDF file])
    K -- No --> N([Pharmacist reviews<br/>on screen])
```

---

## 3. Full Pharmacy Context: Position in the Clinic Visit Workflow

This diagram shows where the pharmacy station sits within the broader clinic visit workflow, and how it connects to the inventory system.

```mermaid
flowchart TD
    REG([Registration<br/>Check-In]) --> TRIAGE
    TRIAGE([Triage<br/>Vitals · Chief Complaint<br/>Medical History]) --> LAB
    LAB([Lab Orders<br/>& Results]) --> CLIN

    CLIN([Clinical Evaluation<br/>Diagnoses · Assessments<br/>Referrals & Orders]) --> PHARM
    CLIN --> PT
    CLIN --> TEACH

    PT([Physical Therapy<br/>Treatment Notes]) --> COMPLETE
    TEACH([Teaching &<br/>External Referrals]) --> COMPLETE

    PHARM([Pharmacy Dispensing<br/>Per-drug dosage · Prescription fill]) --> INV
    PHARM --> COMPLETE

    INV[(Drug Inventory<br/>per Clinic Site)]
    INV -- stock receipt increases on-hand --> INV
    PHARM -- dispense decreases on-hand --> INV
    INV -- low-stock flag --> PHARM

    COMPLETE([Visit Complete])
```

---

## Actors

| Actor | Pharmacy Role |
|-------|--------------|
| Clinician | Prescribes medications by drug category during clinical evaluation |
| Pharmacist | Reviews prescriptions, enters per-drug dosage, dispenses drugs, manages inventory, generates reports |
| System | Tracks on-hand inventory, warns on insufficient stock, decrements inventory on dispensing, flags low stock |

## Key Rules

- Each prescribed drug has its own individual dosage field (not a shared dosage field).
- Drugs are organized into collapsible category groups: anti-infective agents, GI agents, cardiac agents, dermatologic agents, respiratory agents, pain management, vitamins/nutrients, ophthalmic/otic, miscellaneous, chronic disease medications.
- Inventory is tracked per drug per clinic site, not globally.
- Dispensing a drug always decrements inventory; an override with documented reason is required to dispense when stock is insufficient.
- Inventory receipts (stock additions) and dispensing events are recorded separately, allowing cumulative totals in reports.
- Reports are filterable by clinic site and date range, and exportable to CSV or PDF.
