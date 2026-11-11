# Data Model and Persistence

This document describes the **implemented SQLite model** used by `api.php`. The schema uses integer auto-increment IDs and SQLite types; it is not the earlier proposed UUID/PostgreSQL design.

## Tables and relationships

- `users`: name, unique email, password hash, account role (`admin`, `member`, `viewer`), active flag, creation time. Plaintext passwords are never stored.
- `projects`: name, description, status (`planned`, `running`, `on_hold`, `stopped`), owner, next action, created/updated timestamps.
- `project_members`: many-to-many user/project membership with project role (`manager`, `contributor`, `viewer`).
- `tasks`: project, title, description, status (`todo`, `in_progress`, `blocked`, `done`), priority, optional assignee and due date, timestamps.
- `activity_logs`: project, optional task and actor, event type, message, timestamp.
- `approval_requests`: project, requester, optional approver, title/details, status (`pending`, `approved`, `rejected`, `cancelled`), requested/decision timestamps.
- `login_attempts`: hashed email/IP attempt key and timestamp for basic failed-login throttling.

## Relationship summary

- One project has many tasks, activity entries and approval requests.
- Users join projects through `project_members`.
- Tasks and activity entries may reference a user.
- An approval belongs to a project and has a requester plus an optional approver.
- Foreign keys are enabled by the API connection; project deletion cascades to project-owned records.

## Indexes

The API creates indexes for project status/update time, membership lookup by user/project, tasks by project/status, activity by project/time, approvals by project/status, and login-attempt lookup by key/time.

## API routes implemented

- `GET /api/health`
- `POST /api/auth/register` (one-time first-admin setup)
- `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`
- `GET/POST /api/projects`, `GET/PATCH/DELETE /api/projects/:id`
- `GET/POST /api/projects/:id/tasks`, `PATCH/DELETE /api/tasks/:id`
- `POST /api/projects/:id/activities`
- `GET/POST /api/projects/:id/members`
- `GET /api/users` (admin only), `POST /api/users` (admin only)
- `GET/POST /api/projects/:id/approvals`, `PATCH /api/approvals/:id`

## Authentication and security controls

- Passwords are hashed with Argon2id when supported, otherwise PHP's `PASSWORD_DEFAULT`; login rehashes outdated password hashes.
- When `PROJECT_DASHBOARD_SETUP_KEY` is configured, first-admin registration requires that key; set a strong random value in the server environment before public exposure and never commit it.
- PHP stores session state server-side. The browser receives an HttpOnly, SameSite=Strict cookie, Secure when HTTPS is detected.
- Login regenerates the session ID. State-changing requests require a per-session CSRF token in `X-CSRF-Token`.
- Failed login attempts are counted by a SHA-256 hash of normalized email plus remote IP; the current threshold is 10 attempts per 15 minutes.
- API responses set no-store, nosniff, frame/referrer/permissions and Content-Security-Policy headers.
- Project membership is checked server-side. Only admins can create projects and assign members; admins and project managers can edit project details, delete tasks, and create approval requests; contributors can create/update tasks and add activity; viewers are read-only.

## Operational notes and known limits

- Default SQLite location is `storage/dashboard.sqlite`; production should set `PROJECT_DASHBOARD_DB` to a path outside the public web root. Apache rules must deny access to the storage path.
- The login throttle is a basic application-level control, not a replacement for edge/WAF throttling.
- No password-reset, email verification, MFA, account self-service, refresh-token, or JWT flow exists.
- Before public deployment, configure HTTPS, restrict file permissions, run independent security/accessibility review, and test backup/restore.
