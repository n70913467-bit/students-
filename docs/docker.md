# Docker deployment

The repository includes a PHP 8.3 + Apache image and a Compose file for a persistent SQLite volume.

## Requirements

- Docker Engine and Docker Compose plugin.
- A long, unique first-admin setup key (at least 32 random characters).
- HTTPS termination in front of the container before exposing it to public users.

## Start locally

From the repository root, set a one-time setup key in your shell and start the service:

```sh
export PROJECT_DASHBOARD_SETUP_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
docker compose up --build -d
```

Open `http://localhost:8080` for local development. Create the first administrator using the same setup key in the first-admin form. The SQLite database is stored in the named Docker volume `dashboard_data`, outside Apache's public document root, and persists across container restarts.

After the first admin is created, remove `PROJECT_DASHBOARD_SETUP_KEY` from the runtime environment and recreate/restart the service. Do not put the setup key in source control, screenshots, or chat messages.

## Public deployment

- Put the container behind a trusted HTTPS reverse proxy/load balancer.
- Preserve the `X-Forwarded-Proto: https` information only from the trusted proxy, or configure HTTPS directly at the web server. The application must see the request as HTTPS for secure cookies and HSTS.
- Keep the `dashboard_data` volume persistent and restrict access to the host.
- Configure host-level backups of the SQLite database using a SQLite-aware online backup procedure; test restoration.
- Configure monitoring, edge rate limiting, log retention, and patching for the host and container.
- Verify `GET /api/health`, authentication, CSRF rejection, role permissions, persistence after restart, CSV export, and direct denial of database/WAL paths.
- Never expose the setup key or database volume publicly.

The Compose file enables the Secure cookie flag. Plain HTTP is for local development only; a public deployment must use HTTPS. This container build does not provision a domain, TLS certificate, hosting account, backup service, or monitoring service and is not a production security certification.
