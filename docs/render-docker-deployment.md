# Deploy SKILLSYNC to Render with Docker

Render should build the repository's `Dockerfile` as a Docker web service. The
image contains PHP 8.3 CLI and PHP-FPM, Nginx, a separate Python virtual
environment, the pinned NLP packages, and the checked-in MiniLM and NLTK
assets. NLP execution remains local and offline.

## Render service settings

- Runtime: Docker
- Health check path: `/up`
- Persistent disk mount path: `/var/data`
- Port: use Render's `PORT` environment variable; Nginx defaults to `10000`
  only when `PORT` is absent (for local container runs).

The entrypoint stores the complete Laravel `storage` directory on the mounted
disk, including uploaded proposals, public profile photos, logs, sessions,
cache files, and compiled views. Do not mount over `/var/www/html/storage`
directly.

## Environment

Set these in the Render dashboard; do not commit a `.env` file or secrets:

```text
APP_ENV=production
APP_DEBUG=false
APP_KEY=<generated Laravel application key>
APP_URL=https://<your-render-hostname>
DB_CONNECTION=mysql
DB_HOST=<your MySQL host>
DB_PORT=3306
DB_DATABASE=<your database name>
DB_USERNAME=<your database user>
DB_PASSWORD=<your database password>
LOCAL_NLP_ENABLED=true
LOCAL_NLP_PYTHON=/opt/venv/bin/python
```

Generate `APP_KEY` with `php artisan key:generate --show` in a trusted local
environment and store the result only as a Render secret. Keep the existing
MySQL connection configuration; the database itself must be reachable from the
Render service.

## First deployment

After the service has the MySQL environment and is running, run
`php artisan migrate --force` using the Render shell. Migrations are not run
automatically on container startup. Confirm the `/up` health check succeeds,
then exercise a proposal upload and local analysis to verify the mounted disk
and offline model in the deployed container.

The local Python environment at `nlp/.venv` is excluded from the Docker build.
The model and NLTK data directories are intentionally included. The build reads
the existing pinned `nlp/requirements-server.txt`; it does not download NLP
models or use external NLP APIs at runtime.
