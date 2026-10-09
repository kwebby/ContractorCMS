<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Implementation status

The repository contains a working modular development release. The table below records implemented functionality; production certification and remaining roadmap work are listed separately.

| Area | Implemented |
|---|---|
| Foundation | SQL/Firestore repository contract, transactional locks/sequences, optimistic revisions, indexed SQL lookups, opaque repository cursors, CLI installer/doctor, RBAC, client signup, staff/client invitations, MFA, audit, protected files |
| CRM and work | Leads, customer records, jobs, staff assignments, scheduling conflicts, technician notes/checklists/completion review, properties, appointments, versioned change orders, branch API, task Kanban with checklist progress, calendar with CRM notes and extension-record endpoints |
| Portal | Verified customer scoping, exact-revision estimate approval, own jobs/visits/change orders, conversations, invoice payments/downloads, receipts |
| Communications | Guest/client/job/internal conversations, reconnect cursors, duplicate protection, role-scoped notifications, SMTP settings, 11 versioned email templates, instant/daily delivery, durable retrying outbox |
| Publishing | BlockNote writing, draft/revision workflow, PHP rendering, 16 guided homepage section types and four presets, retained GrapesJS expert layouts, public pages, ZIP theme validation/preview/activation/rollback, built-in theme switching, capability checks and Workshop 1.1.0 starter |
| Website settings | Dedicated full-page editors, versioned shared draft/published appearance/navigation/profile settings, colors, typography/spacing, branding images, eight locally served Google Fonts families and revision restore |
| Local SEO | Canonical public profile, genuine branches, address privacy, regular/special hours, service areas, profile links, manual citation observations/comparisons, correction ownership, CSV NAP/citation exports and page-coverage diagnostics |
| Discovery | SEO inheritance, canonical/robots, redirects, sitemaps, reciprocal locales, schema presets/custom JSON, OG/X metadata, global SEO settings, internal-link and metadata diagnostics |
| Finance | Exact money, estimate snapshots, issued invoice numbering/snapshots, invoice designer, PDFs, offline payments, Stripe/PayPal adapters, signed webhooks, reconciliation, deposits/allocations/credits/refunds/reversals/disputes, receipts |
| Workforce | Compensation and approved timesheets, pay-run review/approval/publication, supplied tax/deduction inputs, employee-only immutable payslips, imports/exports |
| AI | Service preparation, project-scope and quote-comparison tools, structured output, disabled default, quotas/reservations, idempotency, contact verification, separate marketing consent |
| Operations | Local storage, protected backups/restores, cron heartbeat, failure health view, deployment templates, CI definition, prebuilt release packager |
| Verification | PHP feature/unit tests, real PostgreSQL/MySQL runs, parallel invoice/payment test, frontend tests/build/type checks, desktop/mobile browser checks and source security review |

## Website editing and publication

Website configuration uses dedicated pages with inline validation and save status, rather than popup settings forms:

- `/app/website/homepage`: section list and editor, local images, BlockNote writing, buttons, repeated items, ordering, visibility, draft preview and publication.
- `/app/website/appearance`: brand colors, logo/favicon, heading/body fonts, type scale, width, button radius and section spacing.
- `/app/website/navigation`: managed header/footer links, header action and client-portal visibility.
- `/app/website/local-seo`: diagnostics and local-business overview.
- `/app/website/local-seo/business-profile` and `/app/website/local-seo/locations`: canonical public identity and separate full-page branch editors.
- `/app/website/local-seo/citations` and `/app/website/local-seo/page-plan`: listing observations/correction tracking and content coverage.
- `/app/website/seo`: existing SEO defaults and diagnostics. Theme upload/configuration and general business, mail, payment, invoice and security settings also use dedicated pages.

The homepage library contains hero, trust, services, about, process, projects, reviews, service areas, FAQ, contact, offers, financing, maintenance, articles, lead tool and careers sections. Service-team, roofing/exterior, remodeling/project and multi-trade presets supply 8–10 ordered slots. Hero and contact are enabled initially; evidence-dependent sections remain disabled until the contractor supplies actual content. There are no prefilled customer testimonials, ratings, credentials or completed projects.

Homepage drafts use the existing page revision and publication lifecycle. Global appearance, navigation, public profile and branches share their own versioned settings draft; publishing that settings record publishes all its groups together. Both flows enforce optimistic versions, preserve the previous public snapshot on failed publication, support authorized previews and retain revision history. Restoring a revision creates a draft until published.

