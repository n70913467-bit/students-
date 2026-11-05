# Workflow Planning

## Product purpose
A single place to track projects, project status, recent activity, pending work, next actions, and approval blockers. The product is a project tracker, not a financial product.

## Out of scope
No payment collection, payment processing, UPI, cards, wallets, bank transfers, payment gateways, merchant onboarding, or payment APIs.

## Users and roles (target)
- **Admin:** create projects, assign members, change project status, manage users, and view all reports.
- **Project member:** view assigned projects, update activity, create/update tasks, and report blockers.
- **Viewer:** read-only access to permitted projects and reports.

The current browser prototype is single-user. Role-based access is a future backend feature.

## Main workflow
1. Admin creates a project with name, description, owner, status, target dates, and next action.
2. Admin assigns members and records whether an approval is required.
3. Project member adds tasks with priority, due date, and owner.
4. Member updates task status and records a dated activity note.
5. Dashboard recalculates pending/completed task counts and latest activity.
6. If a decision is needed, the project is flagged as waiting for approval and records the approver and request details.
7. Admin reviews project status, filters projects, and exports a report.
8. Project can move between Planned, Running, On Hold, and Stopped. Every status change should create an activity entry in the server-backed version.

## Key screens
- Overview: project counts, task counts, blockers, and overall progress.
- Projects: searchable/filterable list with last activity, next action, status, and approval indicator.
- Project detail: description, owner, members, tasks, and activity timeline.
- Roadmap: project build stages and milestone completion.
- Reports: CSV export first; PDF/report templates may be added later.

## Core business rules
- A project must have a name and a valid status.
- A task belongs to exactly one project.
- Completing/reopening a task updates its activity timestamp.
- “Last activity” should be derived from the newest activity record in the server-backed version, not typed independently.
- Approval-needed is separate from project status; a project can be Running while a decision is pending.
- Deleting a project should require confirmation and should not happen accidentally.
- Dates are stored in ISO format; display uses the user's locale.
- Search and filters must not modify stored records.

## Acceptance criteria
- Users can create and edit projects.
- Users can create tasks, complete/reopen them, and see counts update.
- Status and activity information remain consistent after refresh.
- Search and status filters work together.
- CSV export includes all visible project summary fields.
- Empty states and validation messages are understandable.
- No payment-related functionality or fields are present.
- Server-backed release enforces authentication and role permissions.

## Current prototype limitation
The initial `index.html` prototype stores data in the current browser's localStorage. It is not shared between browsers or users and has no authentication or server API yet.
