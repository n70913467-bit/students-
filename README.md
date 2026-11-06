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
Open `index.html` in a modern browser. The prototype stores its demo data in browser localStorage. It is not yet connected to the API.

## Backend API (development starter)
- Requires PHP 8.1+ and the PDO_SQLite extension.
- `api.php` creates the SQLite tables on first request.
- Set `PROJECT_DASHBOARD_DB` to a database path outside the public web root where possible.
- Configure the web server so `api.php` receives API paths such as `/api/health`, or adjust the routing base for your hosting setup.
- The first admin is created once using `POST /api/auth/register` with JSON `{ "name": "...", "email": "...", "password": "at-least-12-characters" }`. Registration closes after the first account exists.
- Login with `POST /api/auth/login`; send the returned `csrf_token` as `X-CSRF-Token` on state-changing requests. Keep the session cookie in the same browser.
- `GET /api/health` checks API/database initialization. Authenticated routes include `/api/projects`, project detail, tasks, members, users, and approvals.
- Project-level viewer memberships are read-only; project write permissions are checked by the API.
- Run `bash tests/api-smoke.sh` after installing PHP with PDO_SQLite to exercise health, first-admin registration, session lookup, project creation/read, and task creation.
- GitHub Actions runs PHP syntax validation and the API smoke test on pushes and pull requests.
- `schema.sql` documents the relational model. The PHP API currently initializes the same core tables itself.

## Important security/deployment notes
This is an initial backend implementation, not production-ready. Before public deployment: use HTTPS, configure correct URL rewriting, keep the SQLite database outside the public directory, set restrictive file permissions, add rate limiting and audit/backup procedures, review authorization rules, and run functional/security tests. Do not commit real credentials or a live database file.

## API client
- `api-client.js` provides same-origin browser methods for authentication, projects, tasks, project members, and approvals.
- The current `index.html` still runs as a localStorage prototype; it has not yet been migrated to the API client.
- Run `npm run test:client` with Node.js 18+ to run the API client unit tests.

## Documentation
- [Workflow and acceptance criteria](docs/workflow.md)
- [Proposed database model and API outline](docs/data-model.md)

## Development status
- [x] Requirement planning
- [x] Workflow planning
- [x] Initial UI/UX design
- [x] Proposed relational data model
- [x] Initial PHP API and SQLite persistence layer (not yet fully tested)
- [ ] Connect frontend to API and authentication
- [ ] Complete API integration
- [ ] Functional, security, and accessibility testing
- [ ] Deployment

## Data note
The initial sample projects are illustrative records based on project discussions, not live integrations or verified activity logs. The dashboard roadmap percentage is a development checklist estimate, not a production-readiness certification.
