# Contract: Station Transitions

This contract enumerates allowed transitions, the role permitted to perform each, and the resulting state change. It is the source of truth for the implementation of `StationWorkflow` (next-station + picker validation) and the test suite.

## Stations (ordered)

| # | ID | Label | Owning role |
|---|------|-------|------------|
| 1 | `registration` | Registration | `registration_staff` |
| 2 | `triage` | Triage | `triage_nurse` |
| 3 | `lab` | Lab Orders & Results | `lab_technician` |
| 4 | `clinical` | Clinical Evaluation | `clinician` |
| 5 | `pt` | Physical Therapy | `physical_therapist` |
| 6 | `pharmacy` | Pharmacy Dispensing | `pharmacist` |
| 7 | `teaching` | Teaching & Referrals | `teaching_coordinator` |

In addition to the 7 stations above, the `current_station` field accepts one **non-station sentinel value**:

| ID | Label | Owning role | Notes |
|----|-------|------------|-------|
| `complete` | Complete | n/a (admin-only via reopen control) | Set by the terminal transition (Teaching → complete) atomically with `status = 'complete'`. Never offered by the picker. Cleared (replaced by a chosen workflow station) by the admin reopen control. |

## Transition Mechanisms

The visit form exposes two ways to transition a visit. Both write to the same `current_station` field; they differ in how the target is selected.

### Mechanism A — Linear shortcut ("Send to next station")

One-click button. Submits the canonical next station per the table below. Honors the PT-skip rule when `pt_referral = false`.

| From | Condition | To | Side-effects |
|------|-----------|------|--------------|
| `registration` | always | `triage` | none |
| `triage` | always | `lab` | none |
| `lab` | always | `clinical` | none |
| `clinical` | `pt_referral = true` | `pt` | none |
| `clinical` | `pt_referral = false` | `pharmacy` | none |
| `pt` | always | `pharmacy` | none |
| `pharmacy` | always | `teaching` | none |
| `teaching` | always | `complete` (terminal sentinel) | atomic write: `status = complete` AND `current_station = complete` |

### Mechanism B — Station picker ("Send to specific station…")

Dropdown / picker UI. Submits whichever station the user selects. **Any-to-any** within the seven workflow stations is permitted, including backward routing and multi-station skips. The `complete` sentinel is never in the option list.

| From | To | Allowed? | Side-effects |
|------|------|---------|--------------|
| any workflow station | any **other** workflow station | ✓ | none |
| any workflow station | the **same** station | ✗ (excluded from picker; programmatic submit is a no-op) | — |
| any workflow station | `complete` (sentinel) | ✗ (not a station; not in picker; submit handler rejects) | — |
| `complete` (post-terminal) | any station | ✗ (picker not rendered on completed visits; admin must use reopen control) | — |

### Mechanism C — Admin reopen control (FR-021)

Rendered in place of the linear shortcut + picker when `current_station = 'complete'` AND `status = 'complete'`. Visible only to users with `administer visit entities`. UI: a target-station select with the 7 workflow stations + a "Reopen" submit button.

| From | To | Allowed? | Side-effects |
|------|------|---------|--------------|
| `complete` (post-terminal) | any one of the 7 workflow stations | ✓ | atomic write: `status = in_progress` AND `current_station = {target}` |
| `complete` | `complete` | ✗ (not in target list) | — |
| `complete` | any | non-admin user | ✗ (reopen control not rendered; access check rejects programmatic submits) | — |

The picker **does not** apply the PT skip rule. If the user chooses `pt`, the visit goes to `pt`, even when `pt_referral = false`. If the user chooses `pharmacy` from `clinical` while `pt_referral = true`, the visit goes to `pharmacy` and PT is bypassed.

The picker **does not** flip the `status` field. Visits become `complete` only via the linear shortcut's terminal transition (which writes BOTH `status = 'complete'` AND `current_station = 'complete'` atomically). The admin reopen control is the only path back out of the `complete` sentinel.

