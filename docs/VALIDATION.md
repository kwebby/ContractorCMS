<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Validation evidence

Validated locally on 2026-10-09. This records observed results, not production certification.

| Check | Result |
|---|---|
| PHP unit/feature suite, PostgreSQL 17.11 | 226 passed, 1,584 assertions |
| Same PHP suite, MySQL 9.7.1 | 226 passed, 1,584 assertions |
| Separate parallel SQL invoice/payment test | Passed on PostgreSQL and MySQL; 11 assertions per run |
| TypeScript | Passed |
| Frontend component/API tests | 42 passed |
| Playwright Chromium | 10 passed across workspace and website suites |
| Production Vite build | Passed; editor bundles lazy-loaded |
| PHP formatting | Pint --dirty --format agent applied |
| Frontend formatting | Prettier check passed during frontend verification |
| Composer validate | Passed |
| Composer audit | No advisories or abandoned packages reported |
| npm production audit | No vulnerabilities reported |
| Installer/doctor | Capability checks and one-time owner-bootstrap test passed |
| Prebuilt release smoke | Clean extraction, fresh PostgreSQL install, owner login, authenticated dashboard and CSP passed without Node or dev dependencies; archive checked for excluded secrets/data |
| Backup restore | AES-256 roundtrip, symlink rejection and session invalidation regressions passed |

PHP runtime was 8.5.9 on macOS; Composer resolves against the deployment minimum PHP 8.4.1. Node build runtime was 22.23.2. PHP 8.4 and Linux/shared-host profiles still require their clean-host acceptance runs. MySQL 8.4 is defined in CI but was not available locally.

The seven workspace browser tests exercise owner navigation and invoices/PDF links, BlockNote, GrapesJS, mobile layout, client restrictions, anonymous chat with office reply, office appointment confirmation, exact-revision client change-order approval, ZIP theme upload/configuration, dedicated settings, task Kanban progress and dated CRM notes.

Three new website browser workflows exercise guided section editing, BlockNote writing, image uploads/alt/focal positions, anonymous draft-image denial and public access after publication, draft previews/noindex, publication/reordering/revision restore, local fonts and colors, header navigation, private-address exclusion, manual citation records, and nine direct settings pages at 390px without overflow or dialogs. They restore the original demo snapshots and theme; a protected temporary recovery snapshot supports interrupted-test cleanup. Clearly labeled synthetic citation evidence remains unverified. Screenshots in output/playwright/homepage-editor.png, homepage-public.png and website-settings-mobile.png were visually reviewed; the editor capture waits for BlockNote to finish loading.

New PHP tests cover website snapshot/version conflicts, publish permissions, image promotion, font file integrity/licenses, managed URLs following published slug changes, branch schema/identity/privacy, real lead capture, theme capability/slot/settings validation and built-in rollback. Local SEO tests cover manual provenance, stale evidence, normalization, formula-safe CSV, branch mapping and visible content references.

Cross-database runs identified and fixed MySQL object-key-order differences in canonical addresses, evidence comparisons and website dirty-state detection. Regression tests preserve ordered navigation/service-area lists while ignoring associative-key order. Public-profile privacy is also verified across all three AI planning pages, and the authenticated workspace shell exposes only its branding/currency fields instead of the complete billing settings record.

Database-backed tests cover both relational adapters. Firestore uses mocked REST contract tests and deliberately runs business workflows with no SQL connection; this does not certify real Firestore behavior or quotas. Gateway signatures and status handling are mocked; no external payments, AI requests or production emails were sent.

Use dedicated databases when reproducing tests. The integration concurrency test performs migrate:fresh and refuses a database name that does not contain "test". Never run it against business data.

## ContractorCMS publication checks

The rebrand was followed by both complete SQL suites (226 tests each), 42 frontend tests, TypeScript checking, a rebuilt frontend, and a fresh desktop/mobile browser smoke. The documented six-file starter theme is now tested by creating its ZIP, uploading and activating it, publishing a service page and expert/guided homepages, checking the owned content/navigation/form slots, and serving its CSS. The source snapshot intended for GitHub was scanned with Gitleaks; no secrets were reported. Composer and npm production audits also passed. These checks do not replace the independent production release gates above.