The eight-family local font catalog is Inter, Manrope, Montserrat, Source Sans 3, Open Sans, Work Sans, Lora and Merriweather. It includes WOFF2 assets, Latin/Latin Extended coverage, licenses and source/hash metadata. It is a curated library; arbitrary remote fonts and a full Google Fonts downloader are outside this increment.

The public profile is distinct from financial/legal business settings. Explicit address visibility controls the application-owned public projection used by contact/footer output, themes and structured data. Hidden address fields are excluded; changing billing details does not automatically publish them. Service-area cities are not represented as physical branches. Real branch pages can link to a location identity; regular and dated special hours belong to the relevant public profile.

The three AI planning pages share the published public profile, navigation, appearance and social defaults. The authenticated workspace receives only its required branding/currency fields; it does not serialize full billing settings into client page data.

Local SEO observations are **manual evidence**, with listing URL, observed name/address/phone/website, check date, provenance, comparison results, correction state and owner. Saving a listing URL does not verify it. Evidence becomes stale after a canonical revision change or its age threshold; incomplete observations remain unchecked. NAP and citation CSV exports are available, with spreadsheet formula-injection protection. No directory crawler, Google Business Profile synchronization or external ranking verification is claimed.

Workshop 1.1.0 remains on theme API 1 and declares `guided-homepage` and `managed-navigation`. Capability checks verify content/header/footer slots and prevent an additional H1/contact form around guided content. Activation, rollback, direct page publication and candidate theme-settings changes enforce compatibility. Legacy packages remain usable for expert layouts; the built-in theme is selectable and participates in rollback history. See [THEMES.md](THEMES.md) for the manifest, slot contract and CSS variables.

## CRM planning workspace

The task board at `/app/tasks` provides To do, In progress, Blocked and Done columns, drag/drop and selectable status changes, checklist completion bars, assignment, dates and notes. Controls remain available without drag-and-drop. The calendar at `/app/calendar` combines scheduled jobs/visits, lead follow-ups, task deadlines and dated internal CRM notes, with permission-scoped records and links back to their work. Requested and confirmed visits remain distinct. Existing visit scheduling remains available at `/app/schedule`.

## Deliberate limitations

- This is a development release. An independent manual penetration test and clean-host certification have not occurred.
- Real SMTP delivery, live/sandbox payment accounts, hosted Supabase, a live AI model and real Firestore need deployment credentials and acceptance tests. Local tests use safe provider mocks.
- Firestore is a genuine REST persistence adapter, but remains experimental. Its full filtered indexes/report projections, resumable migrations and portable backup/import require further work before certification.
- SQLite is a test convenience, not an advertised installation provider.
- Some lists still load complete collections for local filtering. Repository cursors/indexed lookups exist, but high-volume UI pagination and aggregate reporting need completion.
- Shared hosting uses polling and cron. Reverb/Horizon/Redis acceleration is not integrated in this release.
- External PDF uploads remain quarantined without a scanner. Generated invoices and payslips are available through protected document routes. Do not manually flip quarantine flags to bypass scanning.
- Site media becomes public at first publication; previously published assets remain public when their page is unpublished.
- Website schema options do not guarantee search rich results, rankings or AI citations. Analytics/tag-manager injection is not enabled; Search Console verification is supported.
- Employee tax amounts are imported/supplied; statutory calculations, filing and bank transfers are excluded.
- Trade-depth records are an initial extension surface. Recurring-maintenance engines, procurement/accounting integration, subcontractor coordination, dependencies, selections and progress billing remain later-phase work.
- The other brainstormed AI tools and private field-note/estimate drafting are not enabled yet.
- Theme settings use a constrained flat schema. Theme API compatibility is fixed to v1; uploaded code is not a general plugin system. Global navigation controls require a theme declaring and satisfying managed-navigation; arbitrary legacy templates can retain their own navigation.
- Local SEO diagnostics and manual observations do not certify off-site NAP consistency or publish updates to external directories. Page coverage counts describe CMS content, not search-engine indexing.
- The application uses its own accessible React controls rather than importing the complete shadcn/ui distribution.

## Production completion criteria

Use docs/RELEASE-ACCEPTANCE.md. In particular, do not relabel mocked provider tests as live gateway verification, REST contract tests as Firestore certification, or this implementation review as an independent penetration test.
