# Route Contracts: Main Menu Navigation Links

**Branch**: `002-main-menu-nav-links` | **Date**: 2026-04-07

These are the two URL routes this feature exposes. They form the contract between the Views configuration and the rest of the system (menu links, role-based redirects, future deep links from other views).

---

## Route: Patient Listing

| Property | Value |
|----------|-------|
| Path | `/patients` |
| HTTP Method | GET |
| Access | Authenticated users with `view patient content` permission |
| Menu link | Main navigation — "Patients" |
| Returns | HTML listing of patient records |
| Query parameters | `search` (name/cedula filter, exposed filter form) |

**Contract guarantees**:
- Route exists and returns HTTP 200 for users with permission.
- Route returns HTTP 403 for users without `view patient content` permission.
- Route is inaccessible (403 or redirect to login) for anonymous users.
- The main menu link to this route is visible if and only if the current user has access.

---

## Route: Visit Listing

| Property | Value |
|----------|-------|
| Path | `/visits` |
| HTTP Method | GET |
| Access | Authenticated users with `view visit content` permission |
| Menu link | Main navigation — "Visits" |
| Returns | HTML listing of visit records |
| Query parameters | `clinic_site` (site filter), `status` (status filter), both exposed |

**Contract guarantees**:
- Route exists and returns HTTP 200 for users with permission.
- Route returns HTTP 403 for users without `view visit content` permission.
- Route is inaccessible (403 or redirect to login) for anonymous users.
- The main menu link to this route is visible if and only if the current user has access.
