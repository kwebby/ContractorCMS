<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# ContractorCMS configuration

Server/environment configuration lives in `.env` or the process environment. Business configuration lives in the selected database and is edited on dedicated application pages. Uploaded files and generated documents live in private local storage. Keep all three, plus the original encryption key, in your recovery plan.

## Environment reference

| Setting | Production value/purpose |
|---|---|
| `APP_NAME` | `ContractorCMS`; software name, separate from the contractor's business name |
| `APP_ENV` | `production`; use `local`/`testing` only in their corresponding isolated environments |
| `APP_DEBUG` | `false` on any exposed deployment |
| `APP_URL` | Canonical HTTPS origin, for example `https://cms.example.com`; used for public links, account emails, and payment return URLs |
| `APP_KEY` | Generated once with `php artisan key:generate`; retain securely through upgrades/restores |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | Framework locale defaults; published content's locale/SEO settings are managed separately |
| `DATA_PROVIDER` | `postgres`, `mysql`, or `supabase`; `firestore` requires explicit experimental opt-in |
| `DB_CONNECTION` | `pgsql` for PostgreSQL/Supabase; `mysql` for MySQL |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Dedicated application database connection |
| `DB_SSLMODE` | PostgreSQL TLS mode; `require` or correctly configured `verify-full` for a hosted database; `prefer` is the local example default |
| `SESSION_DRIVER` | `file` in the baseline deployment |
| `SESSION_ENCRYPT` | `true` |
| `SESSION_SECURE_COOKIE` | `true` with HTTPS; only set `false` for an isolated local HTTP instance |
| `SESSION_LIFETIME` | Inactive session lifetime in minutes; example default `120` |
| `SESSION_DOMAIN` | Leave `null` unless a reviewed deployment needs an explicit cookie domain |
| `CACHE_STORE` | `file`; used for cache and scheduler overlap protection |
| `FILESYSTEM_DISK` | `local`; CMS services explicitly use protected local storage |
| `QUEUE_CONNECTION` | `sync`; the CMS durable outbox has its own scheduler processor |
| `CMS_MFA_REQUIRED` | `true` in production; applies to accounts with privileged permissions |
| `TRUSTED_PROXIES` | Comma-separated actual reverse-proxy IPs/CIDRs, only when a proxy exists |
| `MAIL_MAILER` | `log` for development; app-configured SMTP is preferred for business use |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Laravel fallback SMTP settings if using `MAIL_MAILER=smtp` without saved CMS mail settings |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Fallback sender identity |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | Host logging policy; example uses a single file through the stack channel |

Database TLS settings are provider-specific. `DB_SSLMODE=require` encrypts the connection but is not equivalent to full server identity verification. For `verify-full`, configure the correct CA certificate for the PHP/libpq runtime and use the database's matching hostname. This release does not expose a dedicated `DB_SSLROOTCERT` variable in `config/database.php`; do not assume adding an unused variable configures TLS. See [Supabase SSL enforcement](https://supabase.com/docs/guides/platform/ssl-enforcement) for its requirements.

Values set in a deployment process environment can override `.env`. After environment changes:

```bash
php artisan optimize:clear --no-interaction
php artisan optimize --no-interaction
php artisan cms:doctor --install --no-interaction
```

Restart persistent scheduler/PHP services as appropriate for the host so they load the new environment. Never publish `.env`, credentials, local database files, or configuration-cache files containing resolved secrets.

## Business identity and invoice settings

Open **Settings → Business profile** (`/app/settings/business`). Configure trading/legal names, contact details, address, state/province, postal code, country, currency (`USD`/`CAD`), IANA timezone, trade, service areas, tax reference, website, and logo. Set the timezone explicitly; bootstrap initializes `America/New_York`.

Use **Settings → Invoice designer** (`/app/settings/invoice-design`) for the constrained document layout: colors, fonts, optional columns/sections, terms/payment instructions, and paper format. Required totals and invoice information remain application-controlled. Preview with representative long descriptions and addresses before issuing documents.

Issued invoices snapshot business/customer details, items, calculations, and design version. Updating settings changes future documents; it does not rewrite an issued invoice. Correct issued financial records through credit/reissue workflows. Payslips similarly use approved, immutable published records. The CMS does not calculate statutory payroll taxes or file payroll returns.

