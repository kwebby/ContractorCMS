<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Installing ContractorCMS

ContractorCMS installs one contractor business, with its staff, clients, website, branches, and trades. It runs as a PHP application with a SQL database and private local file storage. This is a development release: review [the release acceptance checklist](RELEASE-ACCEPTANCE.md) before using it for live business records.

## Choose an installation method

| Method | Use it when | What must be installed |
|---|---|---|
| Prebuilt release archive | Deploying to eligible shared hosting or a PHP server | PHP and extensions, PostgreSQL/MySQL access, a web server, CLI cron |
| Git/source checkout | Developing or building your own deployment | All runtime requirements, Composer 2, Node 22.22.2+ within the 22.x line, npm, Git |
| Docker Compose | Evaluating the supplied container profile | Docker Engine and Compose plugin; Git checkout |

The prebuilt `contractor-cms-*.tar.gz` archive includes `vendor/` and `public/build/`; it needs no Node runtime or Composer installation on the target host. GitHub's automatically generated **Source code** ZIP/tarball is a source checkout, not a prebuilt application package. A source checkout requires the build steps below.

Read [Dependencies](DEPENDENCIES.md) for the precise extension list and database verification status. Start with PostgreSQL or MySQL. Supabase uses PostgreSQL. Firestore remains experimental and is not a normal production installation choice.

## 1. Provision the host and database

Use a dedicated application directory, such as `/var/www/contractor-cms`, and a dedicated database/user. The website document root must be `/var/www/contractor-cms/public`, never the application root. The web process must be able to read the code and write only the required storage/cache paths. See [Deployment](DEPLOYMENT.md) for Apache, Nginx, PHP-FPM, shared hosting, Docker, and permissions.

For PostgreSQL, a database administrator can provision an isolated role/database through `psql`:

```sql
CREATE ROLE contractor_cms LOGIN;
\password contractor_cms
CREATE DATABASE contractor_cms OWNER contractor_cms;
```

`\password` is a `psql` command that prompts for the password. Run these provisioning commands as a database administrator, then use the new role in the application. The database owner can run the application's migrations; do not configure the application with the PostgreSQL superuser.

For MySQL, create a dedicated UTF-8 database and user in your hosting panel, or use an administrator's MySQL session:

```sql
CREATE DATABASE contractor_cms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'contractor_cms'@'localhost' IDENTIFIED BY 'REPLACE_WITH_A_UNIQUE_PASSWORD';
GRANT ALL PRIVILEGES ON contractor_cms.* TO 'contractor_cms'@'localhost';
```

Replace the example password and restrict the account host to the actual application connection origin. Remote deployments need the appropriate host grant and firewall/TLS rules; `localhost` is only an example. These privileges are scoped to this application's database and include schema migration privileges. Do not use the MySQL root account for the application.

## 2. Obtain the application

### Prebuilt archive

