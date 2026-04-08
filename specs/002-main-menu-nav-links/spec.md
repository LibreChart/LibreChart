# Feature Specification: Main Menu Navigation Links for Patients and Visits

**Feature Branch**: `002-main-menu-nav-links`
**Created**: 2026-04-07
**Status**: Draft
**Input**: User description: "001-emr-rebuild: There should be links in the main menu to go to the patient and visit listing views. These should be set up similarly to the 'pages' and 'cms' views."

## Clarifications

### Session 2026-04-07

- Q: Which staff roles should see the Patients and Visits links? → A: All authenticated staff roles see both links.
- Q: What is the default date scope for the Visits listing? → A: Today's visits by default; filter is clearable to view all historical records.
- Q: Should the menu links be normal menu items or local task tabs? → A: Normal menu items (top-level links in main navigation).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Navigate to Patient List from Main Menu (Priority: P1)

A staff member at any clinic station clicks a "Patients" link in the main navigation menu and is taken directly to the patient listing view, where they can search, filter, and select patients.

**Why this priority**: The patient list is the primary entry point for all clinic workflows. Staff need fast, consistent access to it from anywhere in the system.

**Independent Test**: Can be fully tested by verifying that an authenticated staff member can click the "Patients" link in the main menu and arrive at the patient listing view without additional navigation steps.

**Acceptance Scenarios**:

1. **Given** a logged-in staff member is on any page, **When** they click the "Patients" link in the main navigation menu, **Then** they are taken to the patient listing view.
2. **Given** the main menu is rendered, **When** a user views it, **Then** a "Patients" link is present and visible in the expected position.
3. **Given** any authenticated staff member, **When** they view the main menu, **Then** the "Patients" link is visible and accessible regardless of their station role.

---

### User Story 2 - Navigate to Visit List from Main Menu (Priority: P2)

A staff member clicks a "Visits" link in the main navigation menu and is taken directly to the visit listing view, which defaults to showing today's visits. The date filter is clearable to search across all historical records.

**Why this priority**: Visits are the core operational unit of the EMR. Direct menu access prevents staff from having to navigate through patient records to find a visit.

**Independent Test**: Can be fully tested by verifying that an authenticated staff member can click the "Visits" link in the main menu and arrive at the visit listing view without additional navigation steps.

**Acceptance Scenarios**:

1. **Given** a logged-in staff member is on any page, **When** they click the "Visits" link in the main navigation menu, **Then** they are taken to the visit listing view.
2. **Given** the main menu is rendered, **When** a user views it, **Then** a "Visits" link is present and visible in the expected position.
3. **Given** any authenticated staff member, **When** they view the main menu, **Then** the "Visits" link is visible and accessible regardless of their station role.

---

### Edge Cases

- What happens when the patient or visit listing view is unpublished or disabled? The menu link should return an appropriate access-denied or not-found response.
- How does the system handle an unauthenticated (anonymous) user? Both links must be inaccessible; anonymous users are redirected to the login page.
- What if the listing view returns zero records? The view renders with an empty state message; the menu link remains visible.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The main navigation menu MUST include a link to the patient listing view, labelled "Patients" (or equivalent translated label).
- **FR-002**: The main navigation menu MUST include a link to the visit listing view, labelled "Visits" (or equivalent translated label). The visit listing MUST default to filtering records by the current date; the date filter MUST be clearable by the user to show all historical visits.
- **FR-003**: Each menu link MUST be configured as a normal menu item (`type: normal`) in `system.menu.main` — not a local task tab. Links appear as top-level navigation entries visible from every page.
- **FR-004**: Both menu links MUST be visible and accessible to all authenticated staff roles. Anonymous (unauthenticated) users MUST NOT see or be able to access either link.
- **FR-005**: The menu links MUST be managed via Drupal configuration (config/sync or view menu settings), not hardcoded or manually entered through the UI alone.
- **FR-006**: The menu links MUST be ordered logically within the main menu, placed near other primary content navigation items.

### Key Entities

- **Patient listing view**: A Drupal view that lists patient records with search and filter capabilities; the target of the "Patients" menu link.
- **Visit listing view**: A Drupal view that lists visit records with search and filter capabilities; the target of the "Visits" menu link.
- **Main menu**: The primary site navigation menu (`system.menu.main`) rendered site-wide for authenticated users.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Staff members can reach the patient listing view in one click from any page via the main menu.
- **SC-002**: Staff members can reach today's visit listing in one click from any page via the main menu; clearing the date filter shows all historical visits without reloading the page.
- **SC-003**: Both menu links appear correctly after a fresh configuration import with no manual UI steps required.
- **SC-004**: Users without appropriate permissions do not see or cannot access either link, with zero false-positive access grants.

## Assumptions

- The patient and visit listing views already exist or will be created as part of feature 001-emr-rebuild before this feature is completed.
- The "pages" and "cms" views referenced by the user are `views.view.canvas_pages` and `views.view.content`, both of which use Drupal view menu link settings to place links in their respective menus; this feature follows the same pattern for the main menu.
- Menu link labels are authored in English. Spanish translations are applied at runtime via Drupal's translation layer.
- No custom theming or styling beyond what the existing menu block already applies is required.
