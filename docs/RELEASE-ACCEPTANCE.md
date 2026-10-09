<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Release acceptance

The repository is an implemented development build. Passing automated checks is evidence of the covered behavior, not independent penetration-test or host certification.

## Automated evidence

Run the PHP feature/unit suite and the separate SQL concurrency test on dedicated PostgreSQL and MySQL databases. Run TypeScript, Vitest, production build and Playwright checks. Dependency audits must pass or be explicitly remediated. See docs/VALIDATION.md for the actual last run.

Coverage includes identity boundaries, portal linking, role/resource permissions, client-safe projections, MFA/session changes, scheduling conflicts, estimate revisions, invoice numbering/immutability, payment retries/signatures/refunds, payslip privacy, document PDFs, theme archive attacks, content XSS, SEO inheritance/redirects/schema, outbox leases/retries, encrypted backup roundtrip, AI budgets/consent/provider failures and Firestore REST contracts.

## Required external acceptance before production

- Install clean Ubuntu 24.04, Ubuntu 26.04 and CentOS Stream 10 profiles with documented PHP packages.
- Install an eligible shared host without Node, Redis or persistent workers; verify CLI/web parity and private document root.
- Execute the supplied Docker profile and MySQL 8.4 CI matrix; the development machine used MySQL 9.7.
- Exercise a real hosted Supabase PostgreSQL connection with TLS and credential rotation.
- Test Stripe/PayPal sandbox checkout, settlement, refunds, duplicated/out-of-order events, provider downtime and reconciliation using the contractor's accounts.
- Verify actual SMTP delivery, bounce handling, invitation/reset links and provider DNS setup.
- Restore an installation's full database/files on an independent machine with the retained key.
- Run an independent manual penetration test against production-like staging with owner, office, technician, payroll and two unrelated client accounts.
- Resolve and retest all Critical/High findings against an OWASP ASVS Level 2 verification plan.
- Validate workflows with operating contractors and check jurisdiction-specific invoice/payslip content with the business's advisors.

## Firestore release gate

Keep Firestore experimental until the whole application passes without SQL configured against real Firestore, including indexes, transaction/contention behavior, uniqueness, provider auth, migration resumption, reporting projections, export/import, quotas and backup restoration.

## Remaining product depth

Reverb/WebSocket acceleration, Redis/Horizon profiles, automatic route optimization, recurring maintenance generation, inventory/procurement accounting, subcontractor workflows, remodel dependencies/selections and progress billing are later-phase work. The core uses polling/manual scheduling and generic extension records. Trade-specific AI pages beyond the initial three require reviewed input/output rules and validation before enablement.

No ranking, AI citation, payroll compliance, payment availability, or complete repair diagnosis is promised by this implementation.
