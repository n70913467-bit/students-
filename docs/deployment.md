# Deployment Checklist

This guide is intentionally provider-neutral. The repository does not include a live hosting account, domain, TLS certificate, or production database, so deployment must be completed in the target environment.

## 1. Server requirements

- PHP 8.1 or newer with PDO_SQLite enabled.
- Apache with `mod_rewrite` and `mod_headers` enabled, or a web server configured to route `/api/*` to `api.php` and apply the documented security headers to frontend responses.
- HTTPS enabled before users sign in.
- A dedicated writable database directory outside the public document root.
- A PHP-FPM/web-server account with write access only to the application and database paths it needs.

## 2. Prepare the environment

1. Copy the values from `deployment/environment.example` into the protected PHP-FPM/service environment.
2. Set `PROJECT_DASHBOARD_DB` to an absolute path outside the public web root.
3. Generate a long, unique `PROJECT_DASHBOARD_SETUP_KEY`; store it in the hosting provider's secret/environment manager, not in Git.
4. Create the database directory and make the PHP service account its owner. Restrict directory access to that account.
5. Keep `storage/` blocked from public HTTP access. Apache deployments use the root `.htaccess` rewrite rule and `storage/.htaccess` deny rule; other servers must configure an equivalent web-server deny rule. Verify direct requests for the database and SQLite WAL files return 403 or 404.

## 3. Run the deployment preflight

Before publishing, run the CLI preflight from the application checkout:

```sh
export PROJECT_DASHBOARD_DB="/var/lib/project-control/dashboard.sqlite"
export PROJECT_DASHBOARD_PUBLIC_ROOT="/var/www/project-control"
export PROJECT_DASHBOARD_COOKIE_SECURE="1"
php deployment/preflight.php
```

- Create the database parent directory first and grant write access to the PHP service account.
- Set `PROJECT_DASHBOARD_PUBLIC_ROOT` to the actual public document root when running the CLI check; if omitted, the script uses the web server document root or the repository root.
- Run the preflight as the same Unix account used by PHP-FPM/web server. The check verifies PHP 8.1+, PDO_SQLite, an absolute writable database path outside the public root, owner-only database-directory permissions, and secure session-cookie configuration.
- A setup key is optional after first-admin bootstrap. If supplied, it must contain at least 32 characters; the script never prints the secret.
- The preflight does not create a database, change server configuration, deploy the application, or certify production security.
- Run `bash tests/preflight-smoke.sh` to test both passing and failing configuration cases locally.

## 4. Publish and configure

- Point the web document root at the application directory, or configure a private app directory with only required public files exposed.
- Configure rewrite rules so `/api/health` and other `/api/*` routes reach `api.php`.
- Keep PHP error display disabled in production and send errors to protected server logs.
- Configure TLS and confirm the browser receives the session cookie with the Secure, HttpOnly and SameSite=Strict attributes.
- Configure server/edge rate limiting and monitoring in addition to the application's basic failed-login throttle.

## 5. First administrator bootstrap

1. While HTTPS and the setup key are active, open the dashboard.
2. Create the first admin using the setup key.
3. Confirm login, project creation and logout.
4. Remove `PROJECT_DASHBOARD_SETUP_KEY` from the runtime environment and restart PHP workers.
5. Verify that another first-admin registration is rejected.

## 6. Verify after deployment

- `GET /api/health` returns `ok: true`.
- Unauthenticated `GET /api/projects` returns HTTP 401.
- Login succeeds; the cookie is Secure, HttpOnly and SameSite=Strict.
- Writes without a valid CSRF token return HTTP 419.
- Viewer accounts cannot write; contributors can create/update tasks and add activity, while only admins/project managers can edit project details, delete tasks, or create approvals. Only admins create projects and assign members.
- Task due dates reject invalid calendar dates; CSV exports neutralize spreadsheet formula prefixes.
- A user cannot read a project unless they are an admin or a member.
- Database files and SQLite journal files cannot be downloaded over HTTP.
- Create a test project, task, activity and approval; refresh and confirm persistence.
- Export CSV and verify that it contains expected project summaries.

## 7. Backups, recovery and operations

- Back up the SQLite database using a SQLite-aware backup method while accounting for WAL mode; do not copy only the main database file while writes are active.
- Test restore into a separate environment before relying on backups.
- Set retention rules for activity logs and failed login attempts.
- Rotate the setup secret after initial bootstrap; rotate application/server secrets according to hosting policy.
- Review PHP/server logs and login throttling regularly.
- Run the CI workflow before each release.

## Not included in this repository

No hosting provider, domain, TLS certificate, production credentials, mail provider, monitoring destination or backup destination has been configured. The deployment guide is a checklist, not proof that the app is live or production-certified.
