<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Developing ContractorCMS

ContractorCMS is one Laravel application with React/Inertia workspace screens and server-rendered public pages. One installation belongs to one contractor company. Branches organize locations; they are not a multi-tenant security boundary.

Start with [Installation](INSTALLATION.md) and [Dependencies](DEPENDENCIES.md) to create a local installation. For a public website design without application code changes, follow the [Theme developer guide](THEMES.md). The [Architecture](ARCHITECTURE.md), [Implementation status](IMPLEMENTATION.md) and [Release acceptance](RELEASE-ACCEPTANCE.md) documents explain the current boundaries and unfinished certification work.

## Development commands

Use the PHP and Node versions specified by the dependency guide and lockfiles. From the repository root, after configuring a local `.env`, installing dependencies, migrating the development database and creating an owner:

```bash
composer run dev
```

This runs the PHP development server, Laravel scheduler and Vite together. Use the URL reported by the PHP server and set `APP_URL` to match it. The supplied Playwright configuration defaults to port 8976; either launch PHP on that port or set `PLAYWRIGHT_BASE_URL` when testing.

To run the processes separately:

```bash
php artisan serve --host=127.0.0.1 --port=8976
```

```bash
npm run dev
```

```bash
php artisan schedule:work
```

Each command runs in its own terminal. The scheduler is needed to drain durable outbox work. `npm run build` writes production browser assets into `public/build`; a packaged production host serves those files without a Node process. Keep `storage` and `bootstrap/cache` writable by the application's operating-system user.

## Repository map

| Path | Responsibility |
|---|---|
| `app/Contracts/RecordRepository.php` | Authoritative persistence contract, transactions, locks and sequences |
| `app/Infrastructure/` | SQL/Firestore persistence, outbox processing and backup support |
| `app/Identity/` | Repository-backed users and Laravel authentication provider |
| `app/Support/` | Authorization, roles, secrets and shared support services |
| `app/Operations/` | Operational state changes and CRM/planning relationships |
| `app/Finance/` | Exact money, estimates, invoices, payment gateways and PDFs |
| `app/Workforce/` | Compensation records, pay runs and payslips |
| `app/Communications/` | Versioned email templates and notification digests |
| `app/Publishing/` | BlockNote/layout rendering, themes, homepage sections, website settings and SEO |
| `app/Automation/` | AI provider adapter and bounded public lead tools |
| `app/Http/Controllers/` | Input validation, authorization and HTTP responses |
| `app/Http/Middleware/` | Request security, workspace sharing and application access rules |
| `app/Console/Commands/` | Installation, doctor, outbox, backup and restore commands |
| `config/` | Runtime configuration; `.env` is local and untracked |
| `routes/` | Web/session endpoints and `/api/v1` application routes grouped by module |
| `database/migrations/` | SQL schema migrations |
| `database/seeders/` | Explicit local/demo data population |
| `resources/js/pages/` | Inertia authentication and workspace shell pages |
| `resources/js/sections/` | Dedicated module screens, including settings and website editors |
| `resources/js/components/` | Shared UI, BlockNote writer and GrapesJS designer |
| `resources/js/api.ts` | Same-origin authenticated request helper and error handling |
| `resources/views/public/` | PHP-rendered public website, managed widgets and public styles/scripts |
| `resources/views/documents/` | Application-owned invoice, receipt and payslip PDF templates |
| `resources/fonts/catalog.json` | Curated public font-family metadata |
| `public/assets/website-fonts/` | Locally hosted WOFF2 assets and original family licenses |
| `storage/app/private/` | Runtime files, installed theme packages, PDFs and backups; never commit |
| `tests/Unit/`, `tests/Feature/` | Default PHPUnit suites |
| `tests/Integration/` | Optional integration scenarios outside the default PHPUnit suite |
| `resources/js/__tests__/` | Vitest component and request-helper tests |
| `tests/Browser/` | Playwright workflows against a running test installation |
| `deploy/` | Example Apache/Nginx/Docker/cron configurations |
| `bin/package-release` | Production archive build with vendor dependencies and compiled assets |

Inspect routes with `php artisan route:list --except-vendor`, and list administrative commands with `php artisan list`. Names in source are the authority; do not assume a route or hook exists because another CMS offers one.

## Request and persistence flow

The usual flow is:

```text
React/Inertia screen or same-origin browser request
  → route + session/CSRF middleware
  → controller input validation and Access checks
  → application service/state transition
  → RecordRepository transaction + locks
  → business records and durable outbox records
  → response and later outbox delivery
```

