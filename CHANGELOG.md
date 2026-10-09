<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Changelog

## Unreleased

Security and reliability fixes from a code review of the alpha.

- Staff invitations no longer fail with a server error; invitations are rechecked against the inviter's current access, and a staff invitation can replace an unverified self-registered client account for the same address.
- MFA challenges lock after repeated invalid codes, and portal invitation acceptance requires the code for enrolled accounts.
- Trusted hosts and `TRUSTED_PROXIES` are resolved from configuration at request time, so they also apply to `.env`-file and config-cached installations.
- Refunds cannot exceed the money an invoice still holds; failed provider refunds release their reservation; PayPal/Stripe refund, reversal and foreign-event webhooks are handled idempotently; stale checkouts expire when the balance changes.
- Client users receive only customer-facing finance fields; `/api/v1/reports`, dashboard activity and the team directory follow role permissions; dashboard totals are kept per currency.
- The outbox processes only actionable events, so delivery history can no longer starve new mail; delivered message content is removed; a daily `cms:prune` deletes expired planning-tool data.
- MySQL/MariaDB use `READ COMMITTED` and binary-collated record keys; database exception messages mask bindings.
- Backups refuse to exceed the limits that restore enforces.
- The nginx example serves application-generated `/assets/` files, and the static `robots.txt` no longer hides the generated one.
- Theme CSS URL checks cover every `url()` and image function form; Twig reserved-word checks no longer reject ordinary page text; a theme that fails at render time falls back to the built-in theme; malformed layouts return validation errors.
- Removed assistant tooling files and packages from the development setup.

## 0.1.0-alpha.1 — 2026-10-09

Initial public development release of ContractorCMS.

- Rebranded the application, authentication screens, browser titles and package metadata as ContractorCMS.
- Included the contractor website, CRM, operations, client portal, team communications, invoice/payment and workforce workflows.
- Added guided homepage editing, dedicated website settings, local font assets, technical/local SEO, social metadata and citation observations.
- Included sandboxed ZIP themes with preview, activation and rollback, plus a distributable documented starter theme.
- Documented source/prebuilt installation, dependencies, configuration, hosting, recovery, application development and theme packaging.
- Added source checks for PostgreSQL/MySQL and frontend builds; retained the author attribution and third-party licenses.

This is an alpha development release. Firestore remains experimental. Live-provider acceptance, clean supported-host certification and independent penetration testing are outstanding; consult the release acceptance and validation documents for exact limits.
