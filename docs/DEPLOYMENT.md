# Deployment

Production runs on AWS Lightsail at https://13-50-9-19.sslip.io. The server configuration below is not stored in the repository; only `scripts/deploy.sh` is.

## Server

- **OS:** Ubuntu 24.04, OS-only image (not the Bitnami LAMP blueprint), so every component is installed and configured explicitly.
- **Resources:** 1 GB RAM plus 1 GB swap. MySQL's `performance_schema` is off to save memory.
- **MySQL 8.0** listens on 127.0.0.1 only and the port is closed in the Lightsail firewall. The application uses a dedicated user with SELECT, INSERT, UPDATE and DELETE on its own database only. Imports run as root through `sudo` (auth_socket).
- **Apache:** DocumentRoot is `public/`, `FallbackResource /index.php` sends only requests that do not match a file in `public/` (for example `/api/search`) to the front controller, while `index.html` and the assets are served directly by Apache, `-Indexes`, `AllowOverride None`, `ServerTokens Prod`.
- **HTTPS:** Let's Encrypt via Certbot on an sslip.io hostname, with automatic renewal.
- **Secrets:** `.env` has mode 640, owner `ubuntu`, group `www-data`. There is no Anthropic key on the server: enrichment is a one-off run done locally.

The virtual host in production is below. Certbot generated the HTTPS (443) version from it and added the HTTP to HTTPS redirect.

```apache
<VirtualHost *:80>
    ServerName 13-50-9-19.sslip.io
    DocumentRoot /var/www/catalog-search/public

    <Directory /var/www/catalog-search/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.html
        FallbackResource /index.php
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/catalog-search-error.log
    CustomLog ${APACHE_LOG_DIR}/catalog-search-access.log combined
</VirtualHost>
```

## Database

The database was shipped with `mysqldump --hex-blob` and imported on the server, instead of running `scripts/ingest.php` there. No embeddings are recomputed, so there is no Voyage cost, and production holds exactly the data that was evaluated. `--hex-blob` keeps the binary embedding column intact.

`VECTOR_INDEX_VERSION` in `.env` is optional (default `1`). If the data is re-ingested, bump it (or reload Apache) so APCu does not serve the old vectors. The APCu entry also expires after one hour (`src/Services/VectorIndex.php`), so about once an hour one request reloads the vectors from MySQL and is slower.

## Deploying: `scripts/deploy.sh`

Run on the server as user `ubuntu`:

```bash
scripts/deploy.sh
```

`DEPLOY_URL` overrides the warm-up URL (default `https://13-50-9-19.sslip.io`). The script stops on the first error (`set -euo pipefail`).

1. **Check the working tree.** Refuses to deploy if there are local modifications.
2. **Fetch.** `git fetch`; if the upstream commit equals HEAD, it skips to step 6.
3. **Check fast-forward.** If HEAD is not an ancestor of upstream, the history diverged: it aborts without changing anything.
4. **Lint the incoming revision.** `git archive` extracts `src/` and `public/` of the upstream commit into a temp directory and runs `php -l` on every PHP file. On any error it aborts and nothing is applied.
5. **Apply.** `git merge --ff-only`, then `composer install --no-dev --optimize-autoloader --no-interaction`.
6. **Reload Apache** (`sudo systemctl reload apache2`), which clears APCu and OPcache.
7. **Warm up.** Calls `/api/search` with a Hebrew query. A non-200 status is an error, and the message says the new code is already live. The script then reports whether `semantic` is true (otherwise a warning that the lexical fallback is active, so Voyage may be failing) and prints `took_ms`.
8. **Summary.** Prints `previous -> current` short hashes and the rollback command.

### Why it lints before applying

An earlier version linted after `git pull`. That assumed new code only goes live on an Apache reload, but OPcache revalidates file timestamps, so Apache can start serving a modified file before any reload. A syntax error would then be live before the check ran. Linting the incoming revision from `git archive`, before the working tree is touched, avoids this.

### Rollback

The script prints the exact command with the previous commit hash:

```bash
git reset --hard <previous> && composer install --no-dev --optimize-autoloader --no-interaction && sudo systemctl reload apache2
```

`composer install` is part of it because dependencies may differ between the two revisions.

Deploys are not atomic: files are updated in place, then Apache is reloaded.
