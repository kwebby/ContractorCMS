<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Deployment, backups, and upgrades

Begin with [Installation](INSTALLATION.md) and [Dependencies](DEPENDENCIES.md). These are supplied deployment profiles, not a claim of clean-host certification. Ubuntu 24.04/26.04 LTS, CentOS Stream 10, shared hosting, and Docker each have acceptance work listed in [Release acceptance](RELEASE-ACCEPTANCE.md).

## Shared PHP hosting

Choose a host with PHP **8.4.1+** and required extensions, matching CLI PHP, cron, outgoing HTTPS/SMTP, a SQL connection, protected writable storage, and control over the public document root. Upload the **prebuilt release asset**, not GitHub's source ZIP, if the host has no build tools.

Extract outside `public_html`, then configure the domain root to the application's `public/` directory in the hosting panel. Example: application `/home/account/contractor-cms`, public root `/home/account/contractor-cms/public`. If the provider cannot serve that root while protecting `.env`, `storage`, `vendor`, and application code, use another hosting profile. Moving private application files into a public directory is not a supported workaround.

Select PHP/extensions in the panel. Initial settings:

```ini
memory_limit = 256M
upload_max_filesize = 25M
post_max_size = 30M
max_execution_time = 60
```

These support bounded theme/PDF/provider work, not guaranteed sizing for every installation. Large backups need separately measured CLI memory/time allowances. Match web-server request limits to at least 30 MiB. Hosts can enforce lower limits outside PHP; verify with the provider.

Follow Installation's environment/key/migrate/doctor/owner steps. Add [deploy/shared-host.cron](../deploy/shared-host.cron) using the actual CLI PHP path. Queue a test email and confirm receipt. No Node process, Redis, WebSocket daemon, permanent worker, or public storage symlink is required.

## Native Linux filesystem and permissions

Keep source readable by the web/PHP account, with write access limited to `storage/` and `bootstrap/cache/`. This example assumes the deployment account owns `/var/www/contractor-cms` and PHP runs in Ubuntu's `www-data` group:

```bash
cd /var/www/contractor-cms
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R u+rwX,g+rwX,o-rwx storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
sudo chgrp www-data .env
chmod 640 .env
```

Deploy/cron and PHP accounts need compatible group membership/umask so newly created cache/session files remain accessible. Shared hosting may run PHP as the same account, allowing `.env` to remain `0600`. On CentOS use the actual FPM pool user/group instead of `www-data`. Do not use `0777` or make source writable by the web process.

Keep `storage/app/private` as a real directory: backup/restore rejects symlinks within protected storage. With release directories, copy or mount persistent storage at the intended path instead of adding a symlink into the private tree. A container volume mount is supported by the supplied layout. No `php artisan storage:link` is needed; CMS downloads pass through authorization-aware routes.

## Ubuntu with Nginx/PHP-FPM

Install a maintained PHP 8.4.1+ distribution and its FPM/CLI/extensions first. Do not assume the default `php` package meets the requirement. Review the PHP project's [Ubuntu installation instructions](https://www.php.net/downloads.php?os=linux&osvariant=linux-ubuntu&usage=web&version=8.4) before selecting a package repository. Repository changes are an operator decision, not performed by the CMS installer.

Once an appropriate PHP 8.4 package source is configured, typical Ubuntu package names are:

```bash
sudo apt-get update
sudo apt-get install nginx php8.4-cli php8.4-fpm php8.4-pgsql php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath
php -v
php -m
```

Core extensions such as OpenSSL, ctype, tokenizer, PDO, JSON, fileinfo, and sodium may be supplied by core/common packages. Verify the complete list with doctor. Install a local database daemon only if needed; a managed database needs the PDO driver and network access.

Copy/edit the supplied Nginx example:

```bash
sudo cp deploy/nginx/contractor-cms.conf /etc/nginx/sites-available/contractor-cms
sudo ln -s /etc/nginx/sites-available/contractor-cms /etc/nginx/sites-enabled/contractor-cms
sudo nginx -t
sudo systemctl reload nginx
```

Use the symlink command only if the site link does not exist. Replace `cms.example.com`, `/var/www/contractor-cms/public`, and `/run/php/php8.4-fpm.sock` with the actual domain/root/FPM listener. The example sends only `/index.php` to PHP, rejects other PHP paths, blocks dotfiles/private paths, and caps request bodies at 30 MiB. Configure FPM startup and verify socket permissions.

The template listens on port 80 to demonstrate routing. Add a valid TLS certificate and HTTPS server block, or terminate TLS at a correctly configured proxy, before production. It is not a complete certificate setup. Retest with `nginx -t` after edits and verify public/authenticated pages over HTTPS.

## Apache

[deploy/apache/contractor-cms.conf](../deploy/apache/contractor-cms.conf) uses `DocumentRoot .../public`, permits the supplied `.htaccess` rewrite rules, and disables directory listing/MultiViews. Configure a compatible PHP handler; the vhost does not install PHP.