Public NAP settings are separate from billing identity. Use **Website → Local SEO → Business profile** to choose public name/contact details, address visibility, hours, service areas, and public links. Locations represent actual branches. Manual citation observations track evidence and inconsistencies; they do not automatically update external directories.

## Email delivery

Open **Settings → Email delivery** (`/app/settings/mail`) and confirm your password when requested for credential changes. Set:

| Field | Meaning |
|---|---|
| Host | SMTP host supplied by the email provider |
| Port | Supported values: `25`, `465`, `587`, or `2525` |
| Encryption | `tls` for required STARTTLS, or `ssl` for implicit SMTPS; match the provider |
| Username/password | Provider SMTP credentials, where required |
| From address/name | Verified sender address and the business name |
| Reply-to | Optional address for customer replies |

Saved SMTP settings take precedence for CMS outbox emails over the fallback Laravel mailer. The `mail` settings group is encrypted in the database using `APP_KEY`; secret values are masked in settings responses. Saving the mask unchanged preserves the existing secret. Keep the encryption key outside the public directory and in the recovery secret store.

Use the page's test-delivery action, then let cron process the outbox or run:

```bash
php artisan cms:outbox --limit=50 --seconds=45 --no-interaction
```

Confirm receipt and review **System health** for failures. “Queued” proves queueing, not delivery. Follow your provider's SPF, DKIM, DMARC, sender verification, and bounce instructions; automatic bounce ingestion is not implemented here.

**Settings → Email templates** (`/app/settings/email-templates`) edits versioned plain-text templates for invitations, verification/reset, inquiries, estimates, appointments, messages, invoices, receipts/refunds, and payslip notices. Use the editor's allowed placeholders. Content is snapshotted into queued actions so later edits do not change already queued messages.

Delivery is at-least-once: an interruption after SMTP accepts a message but before its outbox acknowledgement can produce a duplicate. Stable message identifiers help tracing but do not guarantee recipient-side deduplication.

## Notifications and background work

The Notifications workspace filters/sorts by status, category, priority, action, related record, and date. Server permissions determine what each account can read. Client notifications cannot reveal another customer's job; payroll notifications are restricted to authorized employees.

The `notifications` settings group supports `email_enabled` and `digest` (`instant`/`daily`) through the authorized settings API (`GET`/`PUT /api/v1/settings/notifications`). The current settings directory does not include a standalone notification-preferences page. These are installation-level email/digest settings, not an external push-notification integration.

`routes/console.php` schedules `cms:outbox --limit=50 --seconds=45` every minute. The processor records a heartbeat, uses durable leases, retries work, and creates daily digests. Use one configured scheduler per application storage/cache boundary. Do not install cron and an unnecessary second scheduler for the same instance.

## Stripe

Use the contractor's Stripe account. In **Settings → Payment gateways** (`/app/settings/payments`), set the enabled switch, secret key for the intended environment, and webhook signing secret. A publishable key can also be stored; the current flow redirects to Stripe-hosted Checkout created by the server.

Register this HTTPS endpoint:

```text
https://cms.example.com/api/v1/payments/webhooks/stripe
```

The implemented handler consumes:

```text
checkout.session.completed
checkout.session.async_payment_succeeded
charge.refunded
charge.dispute.created
charge.dispute.closed
```

Use the signing secret for the matching endpoint/environment. Preserve original request bytes and `Stripe-Signature` through proxies. Checkout returns do not mark invoices paid; verified status updates/reconciliation determine the ledger outcome. The CMS stores references and amounts, not card numbers.

## PayPal

On Payment gateways, configure the enabled switch, client ID, client secret, webhook ID, and mode (`sandbox`/`live`). Use credentials and webhook registration from the same account/environment:

```text
https://cms.example.com/api/v1/payments/webhooks/paypal
```

The implemented handler consumes:

```text
CHECKOUT.ORDER.APPROVED
PAYMENT.CAPTURE.COMPLETED
PAYMENT.CAPTURE.REFUNDED
PAYMENT.CAPTURE.REVERSED
```

The server verifies events with PayPal, captures approved orders, and distinguishes pending activity from settled payments. Preserve the PayPal transmission headers. The webhook ID identifies the webhook registration, not the client.