The application binds `RecordRepository` in `AppServiceProvider`. SQL uses the versioned `cms_records` store and `cms_locks`; Supabase uses the PostgreSQL adapter. Firestore has an alternative REST implementation, but its complete production parity and operational certification remain unfinished. Avoid introducing Eloquent queries in business services merely because the development installation uses SQL.

Record mutations carry versions. Where the API requests `expected_version`, send the version returned by the last read; report a 409 conflict and let the user reload/reconcile instead of overwriting another editor silently. Use `transaction()`, `lock()` and `sequence()` for multi-record invariants and numbering. Persist the outbox event in the same business transaction as the action that requires delivery. A successful request and a delivered email are different states.

Financial calculations use the `Money` implementation and integer minor units. Do not use JavaScript/PHP floating-point arithmetic for ledger amounts. Invoice issuance preserves immutable snapshots; later corrections belong in explicit credit/reissue/reversal workflows.

## Add a business feature

1. Choose the owning module and inspect its current controller, service, permissions and tests.
2. Specify permitted actors and record relationships. A permission alone is insufficient when a technician must be assigned to the job or a client must own the customer record.
3. Add bounded input validation and the service transition. Keep database-specific code in persistence adapters and wrap related changes in a transaction.
4. Add an explicit route under the existing module's route file. Reuse the session/CSRF boundary and the shared request helper.
5. Build a dedicated screen for substantial settings or workflows. Use inline save status/errors, existing controls and optimistic version handling.
6. Add relevant notifications/audits/outbox delivery and a useful failure state where the business action requires them.
7. Add tests for successful behavior, the denied role/relationship, invalid transitions and concurrency/idempotency where meaningful.
8. Update implementation/upgrade documentation if the feature changes persisted data or operational requirements.

Use Artisan generators for new Laravel classes, for example:

```bash
php artisan make:class Operations/ExampleWorkflow --no-interaction
php artisan make:controller ExampleController --no-interaction
php artisan make:test ExampleWorkflowTest --phpunit --no-interaction
```

These names illustrate the commands; choose a concrete feature name in a real change. New first-party main files retain the project attribution comment, using the language's comment syntax:

```text
Author: ramanpal singh
URL: https://kwebby.com
```

JSON does not support comments. Keep it valid JSON and place attribution in accompanying source/license documentation. Preserve all third-party copyright and license notices.

## Public rendering and custom structure

`PublishingController` selects a published snapshot and composes the public response. BlockNote content is rendered by `BlockNoteRenderer`; expert page projects go through `LayoutRenderer`; guided homepages go through `HomepageSections`. `ThemeManager` then wraps the resulting content in the selected sandboxed Twig template. Finally, the application view emits the document head, public scripts and metadata.

The public home route is `/`; other published pages use `/p/{slug}`. An uploaded theme maps existing page types to templates. It cannot change those routes, create database models or install code. If a deployment needs a different URL structure, implement it in application routes, canonical generation, redirects, sitemap output and link resolution together, then test old URLs and publication behavior. Do not change template links alone and leave canonical/sitemap URLs inconsistent.

Keep the CMS's SEO head intact. `SeoBuilder`, `WebsiteSettings` and the public view generate canonical URLs, robots, language alternates, Open Graph, X cards and the protected business/page JSON-LD graph. Add new page types through validation, UI choices, schema generation, sitemap behavior and tests as one change. Branch data must represent a real location; service-area pages must not invent offices or public addresses.

Theme source and settings are presentation. Business identity belongs in the canonical public profile; billing identity is managed separately. The API's public projections must continue to omit hidden addresses and private finance/payroll/client fields.

### Adding a section or widget

For a new guided homepage section, inspect these existing extension points:

- `HomepageSections`: type/preset definitions, allowed fields, length limits, validation, media discovery and safe rendering.
- `resources/js/sections/Website.tsx`: section controls, repeated items, ordering and preview interaction.
- `resources/views/public/home-sections.css`: responsive presentation.
- Homepage/publishing tests: invalid data, single-H1 rules, draft media access, publication/revision behavior and public output.

For a new expert-layout component, add both the client editor definition in `LayoutDesigner.tsx` and the server handler in `LayoutRenderer`. A drag-and-drop component that renders only in the editor is incomplete. If it requires interactivity, implement the server endpoint, validation, authorization/rate limits, application-owned HTML and application-served JavaScript before exposing the component. Theme ZIPs remain unable to ship executable JavaScript.

