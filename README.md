# Project Control Dashboard

A responsive dashboard for tracking projects, tasks, activity history, approvals, and development progress.

## Scope
- Track projects by Running, On Hold, Stopped, and Planned status.
- Record latest activity, next action, and approval/permission requirements.
- Add and edit projects; add tasks, mark them complete/reopen, and remove tasks.
- View an activity history timeline for the selected project.
- Search and filter projects; export project summaries as CSV.
- Track the eight-stage development roadmap.

## Explicit exclusions
This project does **not** process, collect, or manage payments. No payment gateway, UPI, card, wallet, banking, merchant, or payment API integration is in scope.

## Frontend prototype
Run the authenticated app through PHP (do not open the HTML file directly): `php -S 127.0.0.1:8000 router.php`, then visit `http://127.0.0.1:8000`. The router serves the frontend and same-origin `/api/*` endpoints. The UI now uses the PHP API and SQLite database; the first admin account is created from the setup form.

## Backend API (development starter)
- Requires PHP 8.1+ and the PDO_SQLite extension.
- `api.php` creates the SQLite tables on first request.
- Use `deployment/environment.example` as a template: set `PROJECT_DASHBOARD_DB` to a path outside the public web root and set a long random `PROJECT_DASHBOARD_SETUP_KEY` before the first admin is created. Set `PROJECT_DASHBOARD_COOKIE_SECURE=1` when TLS terminates at a trusted reverse proxy. The PHP API creates the default storage directory with restrictive permissions and attempts to restrict the database file; the deployment account must still own and protect the database directory.
- For local development, use `php -S 127.0.0.1:8000 router.php`. Apache deployments can use the included `.htaccess`; configure equivalent rewrites for other servers.
- The first admin is created once using `POST /api/auth/register` with name, email, password (12+ characters), and `setup_key`. `PROJECT_DASHBOARD_SETUP_KEY` is mandatory: if it is missing or empty, registration fails with HTTP 503; if the supplied key is wrong, registration fails with HTTP 403. Set a long random setup key in the protected server environment before first-admin setup; never place it in source control. Remove the setup-key environment value after creating the first admin. Registration closes after the first account exists.
- Login with `POST /api/auth/login`; passwords are hashed with Argon2id when available (otherwise PHP's default password algorithm). The server uses an HttpOnly, SameSite=Strict session cookie and returns a CSRF token that the frontend keeps in memory and sends as `X-CSRF-Token` for state-changing requests. Failed login attempts are throttled per email/IP pair.
- `GET /api/health` checks API/database initialization. Authenticated routes include `/api/projects`, project detail, tasks, activity logs, members, users, and approvals. Admins can create users, list users, and assign project roles; project managers can view project members and use the membership API, while the current UI keeps user creation and assignment admin-only.
- Project-level viewer memberships are read-only; project write permissions are checked by the API. The API also supports user creation, project member assignment, activity notes, approval listing/decisions, and project/task deletion.
- Run `bash tests/api-smoke.sh` after installing PHP with PDO_SQLite to exercise frontend routing, private storage blocking, first-admin registration, session lookup, project/task/activity CRUD, CSRF rejection, user creation, project memberships, approvals, logout, login throttling, and viewer restrictions.
- GitHub Actions runs PHP syntax validation and the API smoke test on pushes and pull requests.
- `schema.sql` documents the relational model. The PHP API initializes the same core tables itself, including the login-attempt throttle table.

## Important security/deployment notes
This is an initial backend implementation, not production-ready. Before public deployment: use HTTPS, configure correct URL rewriting, keep the SQLite database outside the public directory, set restrictive file permissions, review login throttling limits, add monitoring and backup/restore procedures, review authorization rules, and run independent functional/security/accessibility tests. The current login throttle is a basic per-email/IP control, not a substitute for an edge/WAF rate limiter. Do not commit real credentials or a live database file.

## API client
- `app.js` connects the login/register screen and dashboard CRUD actions to the same-origin API client.
- `api-client.js` provides same-origin methods for authentication, projects, tasks, activity logs, users, project members, and approvals.
- Run `npm test` with Node.js 18+ to run API client unit tests and frontend DOM/safe-rendering contract tests.

## Documentation
- [Workflow and acceptance criteria](docs/workflow.md)
- [Secure deployment checklist](docs/deployment.md)
- [Implemented SQLite data model and API](docs/data-model.md)

## Development status
- [x] Requirement planning
- [x] Workflow planning
- [x] Initial UI/UX design
- [x] Implemented SQLite relational data model
- [x] Initial PHP API and SQLite persistence layer
- [x] Connect frontend to API and authentication
- [x] Core project, task, activity, member, and approval API integration
- [x] Automated API, authentication, client, schema, and frontend contract tests
- [ ] Independent security and accessibility review
- [ ] Production deployment and live-environment verification

## Data note
The initial sample projects are illustrative records based on project discussions, not live integrations or verified activity logs. The dashboard roadmap percentage is a development checklist estimate, not a production-readiness certification.
