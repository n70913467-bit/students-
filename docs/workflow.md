# Workflow Planning

## Product purpose
A single place to track projects, project status, recent activity, next actions, tasks, approvals and development progress. This is a project tracker, not a financial product.

## Out of scope
No payment collection, payment processing, UPI, cards, wallets, bank transfers, payment gateways, merchant onboarding or payment APIs.

## Users and roles
- **Admin:** create projects and users, see all projects, assign project members, and decide approvals.
- **Project manager:** manage an assigned project, view its member list, update projects/tasks/activity and manage approval requests for that project.
- **Contributor:** view assigned projects, create/update tasks and add activity notes.
- **Viewer:** read-only access to assigned projects.

Account roles are `admin`, `member`, `viewer`; project membership roles are `manager`, `contributor`, `viewer`.

## Main workflow
1. The first admin creates the initial account once. Public registration closes after that.
2. Users sign in using email/password; logout destroys the server-side session.
3. Admin creates users and projects, then assigns members with project-level roles.
4. A project member creates tasks with priority and optional due date.
5. Authorized members update task status and record dated activity notes.
6. An authorized member creates an approval request and can assign an active project member/admin as approver.
7. Admin or the assigned approver reviews and approves/rejects the request, subject to project write access.
8. Dashboard refreshes project summaries, task counts, activity history, approvals and memberships from the API.
9. Admin can export project summaries as CSV.

## Key screens
- Login and first-admin setup.
- Overview: project counts, task counts and development roadmap.
- Projects: searchable/filterable list with status, activity, next action and approval indicator.
- Project detail: tasks, activity timeline, approval requests and member list.
- Team management: admin user creation and project membership assignment.
- Reports: CSV export.

## Core business rules
- A project must have a name and a valid status.
- A task belongs to exactly one project.
- Only active users can be assigned to a project.
- Viewer memberships cannot make changes.
- Approval decisions are limited to admins or the assigned approver, with project write access.
- Deleting a project requires confirmation in the UI and cascades to project-owned records.
- Dates are stored in ISO format; display uses the browser locale.
- Search and filters must not modify stored records.
- Protected API routes enforce session authentication, CSRF checks on writes and server-side permissions.

## Acceptance criteria
- First-admin registration closes after initial account creation.
- Users can log in and log out; protected routes reject unauthenticated requests.
- Passwords are hashed; session cookies are HttpOnly and SameSite; writes require CSRF tokens.
- Admin can create users, projects and memberships.
- Project access is scoped by membership; viewers cannot write.
- Users can create/update tasks, add activity, and review approval requests according to their roles.
- State persists in SQLite after refresh.
- Search, status filters and CSV export work.
- Empty states and validation messages are understandable.
- No payment-related functionality or fields are present.
- CI runs PHP syntax, API smoke, frontend syntax and API-client tests.

## Current limitations and deployment readiness
This is an initial PHP/SQLite implementation, not production-ready. Password reset, email verification, MFA, user self-service, account deactivation UI, pagination, full audit review, backup/restore drills and independent security/accessibility testing remain future work. Deploy only with HTTPS, private database storage, restrictive file permissions, monitoring and edge-level rate limiting.