For a new BlockNote block, update the writer schema and PHP renderer together. Add a round-trip fixture covering the editor's actual JSON shape and malicious/unsupported properties. Do not enable every upstream block and silently discard what PHP cannot render.

For a new font family, update the curated catalog and local WOFF2 assets with its verified source, hashes and full redistribution license. Keep approved-family validation and public font CSS in sync. This is a source change; arbitrary remote font URLs are not a public appearance setting.

## Tests and safe test data

Default checks:

```bash
vendor/bin/phpunit
npm run typecheck
npm test
npm run build
```

The default PHPUnit configuration uses an in-memory SQLite database for quick checks. That is a testing convenience, not a supported production database selection. Validate persistence behavior on dedicated PostgreSQL and MySQL test databases as well. Tests using `RefreshDatabase` migrate/reset their selected database: **never point them at a live company database**.

For example, after creating a disposable PostgreSQL test database and setting its credentials in your terminal environment:

```bash
DB_CONNECTION=pgsql DATA_PROVIDER=postgres DB_DATABASE=contractorcms_test \
  vendor/bin/phpunit
```

Set `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` for that test server too; do not let production `.env` values fill those fields accidentally. MySQL uses `DB_CONNECTION=mysql DATA_PROVIDER=mysql`. Run suites serially when they share a test database or file paths. The default PHPUnit XML includes Unit and Feature; integration tests require an explicit path and their stated prerequisites. CI currently checks PostgreSQL/MySQL and the frontend; it does not certify live payments, a real Firestore project or every hosting profile.

Use the narrowest relevant tests while developing:

```bash
vendor/bin/phpunit tests/Feature/PublishingThemeTest.php
vendor/bin/phpunit tests/Feature/ThemeCapabilitiesTest.php
npm test -- resources/js/__tests__/theme-capabilities.test.tsx
```

After changing PHP, run the project's formatter:

```bash
vendor/bin/pint --dirty --format agent
```

For browser tests, use a disposable running installation with an owner and the test fixtures expected by `tests/Browser`. Install Chromium once with `npx playwright install chromium`. Set `PLAYWRIGHT_BASE_URL`, `PLAYWRIGHT_EMAIL` and `PLAYWRIGHT_PASSWORD` in a private terminal environment, then run:

```bash
npm run test:e2e
```

Browser workflows create and change business records, and website scenarios temporarily change public configuration before restoring it. They are unsuitable for production. The optional local credential fallback file is ignored by Git. Traces, screenshots and failure reports can contain entered data; keep them out of the repository and public releases. Login rate limits still apply during repeated browser runs.

## Dependencies, secrets and generated files

Commit both dependency lockfiles and install with `composer install` / `npm ci`. Do not use an unrelated dependency upgrade as a fix for a feature change. Review upstream compatibility and licenses before adding packages, particularly editor extensions. `vendor`, `node_modules` and `public/build` are generated locally; the release packager includes production vendor files and compiled assets separately.

Keep `.env`, API credentials, session data, application keys, database dumps, backups, logs, uploads and browser traces out of Git. `.env.example` documents variable names with safe defaults; it must not contain working credentials. Credentials configured in the application use server-side encryption. Retain the real `APP_KEY` securely for restore and upgrade; losing or rotating it without a migration plan can make encrypted data unreadable.

Do not widen trusted proxies, CSRF exemptions, theme allowlists, MIME checks, private file access or payment-webhook verification to make a test pass. Fix the intended request and retain the boundary. Public AI tools may receive their validated public inputs; they must not gain private CRM/payroll repository context or execute financial actions.

## Build a release

On a build machine with dependencies and frontend tooling available:

```bash
bin/package-release
```

The script builds assets, checks Composer metadata, stages application source/docs/configuration, installs production PHP dependencies and writes a timestamped `.tar.gz` under `dist/`. Use `bin/package-release --reuse-assets` only after deliberately rebuilding and verifying `public/build`; otherwise it can package stale browser code. It does not run the complete test suite or independent penetration testing for you.

Before distributing an archive, inspect its contents for local data/secrets, verify installation on a fresh environment, run the relevant checks and update [Validation](VALIDATION.md). Follow [Deployment](DEPLOYMENT.md) for document-root, file-permission, cron and backup requirements. A source archive generated automatically by GitHub does not contain the built `vendor` or `public/build` directories; it needs the source-install workflow.

Current production gates remain documented in [Release acceptance](RELEASE-ACCEPTANCE.md), including provider checks, host certification, restore exercises and independent manual penetration testing. Automated checks are evidence for their tested paths, not a substitute for those gates.