On an Ubuntu Apache/PHP-FPM host with packages already installed:

```bash
sudo a2enmod rewrite proxy_fcgi setenvif
sudo a2enconf php8.4-fpm
sudo cp deploy/apache/contractor-cms.conf /etc/apache2/sites-available/contractor-cms.conf
sudo a2ensite contractor-cms.conf
sudo apachectl configtest
sudo systemctl reload apache2
```

Edit domain, application/log paths, and TLS before production. `a2enconf php8.4-fpm` assumes the packaged config exists and no conflicting PHP handler is active. Use commands appropriate for the installed distribution. The Apache template is an HTTP routing example; add HTTPS before production.

## CentOS Stream 10

Use a compatible PHP-FPM/Apache or Nginx stack; clean CentOS certification is outstanding. Confirm PHP **8.4.1+** availability first. Related Enterprise Linux 10 documentation describes a PHP 8.3 base runtime, too old for this lockfile. Consult current distribution/vendor instructions rather than assuming `dnf install php` is sufficient. [Red Hat language runtime documentation](https://docs.redhat.com/en/documentation/red_hat_enterprise_linux/10/html/installing_and_using_dynamic_programming_languages/index)

After selecting a compatible maintained PHP runtime:

1. Verify CLI/FPM versions and all extensions from Dependencies.
2. For Apache, place the edited vhost in the host's `httpd` config, commonly `/etc/httpd/conf.d/`, and configure PHP-FPM. Replace Debian `/var/log/apache2/...` paths with the host's log location, commonly `/var/log/httpd/...`.
3. For Nginx, use its included configuration directory, commonly `/etc/nginx/conf.d/`, and replace the Debian FPM socket with the configured listener.
4. Use the FPM pool's actual user/group for writable directories.
5. Apply persistent SELinux contexts for readable source and the writable storage/cache directories. Permit only required database, HTTPS-provider, and SMTP network access. Keep SELinux enforcing; review labels/policy with the system administrator instead of disabling enforcement.
6. Configure firewall/TLS, validate web-server configuration, enable services, and complete Installation plus host acceptance checks.

The Docker profile below is an alternative if a compatible native PHP stack is unavailable, subject to its own checks. These instructions do not invent a universal third-party repository command for every CentOS installation.

## Docker Compose source profile

Root [compose.yaml](../compose.yaml) builds [deploy/docker/Dockerfile](../deploy/docker/Dockerfile): PHP 8.4/Apache, PostgreSQL 17, and scheduler containers. HTTP binds to `127.0.0.1:8080`. Code is copied into the image; local files use `cms-storage` and PostgreSQL uses `cms-database` named volumes.

Use a source checkout, not the prebuilt archive. Copy/configure `.env` with a strong `DB_PASSWORD`, then:

```bash
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show --no-interaction
```

The second command prints a new encryption key. Save it securely as `APP_KEY` in `.env` without committing/sharing it. `--show` does not persist it to the host file. Run only for a new installation; retain existing installations' keys.

For local evaluation only, use `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://127.0.0.1:8080`, `SESSION_SECURE_COOKIE=false`, and `CMS_MFA_REQUIRED=false`. For production keep HTTPS/secure cookies/MFA enabled and put a TLS reverse proxy before the loopback port.

```bash
docker compose up -d
docker compose exec --user www-data app php artisan migrate --force --no-interaction
docker compose exec --user www-data app php artisan cms:doctor --install --no-interaction
```

Supply `CMS_OWNER_PASSWORD` securely in the operator's process environment:

```bash
docker compose exec --user www-data -e CMS_OWNER_PASSWORD app php artisan cms:install --email="owner@example.com" --name="Business Owner" --business="Example Contractor" --country=US --currency=USD --no-interaction
unset CMS_OWNER_PASSWORD
docker compose exec --user www-data app php artisan optimize --no-interaction
```

Compose overrides database host/port/adapter to its PostgreSQL service; setting MySQL in `.env` does not change that profile. Edit/review Compose if building another deployment. The scheduler can log schema failures before migrations finish; check `docker compose logs scheduler` and its heartbeat after setup. It runs `schedule:work`; do not add duplicate host cron.

Back up both data volumes. `docker compose down -v` destroys them and is not an upgrade command. CMS backups cover business SQL records/private files; host database/volume backups can support wider disaster recovery. Keep `.env`/key separately. Container execution and independent restoration remain release acceptance checks.

## Reverse proxies and HTTPS

Set `APP_URL` to the canonical HTTPS origin. Trust only actual proxy IPs/CIDRs in `TRUSTED_PROXIES`, with correct forwarded protocol/host headers. Avoid proxy wildcards. Production host validation follows the configured application URL.

Preserve payment webhook bodies/signature headers. Do not apply HTML caching, authentication challenges, or body rewriting to webhook endpoints. Exclude authenticated pages/private files from shared caches. Verify secure cookies, emailed URLs, canonical links, and webhook delivery after proxy changes.

## Backups

The SQL backup creates an AES-256 encrypted ZIP containing `cms_records`, sequence/lock state, encrypted settings, themes, uploads, document PDFs, and other private files. It excludes existing backups, `.env`, and the plaintext app key. It does not back up unrelated tables, logs, server configuration, or the complete application source.

Supply `CMS_BACKUP_PASSWORD` (16+ characters) through a protected process environment. Enter maintenance and drain requests/stop persistent processors before the snapshot:

```bash
php artisan down --no-interaction
php artisan cms:backup --no-interaction
php artisan up --no-interaction
```

Run `up` only after confirming success. If backup fails, investigate while maintenance stays enabled. With Docker, stop the scheduler before backup and execute commands in the application container with the same key/storage; resume it afterward.

The command prints its archive path under `storage/app/private/backups/`. Copy it to access-controlled off-machine storage. Retain the original `APP_KEY`, deployment configuration, provider credentials, release version, and backup password separately. A backup on the same disk does not protect against disk loss. Add operator-reviewed backup scheduling based on recovery objectives; the default CMS schedule does not automatically make backups.

Never publish backups, even encrypted, to a public GitHub repository. They contain customer, payroll, financial, and credential-bearing records.

## Restore

Restore to an isolated installation first, never over active business data.

1. Deploy a compatible release and create an **empty** supported SQL database.
2. Set original `APP_KEY`, target database settings, and a safe staging origin. Isolate external provider actions until reconciliation is reviewed.
3. Prepare empty `storage/app/private`. Existing backup files are permitted, but no other private application files may be present.
4. Migrate, enter maintenance, and stop/drain processors.
5. Supply `CMS_BACKUP_PASSWORD` securely and restore.

```bash
php artisan migrate --force --no-interaction
php artisan down --no-interaction
php artisan cms:restore /absolute/path/backup.zip --no-interaction
php artisan cms:doctor --install --no-interaction
```

Restore rejects populated CMS tables/storage, wrong keys/passwords, unencrypted entries, unsafe paths, symlinks, corrupt hashes, and oversized archives. Limits: 1 GiB compressed, 2 GiB expanded, 128 MiB per entry, 50,000 entries. Larger installations need a measured native/streaming backup strategy. Restore invalidates file sessions.

Verify users/roles, customer/job counts, invoice numbering/balances, payments/refunds, public content, active theme/settings, private files, and payslip permissions. Reconcile provider transactions and pending outbox actions before reconnecting services. Then resume processing and run `php artisan up --no-interaction`. Record restoration time and retained release/key.

Firestore is rejected by these portable commands. Managed Firestore exports and complete recovery need a separate tested procedure before certification.

## Upgrades

1. Read target release migration/compatibility notes and validate on a staging restore.
2. Back up records/files; retain the current release, `.env`, and key.
3. Enter maintenance, stop/drain processing, and wait for in-flight requests.
4. Deploy new code/assets, preserving `.env`, `APP_KEY`, and private storage. Source deployments run `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` from the new lockfile and build on the build host. Prebuilt releases include dependencies/assets.
5. Run:

```bash
php artisan optimize:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan optimize --no-interaction
php artisan cms:doctor --install --no-interaction
```

6. Smoke-test login/MFA, website, private download, client access, invoice rendering, scheduler, and providers on staging or through controlled maintenance access.
7. Bring up the app, resume processing, and monitor failures/queue age.

Do not rerun `cms:install` or regenerate the key. Roll back application code only if it supports the migrated schema/stored formats. For incompatible failures, restore a complete compatible backup into a clean target rather than blindly rolling back schema against live data.

## Build a distributable release

On a clean source checkout with supported build tools:

```bash
bash bin/package-release
```

The script installs locked frontend dependencies, type-checks, builds assets, validates Composer, stages app/docs/configuration, installs production PHP dependencies, and creates `dist/contractor-cms-TIMESTAMP.tar.gz`. `--reuse-assets` skips the frontend build; use it only after successfully building the exact source being packaged.

The archive excludes local `.env`, private files, development databases, and Composer dev dependencies. Inspect before distributing, verify a fresh installation, and publish a SHA-256 checksum. A theme ZIP has a separate manifest/validation contract; follow [Themes](THEMES.md), not the application packaging workflow.

## Monitoring

Review **Settings → System health** for heartbeat, queue age/failures, disk capacity, storage, and configured providers. Add host process/disk monitoring, HTTPS uptime checks, log rotation, database-backup alerts, and actual provider delivery monitoring. Health indicators are application observations, not proof that payment, email, or disaster recovery succeeds end to end.
