<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Local SEO pack and website appearance specification

Research date: 9 October 2026. Market: US and Canada. Status: researched product specification; these additions are not yet implemented. Read with the company observations and implementation blueprint in this directory.

## 1. What the Local SEO pack should do

Give the contractor one place to maintain their public identity, publish it consistently, and track corrections to external listings. NAP means business **name, address and phone**. Include website URL, hours, services and service areas because a matching phone alone does not establish an accurate listing.

Google describes local results in terms of relevance, distance and prominence/popularity. Accurate business information helps Google understand the business; neither a CMS setting nor a page-count target guarantees placement in the local pack. [Google local-ranking guidance](https://support.google.com/business/answer/7091?hl=en)

Recommended dedicated settings pages:

| Page | Main jobs |
|---|---|
| Website → Business profile | Public business name, primary phone, identity, service area and visibility |
| Website → Locations | Genuine branches, branch contacts/hours, associated landing pages |
| Website → Local SEO | Completeness, internal consistency, listing observations and correction tasks |
| Website → Page plan | Required content coverage, drafts and links to edit each page |
| Website → SEO & social | Existing title, canonical, schema, Open Graph and X settings |

Each editor is a full page with inline validation, save status and a clear publish action. Destructive actions can use an inline confirmation state. Everyday editing should not open popups.

## 2. Canonical business and branch data

These are proposed fields, rather than claims about competitors or search-engine requirements.

| Field group | Required controls |
|---|---|
| Identity | Public trading name; separate legal/billing name; logo; business description; real contractor specialties; stable business ID |
| Location type | Service-area business, customer-facing storefront, or hybrid; address visibility policy |
| Address | Structured street, unit, city, state/province, postal/ZIP code and country; preserve original formatting alongside normalized comparison values |
| Phone | Primary public number, international normalized number, extension, display format, approved alternate/tracking numbers, emergency number if real |
| Contact | Public email; contact page; booking/request page; primary domain and canonical HTTPS URL |
| Hours | Time zone, regular hours, split shifts, holiday exceptions, appointment-only status; distinguish office hours from emergency response availability |
| Service area | Real cities/regions/postal areas, parent region, associated content page, enabled services and responsible branch |
| Credentials | License type/number/jurisdiction, verification URL/date, expiry where applicable; supported insurance/certification statements |
| Profiles | Google profile URL/identifier if supplied; other map, directory and social profile URLs; owner verification status/date |
| Branch | Stable ID, real local name, branch-specific NAP/hours, parent company, dedicated URL, public address visibility |
| Governance | Who approved it, revision, publication date, next review date, reason for change |

Google expects the business name to reflect its real-world identity, not added keywords. A service-area business without a customer-facing location should hide its address on its Google profile; virtual offices do not qualify simply because an address can be rented. Branches must reflect genuine qualifying operations. These rules motivate a separate public profile rather than copying an invoice address into website settings. [Google representation guidelines](https://support.google.com/business/answer/3038177?hl=en)

Google currently permits up to 20 selected service areas and describes an approximately two-hour driving boundary as general guidance. Treat this as a **Google-profile export check**, not a hard cap on the CMS's geographic records or website pages. A city served is not automatically a physical branch. [Service-area guidance](https://support.google.com/business/answer/9157481?hl=en)

### Where the public profile is reused

- Header contact controls, footer, contact page and relevant location pages.
- Homepage service-area section and contact form routing.
- Business identity in JSON-LD and approved map/profile links.
- Theme public context and application-owned contact widgets.
- Exported citation correction sheet.

Private billing addresses remain available to authorized back-office roles. Hidden addresses and precise private coordinates must be absent from public HTML, metadata, schema, maps, JSON endpoints and image descriptions. Updating an invoice address must not silently change the published public profile.

### Comparing identity correctly

Normalize phone punctuation and country codes, URL scheme/trailing slash rules, case and whitespace. Retain address unit numbers and country/province distinctions. Recognize approved trading-name aliases and approved tracking numbers. Do not flag “Street” versus “St.” as a confirmed different location without further review. Classify ambiguous differences as needing review.

Distinguish these states: **consistent**, **difference detected**, **needs review**, **not checked**, **not applicable**, **fetch blocked**. A blank address can be correct for a service-area business. A company switchboard can differ legitimately from a branch phone. An old office address is a substantive problem.

## 3. Citation and listing workflow

Start with manually maintained observations and evidence links. The research does not establish the sampled companies' consistency across Google Maps or directories. A website-only check cannot certify external NAP consistency.

1. Approve canonical public details for the business or branch.
2. Add relevant listing URLs. Prioritize the actual Google profile, Apple Maps, Bing, and directories or trade organizations the business already uses.
3. Record observed name/address/phone/URL/hours, evidence URL, date, checker and method. Distinguish owner-entered data from connector-confirmed data.
4. Compare with the published canonical version. Show the values side by side and the exact differing fields.
5. Assign a correction task to an owner. Track submitted, awaiting review, corrected and rechecked separately.
6. Export the approved details and outstanding corrections as CSV. Website publishing does not imply an external listing was updated.

Apple's current business portal exposes map identity management, including logo, hours, imagery and business details. Keep provider labels and links configurable because platforms change. [Apple Business](https://business.apple.com/)

Proposed observation record:

```text
location_id, provider, listing_url, canonical_revision,
observed_name, observed_address, observed_phone, observed_website, observed_hours,
checked_at, checked_by, method, evidence_reference,
comparison_status, differing_fields, correction_owner, correction_status, notes
```

Future connector-based checks need supported APIs, credentials, rate limits and provenance. A crawler must use a provider allowlist, block private/loopback addresses, revalidate redirects, and limit response sizes. Do not add unrestricted server-side URL fetching to a settings field.

## 4. Page templates in the local content pack

| Template | Editable content that makes the page useful | Linking |
|---|---|---|
| Service overview | Actual specialties, customer type, relevant credentials, request CTA | Service details and coverage hub |
| Service detail | Scope, exclusions, process, equipment/material options, local project evidence, useful questions, pricing policy if approved | Related services, appropriate locations and request form |
| Service-area hub | Real coverage grouped by geography, availability, boundaries and responsible office | Approved area pages and services |
| Service-area detail | Work genuinely offered there, original nearby project/example, access or planning details the contractor can substantiate, local contact routing | Relevant services and actual branch, when one exists |
| Physical branch | Branch NAP, visit policy, hours/holiday exceptions, team, real directions and parking/access information | Local services and company identity |
| Project/case study | Customer-approved images, broad location, work type, challenge, scope, completed work, dates and outcome | Service and service-area pages |
| About/team | Business history, real people, verified qualifications and accountable authors | Contact, projects and careers |
| Contact/request | Public profile, response expectations, accessible request form, consent choices | Portal access and service coverage |
| Reviews | Permission/source, accurate attribution and date; no manufactured ratings | Original review source and request form |
| FAQ/help | Answers reflecting actual company policies, reviewed dates and next step | Detailed services or contact |

A local page should have a reason to exist for the visitor. The CMS should create drafts from templates and require real supporting information before publication. Do not automatically publish every service × city permutation. Google's doorway-abuse policy specifically addresses substantially similar location-targeted pages that funnel visitors elsewhere. [Google spam policies](https://developers.google.com/search/docs/essentials/spam-policies#doorway-abuse)

## 5. Schema, SEO, GEO and social controls

Use one application-owned graph per page with stable business, branch, site and page IDs. A page's content determines its schema. The theme cannot replace the canonical identity or inject a second conflicting business graph.

| Content | Proposed schema behavior |
|---|---|
| Homepage | WebSite, WebPage and truthful Organization/contractor business entity |
| Service | Service with genuine provider and areaServed; BreadcrumbList |
| Physical branch | Appropriate LocalBusiness subtype, actual public address/phone/hours and branch URL |
| Service-area page | Service + areaServed; no invented office/address |
| About/contact | AboutPage or ContactPage referencing canonical company identity |
| Project/article | Article or BlogPosting when appropriate, author, images and dates |
| Collection | CollectionPage; ItemList only for visible listed items |
| FAQ | Optional semantic FAQPage for visible Q&A; no Google rich-result promise |
| Real vacancy | JobPosting with accurate open/closed dates and actual employment details |

Google's LocalBusiness rich-result documentation requires name and address and recommends the most specific applicable subtype. Hidden-address businesses should retain truthful, privacy-preserving semantic data and receive an eligibility explanation; the CMS must never invent or expose an address merely to satisfy a validator. [LocalBusiness documentation](https://developers.google.com/search/docs/appearance/structured-data/local-business)

Own-business testimonials may be shown accurately, but self-serving Organization/LocalBusiness review markup does not earn Google's review stars, including when the reviews come through an embedded widget. Default automatic aggregate-rating output should therefore remain disabled. [Review-snippet policies](https://developers.google.com/search/docs/appearance/structured-data/review-snippet#self-serving-reviews)

**Current change from older SEO advice:** Google ended FAQ rich results from 7 May 2026 and removed the associated documentation in June. Retain FAQ content for visitors and optional schema semantics, without presenting it as an available Google search enhancement. [Google documentation updates](https://developers.google.com/search/updates#may-2026)

Recommended controls also include titles/descriptions, canonical, indexability, redirects, truthful modification dates, XML sitemap inclusion, locale/hreflang for actual translations, social image/title/description/alt text and page-type defaults. Published pages must remain server-rendered and linked through ordinary navigation. Private portals, invoices, payroll and personalized lead-tool reports remain authenticated or otherwise protected and excluded from public indexing.

For generative search, prioritize original job evidence, clear company facts, accountable authors and useful service explanations. Google's guidance does not require special AI schema or llms.txt. Preserve real business/profile data and ordinary technical SEO rather than offering a synthetic “AI ranking score.” [Google generative-search guidance](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide)

## 6. Local SEO diagnostics and measurement

Recommended checks should explain what failed and link directly to its dedicated editor:

- Inconsistent company/branch name or primary phone between visible pages and JSON-LD.
- Private address exposure; missing branch context; service area incorrectly modeled as an office.
- Outdated hours or expired owner-entered credential evidence.
- Missing/invalid profile URLs, unreviewed external observations and unresolved correction tasks.
- Missing service or contact information; empty local pages; orphan pages and broken local links.
- Canonical/sitemap disagreement, redirected URLs in navigation and duplicate metadata.
- Schema that describes unpublished or invisible claims.

Use actionable counts rather than a claimed Google ranking score. Track form starts/submissions, call-link clicks, lead-tool completions, qualified leads, booked jobs and collected invoices by landing page/source. A phone-link click is not a verified phone call. Separate observed attribution from unknown/cross-device outcomes. Actual local-pack position monitoring is a separate, location-dependent research feature and is not delivered by these checks.

## 7. Appearance and media settings

All controls belong to dedicated Website settings pages with preview, revision history and draft/publish states.

| Group | Controls |
|---|---|
| Brand assets | Light/dark logo, favicon, default social image, logo size and accessible name |
| Palette | Primary/accent/background/surface/text/muted/border; button text and background pairs; contrast feedback |
| Typography | Heading/body family, approved weights, size scale, line height, limited letter spacing, fallbacks |
| Layout | Standard/wide content width, section spacing, image aspect ratio, card density, button radius |
| Header | Menu, primary CTA, phone, optional emergency notice, sticky behavior, mobile menu |
| Footer | Canonical public profile, approved navigation, hours, social links, policy links, copyright |
| Section content | Enable/order, layout variant, heading, eyebrow, BlockNote text, images, cards, buttons and linked records |
| Images | Local media selector/upload, alternative text or decorative status, caption, focal point, mobile crop, rights/source note |
| Calls to action | Label, internal page/form/phone/email/approved HTTPS destination, primary/secondary style, analytics identifier |
| Forms | Approved field set, lead source, assigned team, response expectation, separate marketing opt-in |
| Social | Global OG/X defaults and per-page overrides with preview |

Product constraints: avoid arbitrary CSS/JavaScript inputs; preserve visible focus and mobile readability; use accessible semantic headings; do not bake headings into images. Color warnings should identify the affected text/background pair. The section editor should support keyboard move-up/down as well as drag-and-drop.

Use responsive local image derivatives with dimensions, appropriate loading priority and lazy-loading below the first screen. Keep the hero static by default. Team/truck/project photography should be provided or licensed by the contractor; competitor imagery is inspiration evidence, not reusable site content. Hide unused empty sections instead of publishing placeholder claims.

## 8. Google Fonts library

**Baseline recommendation:** a searchable collection of eight bundled Google Fonts families, served locally. **Later extension:** full catalog metadata refresh and installation of selected families through a bounded, approved downloader. Public pages should request fonts from the CMS server. This matches the existing self-only font/style security policy and the user's self-hosting requirement.

For that later extension, the official Developer API exposes family, variants, subsets, versions, file references and optional axes, and requires an API key. Cache a release-pinned catalog and optionally refresh server-side with configured credentials; websites should continue working when catalog refresh is unavailable. [Google Fonts Developer API](https://developers.google.com/fonts/docs/developer_api)

Proposed starter collection, with each family's official metadata verified on the research date:

| Family | Suggested use | Official metadata |
|---|---|---|
| Inter | Neutral service-business interface and body copy | [Inter](https://raw.githubusercontent.com/google/fonts/main/ofl/inter/METADATA.pb) |
| Manrope | Clear contemporary headings | [Manrope](https://raw.githubusercontent.com/google/fonts/main/ofl/manrope/METADATA.pb) |
| Montserrat | Strong display headings for trade brands | [Montserrat](https://raw.githubusercontent.com/google/fonts/main/ofl/montserrat/METADATA.pb) |
| Source Sans 3 | Readable body and longer service descriptions | [Source Sans 3](https://raw.githubusercontent.com/google/fonts/main/ofl/sourcesans3/METADATA.pb) |
| Open Sans | Familiar general-purpose body option | [Open Sans](https://raw.githubusercontent.com/google/fonts/main/ofl/opensans/METADATA.pb) |
| Work Sans | Compact service-business typography | [Work Sans](https://raw.githubusercontent.com/google/fonts/main/ofl/worksans/METADATA.pb) |
| Lora | Remodeler/design-build editorial headings | [Lora](https://raw.githubusercontent.com/google/fonts/main/ofl/lora/METADATA.pb) |
| Merriweather | Traditional editorial heading option | [Merriweather](https://raw.githubusercontent.com/google/fonts/main/ofl/merriweather/METADATA.pb) |

These usage suggestions are design judgments, not measured conversion results. Suggested pairs: Manrope + Inter for service trades; Montserrat + Source Sans 3 for roofing; Lora + Work Sans for remodeling. A single family is also sufficient.

Catalog UX: search, category/language filters, sample text, live heading/body preview, installed state, available weights, file-size estimate and license link. Maximum two active families by default; load only selected weights/subsets. Provide system-font fallbacks and French-language glyph coverage where needed for Canada.

The Google Fonts repository permits self-hosting subject to each font's license. Preserve font copyright/license files and source/version/hash in the release package; the requested CMS author watermark must never replace third-party attribution. [Official Google Fonts repository](https://github.com/google/fonts#self-host-fonts-available-from-google-fonts)

Use WOFF2 assets and `font-display: swap`, with a restrained preload policy. The CSS API supports variable ranges and advises precise style requests to avoid downloading unused data. A remote Google CSS mode could be optional later, with explicit network/privacy behavior and tightly scoped CSP changes; it is unnecessary for the baseline. [Google Fonts CSS API](https://developers.google.com/fonts/docs/css2)

## 9. Acceptance checklist for implementation

1. Editing the canonical public phone changes footer, contact widget and schema together after publishing.
2. A home-based contractor's hidden address never leaks into public output or theme context.
3. Branch and service-area pages remain distinct; no fake branch is generated for a city served.
4. An unchecked external listing is never labeled verified or synchronized.
5. Citation differences preserve observed values, evidence date and canonical revision.
6. Homepage edits, appearance and navigation support preview, stale-edit detection and rollback.
7. Sections support image/text/heading/button editing, ordering and disabling without code edits.
8. Default and compatible uploaded themes render the same approved content while preserving application SEO; legacy themes disclose unsupported managed-content/navigation capabilities before publication.
9. Font choice persists, local WOFF2 loads under current CSP, and licenses ship with the release.
10. FAQ/review settings distinguish valid semantics from Google search-feature eligibility.
11. Local SEO diagnostics link to specific fixes and never claim guaranteed rankings.
12. Forms retain source metadata and separate service-request consent from optional marketing consent.
13. Displayed phone digits match their intended click-to-call destination, accounting for approved alternate/tracking numbers.
14. A published public-profile revision marks prior citation comparisons stale until rechecked; original observations remain preserved.
15. CSV exports neutralize formula-prefixed cells without changing the underlying observation record.