## Allowed Picker Transitions Matrix

Rows are the visit's current station; columns are the user's selected target. ✓ cells are allowed; ✗ cells are blocked (current station excluded from its own row).

|             | → registration | → triage | → lab | → clinical | → pt | → pharmacy | → teaching |
|-------------|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| registration | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| triage       | ✓ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| lab          | ✓ | ✓ | ✗ | ✓ | ✓ | ✓ | ✓ |
| clinical     | ✓ | ✓ | ✓ | ✗ | ✓ | ✓ | ✓ |
| pt           | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | ✓ |
| pharmacy     | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ |
| teaching     | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |

42 allowed picker transitions total (7 × 7 − 7 diagonal exclusions).

## Disallowed Operations (UI level)

- **Same-station selection** — the current station is excluded from the picker's options. Programmatic same-station submission is a no-op: no revision recorded, no error raised.
- **Transitioning a `status = complete` visit** — both the linear button and picker are hidden; an admin must reopen the visit (`administer visit entities` permission) before any transition.
- **Non-owner transition (either mechanism)** — neither the linear button nor the picker is rendered for non-owning users; a programmatic save attempting to change the station from a non-owning user is rejected in the submit handler with a form error + `warning` watchdog entry.
- **Non-station picker target** (e.g., `complete`, empty string, arbitrary value) — the picker only offers the seven station ids; the submit handler validates the target against the canonical list and rejects anything else.

## Permission Model

| Operation | Required permission(s) |
|-----------|------------------------|
| View visit form (read station strip) | `view visit entities` (existing) |
| Transition (linear OR picker) out of station X | role-owns(X) OR `administer visit entities` |
| Reopen completed visit (Mechanism C) | `administer visit entities` |
| View `/floor` page | `view visit entities` (existing) |
| Edit `current_station` directly via admin field UI | `administer visit entities` (existing) |

No new permissions introduced by this feature. Flexibility is in the destination, not in who may initiate. The reopen control is the only operation that targets the `complete` sentinel state.

## Revision Log Contract

Every transition — linear or picker — creates a Visit revision with:

```
revision_log_message: "Transitioned from {prior_label} to {new_label} by {acting_username}."
revision_user: <acting user uid>
revision_timestamp: <save time>
```

The terminal transition (Teaching → complete via the linear shortcut) reads:

```
revision_log_message: "Marked visit complete at Teaching & Referrals by {acting_username}."
```

The admin reopen control reads:

```
revision_log_message: "Reopened visit at {target_label} by {acting_username}."
```

Picker transitions never produce the "Marked visit complete" or "Reopened visit" messages — those are reserved for the linear terminal transition and the reopen control respectively.

## Floor View Contract

URL: `/floor`
Permission: `view visit entities`

HTML page with a horizontal row of 7 station columns (or wraps responsively). Each column header shows the station label and a count of in-progress visits at that station. Each visit row in a column shows:

```
{patient_last_name}, {patient_first_name} — {arrival_time}
```

…and is linked to `/visit/{vid}/edit`. Sort within a column: oldest-first (longest-waiting at the top).

Empty columns render with an `(empty)` placeholder so the column structure stays visible.

Excluded: any visit with `status = complete`.

Cache tags: `visit_list`, `patient_list`. Page re-renders when any visit saves — regardless of whether the visit arrived at its station via the linear shortcut or the picker.

## Implementation Notes

- The picker's allowed-target list is generated server-side from `StationWorkflow::stations()` minus the visit's current station. The list is not configurable per role — every station owner sees the same six options for a given visit.
- `StationWorkflow::nextStation()` remains the single source of truth for the **linear** transition. A separate method `StationWorkflow::canTransitionTo(Visit, string $target, AccountInterface $user): bool` encapsulates the picker's validation logic (target validity + owner-role check + status check).
- The submit handler validates the picker's submitted target against `StationWorkflow::stations()` before saving. Defense-in-depth: the HTML form only offers valid choices, but a tampered request must still be rejected.
