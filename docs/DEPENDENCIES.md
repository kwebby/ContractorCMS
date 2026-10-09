<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Dependencies and supported deployment profiles

The committed `composer.lock` and `package-lock.json` are the version authorities. The table below records the lockfile versions inspected for this release on 2026-10-09; it is not an instruction to upgrade packages independently.

## Server runtime

| Requirement | Details |
|---|---|
| PHP | Minimum **8.4.1**, as required by `composer.json`; local verification used PHP 8.5.9. Use a maintained PHP patch release that satisfies the lockfile. |
| Web server | Apache with rewrite/PHP handling, or Nginx with PHP-FPM. Public root must be `public/`. |
| PHP CLI | Required for initial installation, migrations, bounded scheduled processing, backups, restoration, and upgrades. Match the web runtime's version/extensions. |
| SQL database | PostgreSQL or MySQL; Supabase is accessed through its PostgreSQL connection. See the evidence matrix below. |
| Private local storage | Writable `storage/` and `bootstrap/cache/`, outside the public directory. Files, themes, PDFs, file sessions, and cache remain on the app server. |
| Cron | Every minute for `php artisan schedule:run`; Docker uses `schedule:work`. |
| Network | Outgoing HTTPS to configured providers; SMTP to the mail provider; database connectivity. No inbound database exposure is required. |
| ZIP encryption | PHP ZIP/libzip support for AES-256 encryption is required for the built-in backup service. |

`cms:doctor` requires these PHP extensions: `openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`, `zip`, `gd`, `intl`, `sodium`, and `bcmath`. Enable `pdo_pgsql` for PostgreSQL/Supabase or `pdo_mysql` for MySQL. XML/DOM support is also needed by the installed document libraries; some packages bundle these extensions together. OPcache is recommended for production.

Use at least `memory_limit=256M` as an initial deployment setting and measure real workloads. Set `upload_max_filesize=25M` or greater and `post_max_size=30M` or greater for theme uploads; match the web-server body limit. The application still caps theme ZIPs at 25 MiB compressed/100 MiB extracted and ordinary attachments at 10 MiB. Increasing PHP limits does not change those application limits. Larger backup jobs may require more memory; the current portable backup is bounded and not a streaming large-dataset solution.

Run capability checks after configuration and SQL migrations:

```bash
php -v
php -m
php artisan cms:doctor --install --no-interaction
```

On a source/build host, also run `composer check-platform-reqs --no-dev`. On a prebuilt host without Composer, doctor is available but does not replace the complete host acceptance checks.

## Build and development tools

| Tool | Requirement/purpose |
|---|---|
| Composer | Composer 2 for dependency installation from `composer.lock`; production targets can omit it when using the prebuilt archive |
| Node | Use **Node 22.22.2+ within 22.x** for the full checked-in build/test toolchain; local checks used 22.23.2. The bundled jsdom version has a stricter requirement than Vite alone. |
| npm | npm distributed with the supported Node runtime; use `npm ci --include=dev` from the lockfile |
| Git | Source checkout and versioned releases |
| Bash, rsync, tar | Needed by `bin/package-release` on the release build machine |
| Playwright Chromium | Needed only for browser tests; install with `npx playwright install chromium` and supply the host libraries required by Playwright |
| Docker/Compose | Optional container deployment; not required for a normal PHP installation |

Production PHP requests use compiled frontend assets. They do not start Node, Vite, a Node SSR server, or a browser. Keep `vendor/` and compiled `public/build/` in the deployable package; leave `node_modules/`, development dependencies, and test artifacts on the build machine.

## Main libraries

| Area | Locked package/version | Role |
|---|---|---|
| Backend | Laravel 13.35.0 | HTTP, configuration, mail, migrations, sessions, scheduler |
| Inertia backend | inertiajs/inertia-laravel 3.5.1 | Laravel-to-React application pages |
| Application UI | React/React DOM 19.3.0 | Authenticated workspace |
| Inertia frontend | @inertiajs/react 3.8.0 | Client navigation and page props |
| Type checking | TypeScript 7.0.2 | Frontend contracts |
| Asset pipeline | Vite 8.3.4; Laravel Vite plugin 3.2.0 | Production asset bundles |
| Styling | Tailwind CSS 4.3.3 and application CSS | Workspace styling |
| Writing | BlockNote core/react/mantine 0.55.0 | Structured content editor |
| Layout design | GrapesJS 0.23.6 | Editable layout project JSON |
| Theme templates | Twig 3.30.0 | Restricted uploaded template rendering |
| HTML filtering | HTMLPurifier 4.19.1 | Sanitizing allowed content |
| Documents | Dompdf 3.1.6 | Constrained invoice/payslip PDF rendering |
| Payments | stripe/stripe-php 22.0.0 | Stripe webhook verification; provider calls use server HTTP clients |
| MFA | pragmarx/google2fa 9.1.0 | TOTP verification |
| Firestore authentication | google/auth 1.55.1 | Experimental Google REST adapter credentials |
| PHP tests | PHPUnit 12.5.38 | Unit and feature tests |
| Frontend tests | Vitest 5.0.3; Playwright 1.64.0 | Component/API and browser checks |

`laravel/tinker` is available for controlled development/administration; do not expose it through the web. Pail, Pint, Faker, Mockery, Collision, and PHPUnit are development tools. The prebuilt release excludes Composer's development dependencies.

No BlockNote XL or commercial extension is required by this implementation. Font families are served from the application's bundled WOFF2 assets; each family retains its license. See [Third-party notices](../THIRD_PARTY_NOTICES.md) before redistributing modified bundles.

## Database and host evidence

| Profile | Implementation | Actual verification |
|---|---|---|
| PostgreSQL | SQL repository with PDO | PHP suite exercised on PostgreSQL 17.11 |
| MySQL | Same repository contract with MySQL SQL adapter | PHP suite exercised on MySQL 9.7.1; MySQL 8.4 is the supplied CI profile |
| Supabase PostgreSQL | PostgreSQL adapter with provider connection/TLS settings | SQL behavior covered; a real hosted Supabase connection is not yet certified |
| Firestore | Google-hosted REST persistence adapter | Mocked REST/contract tests only; experimental flag required; portable backup/restore unsupported |
| Ubuntu 24.04/26.04 LTS | Native PHP-FPM or container deployment target | Clean-host certification remains outstanding |
| CentOS Stream 10 | Native compatible PHP-FPM or container target | Clean-host certification remains outstanding |
| Shared PHP hosting | Prebuilt assets, file sessions/cache, cron outbox | Packaged PHP smoke check passed locally; provider-specific shared-host acceptance remains outstanding |
| Docker Compose | PHP 8.4/Apache, PostgreSQL 17, scheduler container | Supplied configuration; clean container acceptance remains outstanding |

Do not read this table as a minimum supported version guarantee for untested older database releases. MariaDB and SQLite are not exposed as supported production data providers. Laravel's generic connection configuration may include them, but the ContractorCMS installer checks the certified-provider allowlist separately.

Redis, Horizon, Reverb, WebSockets, external object storage, and a permanent queue worker are not baseline dependencies. Chat uses cursor-based polling and background actions use the durable database outbox. The presence of generic Laravel Redis/S3 variables in `.env.example` does not mean those optional integrations have been implemented or certified.

Keep dependency upgrades deliberate: update the appropriate lockfile on a development branch, run both database test profiles, frontend checks, build, dependency audits, and affected browser workflows before packaging. See [Validation](VALIDATION.md) and [Release acceptance](RELEASE-ACCEPTANCE.md).