Download a [project release](https://github.com/kwebby/ContractorCMS/releases) asset named `contractor-cms-*.tar.gz`. Verify its checksum if the release provides one. Extract it into a new, empty application directory outside the public document root:

```bash
mkdir -p /var/www/contractor-cms
tar -xzf /path/to/contractor-cms-RELEASE.tar.gz -C /var/www/contractor-cms
cd /var/www/contractor-cms
cp .env.example .env
chmod 600 .env
```

Use paths owned by your deployment account; provisioning `/var/www` may require an administrator. Confirm that `vendor/autoload.php` and `public/build/manifest.json` exist. Continue to configuration below; skip the Composer/npm build steps.

### Git/source checkout

Clone the public source repository:

```bash
git clone https://github.com/kwebby/ContractorCMS.git contractor-cms
cd contractor-cms
cp .env.example .env
chmod 600 .env
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci --include=dev
npm run typecheck
npm run build
composer check-platform-reqs --no-dev
```

`npm ci` installs the versions in `package-lock.json`. Build tools are development dependencies, so they must be installed on the build machine even when the resulting application will run in production. Do not run `composer update` or `npm update` during installation. Never bypass PHP platform checks to make an incompatible host appear supported.

For contributors who need PHPUnit and developer tools, use `composer install --prefer-dist --no-interaction` without `--no-dev` instead. See [Architecture](ARCHITECTURE.md) and [Validation](VALIDATION.md).

## 3. Configure the environment

Edit `.env` with a protected editor. It is intentionally excluded from version control. Set the correct public HTTPS URL before inviting users or generating emailed links:

```dotenv
APP_NAME=ContractorCMS
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cms.example.com

DATA_PROVIDER=postgres
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=contractor_cms
DB_USERNAME=contractor_cms
DB_PASSWORD="YOUR_DATABASE_PASSWORD"
DB_SSLMODE=prefer

SESSION_DRIVER=file
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
CMS_MFA_REQUIRED=true
```

Choose the matching database block:

| Database | Required selections |
|---|---|
| PostgreSQL | `DATA_PROVIDER=postgres`, `DB_CONNECTION=pgsql`, usually port `5432` |
| MySQL | `DATA_PROVIDER=mysql`, `DB_CONNECTION=mysql`, usually port `3306` |
| Supabase PostgreSQL | `DATA_PROVIDER=supabase`, `DB_CONNECTION=pgsql`, provider connection parameters, `DB_SSLMODE=require` or stricter verified TLS configuration |

For MySQL, replace the PostgreSQL values; `DB_SSLMODE` applies to PostgreSQL only. For remote PostgreSQL, use encryption required by your database provider instead of the local example's `prefer` mode.

For Supabase, use the **database password**, not an anonymous API key or service-role JWT. Copy the host, user, port, and database from the project's Connect panel. Prefer the direct database connection when the host can reach it, or the session pooler when an IPv4 connection is needed. The direct username and pooler username can differ. This application uses normal SQL migrations and transactions, not Supabase Auth or Supabase Storage. Consult [Supabase's connection guide](https://supabase.com/docs/guides/database/connecting-to-postgres) for the project's current connection choices. A real hosted Supabase deployment is still a release acceptance item.

Generate an application encryption key once, on this new installation:

```bash
php artisan key:generate --force --no-interaction
```

Retain this key securely. It encrypts provider settings and MFA secrets, and the original key is required for restoration. Do not regenerate it during upgrades or to troubleshoot a database error. A full environment reference and setup instructions for email, payments, and AI are in [Configuration](CONFIGURATION.md).

## 4. Prepare storage, migrate, and check capabilities

The repository/release includes the required directory structure. If the extraction process omitted empty directories, recreate them:

```bash
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan cms:doctor --install --no-interaction
```

Run commands as the application/deployment account with the same storage access as the web process. Apply the ownership and permissions from [Deployment](DEPLOYMENT.md), rather than making these directories world-writable. A new SQL installation must run migrations before `cms:doctor` can pass its database/schema check.

Resolve every failed doctor check before continuing. It checks the PHP version, required extensions, key, storage, selected adapter, and database/schema access. In production it also requires an HTTPS `APP_URL`, disabled debug mode, privileged MFA, and compiled assets. It cannot prove that DNS, certificates, outgoing email, provider credentials, or external backups work; test those separately.

## 5. Create the first owner

The installer creates an owner only once and then locks bootstrap access. It refuses to run if an installation record or existing users are present. There is no default administrator password.

Supply `CMS_OWNER_PASSWORD` through your deployment secret manager, then run:

```bash
php artisan cms:install --email="owner@example.com" --name="Business Owner" --business="Example Contractor" --country=US --currency=USD --no-interaction
```

For Canada, use `--country=CA --currency=CAD`. The password must contain at least 12 characters. If working in an interactive **Bash** terminal without a secret manager, this reads it without displaying it or putting its value in shell history:

```bash
IFS= read -r -s -p 'Owner password (12+ characters): ' CMS_OWNER_PASSWORD
printf '\n'
export CMS_OWNER_PASSWORD
php artisan cms:install --email="owner@example.com" --name="Business Owner" --business="Example Contractor" --country=US --currency=USD --no-interaction
unset CMS_OWNER_PASSWORD
```

Do not put the password in a command-line argument, a committed file, or an example `.env`. An operator running `php artisan cms:install` interactively can also use its built-in prompts; automated runs must supply every value and `CMS_OWNER_PASSWORD`.

Open `/login`, sign in, and enroll an authenticator at `/security`. Privileged accounts must complete MFA before they can use the workspace when `CMS_MFA_REQUIRED=true`. Set the business timezone in Settings immediately; bootstrap initializes it to `America/New_York` for both countries.

## 6. Enable scheduled processing

Install the following crontab as the application owner, replacing the PHP path and application directory:

```cron
* * * * * cd /var/www/contractor-cms && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

The schedule processes the durable outbox once per minute, with a 50-item/45-second bound and overlap protection. This delivers mail, notifications, and digests. It does not require Laravel's database queue, Redis, or a persistent worker. Rotate `scheduler.log` with your host's log rotation facilities.

For a manual processing check:

```bash
php artisan cms:outbox --limit=50 --seconds=45 --no-interaction
```

Open **Settings → System health** and confirm that the scheduler heartbeat becomes healthy. Configure actual SMTP before relying on account invitations/reset messages; the example environment's `MAIL_MAILER=log` records mail locally rather than delivering it.

## 7. Finish setup and verify the full flow

1. Set business identity, country, currency, timezone, tax references, and invoice design.
2. Configure SMTP on the dedicated Email delivery page; queue a test, process the outbox, and verify receipt.
3. Enter the public business profile and real branch/service-area information in Website → Local SEO. Private billing details are separate from public directory details.
4. Edit Website → Homepage, Appearance, and Navigation. Preview, then explicitly publish the desired snapshots. Upload/configure a theme if needed; read [Themes](THEMES.md).
5. Invite a staff member, verify role access, create a customer, and send an authorized client portal invitation.
6. Submit a public inquiry; move it through estimate acceptance, scheduling, completion, invoice issuance, and a test/offline payment.
7. Check public metadata, sitemap, social tags, and the absence of private customer/employee records in anonymous responses.
8. Create a protected backup and restore it on an independent staging installation before relying on the system.

Cache application configuration after setup:

```bash
php artisan optimize --no-interaction
php artisan cms:doctor --install --no-interaction
```

Provider settings edited in the application are stored in the database; environment changes need `php artisan optimize:clear` followed by `php artisan optimize`. Never run demo seeders on a real business installation.

## Local development without HTTPS

Only for a loopback development instance, use:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
SESSION_SECURE_COOKIE=false
CMS_MFA_REQUIRED=false
MAIL_MAILER=log
```

Keep the other database/storage settings and run the migration/owner steps above. Run `composer run dev` to start Laravel, the scheduler, and Vite together, or use a built frontend with `php artisan serve --host=127.0.0.1 --port=8000` and a separate `php artisan schedule:work`. Built assets are still useful for the public pages. The built-in PHP server is a development server; use Apache/Nginx for deployment. Never expose this debug configuration to the internet.

## Troubleshooting

| Symptom | Check |
|---|---|
| Installer reports database/schema failure | Matching `DATA_PROVIDER` and `DB_CONNECTION`, correct PDO extension, credentials/network, and completed SQL migrations |
| Login returns to the login screen on localhost | `APP_URL` matches the origin; local HTTP uses `SESSION_SECURE_COOKIE=false`; storage sessions are writable |
| Production redirects to `/security` | Complete the required MFA enrollment/challenge |
| Vite manifest missing | Run `npm ci --include=dev` and `npm run build`, or use the prebuilt release asset |
| Email test remains queued | Cron/CLI PHP path, outbox heartbeat, SMTP credentials/TLS, outgoing port policy |
| Uploaded theme rejected | Read the validation message and [theme package contract](THEMES.md); ZIP root/paths/types/limits are enforced |
| 413 on theme upload | Align Nginx/Apache limits and PHP `upload_max_filesize`/`post_max_size`; see Deployment |
| Updated `.env` appears ignored | Clear/rebuild Laravel's configuration cache; restart persistent services if used |
| Install says bootstrap is locked | The installation already has users; sign in or use account recovery instead of reinstalling |

For operational backup, restore, upgrades, and host-specific configuration, continue to [Deployment](DEPLOYMENT.md).
