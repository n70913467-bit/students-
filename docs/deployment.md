# Deployment Checklist

This guide is intentionally provider-neutral. The repository does not include a live hosting account, domain, TLS certificate, or production database, so deployment must be completed in the target environment.

## 1. Server requirements

- PHP 8.1 or newer with PDO_SQLite enabled.
- Apache with rewrite support, or a web server configured to route `/api/*` to `api.php`.
- HTTPS enabled before users sign in.
- A dedicated writable database directory outside the public document root.
- A PHP-FPM/web-server account with write access only to the application and database paths it needs.

## 2. Prepare the environment

1. Copy the values from `deployment/environment.example` into the protected PHP-FPM/service environment.
2. Set `PROJECT_DASHBOARD_DB` to an absolute path outside the public web root.
3. Generate a long, unique `PROJECT_DASHBOARD_SETUP_KEY`; store it in the hosting provider's secret/environment manager, not in Git.
4. Create the database directory and make the PHP service account its owner. Restrict directory access to that account.
5. Keep `storage/` blocked from public HTTP access. Apache deployments can use the root and storage `.htaccess` files; other servers need an equivalent deny rule.

## 3. Publish and configure

- Point the web document root at the application directory, or configure a private app directory with only required public files exposed.
- Configure rewrite rules so `/api/health` and other `/api/*` routes reach `api.php`.
- Keep PHP error display disabled in production and send errors to protected server logs.
- Configure TLS and confirm the browser receives the session cookie with the Secure, HttpOnly and SameSite=Strict attributes.
- Configure server/edge rate limiting and monitoring in addition to the application's basic failed-login throttle.

## 4. First administrator bootstrap

1. While HTTPS and the setup key are active, open the dashboard.
2. Create the first admin using the setup key.
3. Confirm login, project creation and logout.
4. Remove `PROJECT_DASHBOARD_SETUP_KEY` from the runtime environment and restart PHP workers.
5. Verify that another first-admin registration is rejected.

## 5. Verify after deployment

- `GET /api/health` returns `ok: true`.
- Unauthenticated `GET /api/projects` returns HTTP 401.
- Login succeeds; the cookie is Secure, HttpOnly and SameSite=Strict.
- Writes without a valid CSRF token return HTTP 419.
- Viewer accounts cannot write.
- A user cannot read a project unless they are an admin or a member.
- Database files and SQLite journal files cannot be downloaded over HTTP.
- Create a test project, task, activity and approval; refresh and confirm persistence.
- Export CSV and verify that it contains expected project summaries.

## 6. Backups, recovery and operations

- Back up the SQLite database using a SQLite-aware backup method while accounting for WAL mode; do not copy only the main database file while writes are active.
- Test restore into a separate environment before relying on backups.
- Set retention rules for activity logs and failed login attempts.
- Rotate the setup secret after initial bootstrap; rotate application/server secrets according to hosting policy.
- Review PHP/server logs and login throttling regularly.
- Run the CI workflow before each release.

## Not included in this repository

No hosting provider, domain, TLS certificate, production credentials, mail provider, monitoring destination or backup destination has been configured. The deployment guide is a checklist, not proof that the app is live or production-certified.