Before live collection, exercise sandbox checkout, capture/settlement, failures/pending payments, reconciliation, partial refunds, duplicate delivery, and out-of-order events with your actual accounts. Provider calls/signature handling have automated tests; live provider certification remains a release gate. Offline cash, cheque, and bank-transfer records need no gateway account, but finance staff must record and reconcile the real transaction.

## AI lead tools

Open **Settings → AI assistants** (`/app/settings/ai`). Set an OpenAI API key, a model available to that account supporting the Responses API and strict JSON-schema output, the enable switch, and daily limits. This release has one server-side provider adapter; another provider's key does not change that adapter.

| Setting | Allowed range/behavior |
|---|---|
| `enabled` | Tools remain unavailable until enabled with a key and model |
| `daily_request_limit` | Integer `1–1000` |
| `daily_token_limit` | Integer `1000–1000000`; conservative reservations can reject requests whose final usage might have been lower |
| `model` | Owner-selected model identifier, up to 100 characters |

Budgets reset by UTC day. Concurrent requests reserve usage transactionally; failed calls conservatively retain their reservation. These are request/token controls, not an exact dollar-spend cap. Configure provider-account spending limits and monitor costs separately.

The implemented tools are service-visit preparation, project-scope generation, and pasted-text quote comparison. They produce an organizing draft, then optionally verify the visitor's email before creating a CRM lead/report delivery. Report delivery, marketing consent, and requested follow-up are distinct. Tools cannot access private CRM, payroll, chat, payment operations, or arbitrary external URLs. Other trade tool names in configuration are future catalogue ideas, not enabled implementations.

No API key is sent to the browser. Provider requests set `store=false`; review the provider account's applicable data-handling terms separately. That setting is not a blanket claim about all provider retention. Test the configured model and failure handling before promoting tools publicly.

## Accounts, invitations, and MFA

`cms:install` creates the initial owner. Staff join through authorized invitations with permission presets; public registration always creates a client. Email verification alone does not link a client to historical customer records. Use the authorized customer portal invitation/approval flow.

Preset keys are `owner`, `office`, `dispatcher`, `estimator`, `technician`, `finance`, `payroll`, `marketing`, and `client`. Additional explicit permissions may be granted only by an authorized account. Resource checks still restrict assigned jobs, customer relationships, payroll, and conversations.

Production accounts with financial, payroll, settings, or team-management permissions must use TOTP MFA. Credential/privilege changes and sensitive actions can require recent password confirmation. Keep the server clock synchronized. This release has no documented self-service MFA recovery-code workflow; establish an access-controlled administrator recovery procedure before onboarding business-critical accounts. Do not disable production MFA to bypass normal setup.

## Firestore experimental configuration

Firestore is Google-hosted; application code, themes, and binary files remain self-hosted. It is not a local Firebase installation.

```dotenv
DATA_PROVIDER=firestore
FIRESTORE_EXPERIMENTAL=true
FIRESTORE_PROJECT_ID=YOUR_PROJECT_ID
FIRESTORE_DATABASE="(default)"
GOOGLE_APPLICATION_CREDENTIALS=/protected/path/service-account.json
FIRESTORE_EMULATOR_HOST=
SESSION_DRIVER=file
CACHE_STORE=file
```

Provision the database and restrict its service account according to your Google Cloud deployment. Keep the JSON credential outside `public/` and version control. The adapter uses HTTPS REST and Google authentication, without gRPC. A development emulator host is a hostname/port without URL scheme/path.

Do not run SQL migrations in this mode or use it as a live toggle on an existing SQL installation. The installer rejects Firestore unless experimental opt-in is enabled. Real contention, indexes, migrations, reports, quotas, and full workflows still require certification. Built-in backup/restore commands reject this mode. See [Release acceptance](RELEASE-ACCEPTANCE.md).

## Health and troubleshooting data

**Settings → System health** (`/app/settings/health`) displays selected database, PHP version, storage writability/free space, scheduler heartbeat, queue age/pending entries, failed outbox work, and whether mail/AI settings exist. “Configured” does not prove reachability or authentication. Combine it with external uptime/disk monitoring, logs, SMTP tests, and provider sandbox checks.

Use `storage/logs/laravel.log` and web/PHP logs for diagnosis. They may contain operational/personal information; restrict access, rotate, and redact before attaching to an issue. Never copy account tokens or test credentials into public reports.
