<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Architecture

One installation owns one company. Users, customer accounts, jobs and documents share the selected authoritative database. Branch records do not establish tenant isolation; do not host unrelated businesses in one installation.

Modules are separated into Identity, Publishing, Finance, Workforce, Communications, Automation, Operations and Infrastructure. Controllers validate input and authorize actions; application services implement state transitions; RecordRepository supplies transactions and persistence. Inertia and versioned JSON endpoints use the same services.

## Persistence

SqlRecordRepository stores versioned records in cms_records. Business payloads are JSON, while IDs, versions, timestamps and finite lookup projections are explicit columns. Indexed lookups cover email, customer, recipient, invoice, conversation, status, type and employee. cms_locks supplies database row locks and atomic numbering. This is an initial portable record implementation, not a normalized analytics warehouse.

FirestoreRecordRepository implements the same contract over REST with service-account authentication, native transactions, buffered writes, optimistic preconditions, transaction retries and guard documents. Tests execute identity and invoice workflows without a SQL connection. Production Firestore certification, fully indexed filtered queries/report projections, resumable migrations and portable restore remain open.

The repository offers opaque query-bound cursor pagination. SQL pages use indexed projections and bounded reads. Firestore pagination currently uses a collection scan before slicing. Some workspace views still load small complete collections for local filtering. Large-business adoption requires replacing those views with cursor UI and indexed aggregates; do not claim unbounded scale.

Every durable business record, role grant, setting, notification, message, audit and outbox event uses the chosen repository. Binary uploads/PDFs/themes are local; their metadata and relationships are records. File sessions/cache are replaceable runtime state, not an alternative business database.

## Identity and authorization

The owner is created once by cms:install. Staff use invitation tokens. Public registration always creates a fresh client customer/account; it never matches historical records by email. Existing customer access requires an office-issued portal invitation and, for an existing login, the account's password.

Preset permissions are augmented by explicit grants. Permissions and record relationships are checked server-side. Verified clients access their customer records; technicians access assigned work; employees access only their own published payslips. Public record projections remove internal notes, labor costs and private staff fields.

Financial/payroll/administrator permission holders must enroll MFA when the production setting is enabled. Sensitive actions require recent password confirmation. Access changes/reset operations invalidate sessions by version. TOTP replay and expired challenge protection are tested.

## Financial state

Money uses integer minor units and exact quantity/rate arithmetic. Estimate acceptance binds the current revision and creates a job once. Invoice issuance locks the document, allocates its number atomically, and snapshots business/customer/items/template. Payment allocations, credit notes, refunds, reversals and disputes are separate records.

Invoice balance is derived from settled records. Pending provider attempts do not count as paid. Gateway webhooks are verified and deduplicated. A checkout return never changes settlement. Issued PDFs and payslips reference immutable snapshots; staff may not edit issued financial facts in place.

Payroll stores approved compensation/time and supplied/imported taxes and deductions. It produces private slips and exports; it does not calculate statutory obligations, file taxes, or initiate salary bank transfers.

## Publishing boundary

BlockNote JSON is canonical writing content. PHP renders a supported subset with bounded nesting/content and escaped text/URLs. GrapesJS JSON is canonical page-layout state. Application-owned components/widgets render the supported layout set; unsafe code, unsupported constructs and unsanitized HTML are rejected.

Uploaded theme ZIPs are quarantined, validated and versioned before preview/activation. Twig receives primitive public context only. There are no executable theme installers or arbitrary scripts. Theme activation changes a version pointer transactionally; rollback restores the prior version.

The CMS owns the HTML head, canonical, robots, sitemap, language alternates, OG/X metadata and one JSON-LD graph. Theme changes cannot override these. Drafts and personalized/private records require authorization and are excluded from indexing.

## Communications and automation

Messages persist before clients see them. Staff/client/job conversations have fixed types and explicit membership. Anonymous chats require the matching server session and expire; the browser stores only a conversation ID, not authorization.

Outbox events commit with the business transaction. Workers lease events, retry with bounded backoff and stop at five failed attempts. Shared hosting invokes bounded processing via cron. Leases permit recovery after crashes; notification IDs and financial event keys prevent duplicate business effects.

AI public tools have no access to private application repositories in their model context. Validated summaries are structured, input/output bounded, budget reserved transactionally, and requests deduplicated. Report delivery consent and marketing consent are separate. Email confirmation is a POST action so email link scanners do not silently create verified leads.
