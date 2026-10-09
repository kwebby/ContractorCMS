<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Contractor website settings: implementation blueprint

Status: research-backed implementation proposal, not an implemented feature. Prepared 2026-10-09 from a read-only audit of the current CMS. The accompanying business research and synthesis determine the recommended section counts, order, and trade presets; the section IDs below are a provisional implementation vocabulary.

## 1. Product outcome

An owner or authorized content editor can configure a complete contractor homepage through dedicated settings pages: edit images, headings, writing, buttons, and repeated items; choose which sections appear and their order; configure global colors and typography; preview a draft; and publish a consistent public website. The same verified public business identity feeds the website, structured data, and a Local SEO consistency checklist.

This adds a guided editor for common contractor content while retaining BlockNote for writing, GrapesJS for expert page layouts, and sandboxed ZIP themes. Editing uses full pages with inline validation and save status. Do not introduce popup settings editors.

## 2. Existing architecture and integration points

Paths below are relative to the repository root.

| Concern | Current source | Existing behavior and integration decision |
|---|---|---|
| Public page and default homepage | [`resources/views/public/page.blade.php`](../../../resources/views/public/page.blade.php) | Head metadata is application-owned. Hero, services, process, navigation, contact, tools, and footer contain hardcoded content or layout. Replace homepage body composition with validated sections while retaining the owned head. |
| Page records and publication | [`app/Publishing/PublishingService.php`](../../../app/Publishing/PublishingService.php) | Pages already have drafts, optimistic versions, revisions, published snapshots, slug redirects, and transactional media promotion. Extend this lifecycle rather than introducing an independent homepage publication system. |
| Rendering orchestration | [`app/Http/Controllers/PublishingController.php`](../../../app/Http/Controllers/PublishingController.php) | `render()` combines business data, metadata, BlockNote, GrapesJS, and the selected theme. Insert section rendering here with explicit preview/published inputs. |
| Layout designer | [`resources/js/components/LayoutDesigner.tsx`](../../../resources/js/components/LayoutDesigner.tsx), [`app/Publishing/LayoutRenderer.php`](../../../app/Publishing/LayoutRenderer.php) | GrapesJS project JSON is sanitized server-side; only registered content/contact widgets exist. Keep expert layout mode separate from guided sections. |
| Flat theme settings | [`app/Publishing/ThemeSettings.php`](../../../app/Publishing/ThemeSettings.php) | Supports bounded scalar fields and hex colors. Do not overload this with arbitrary nested section data. |
| Theme sandbox | [`app/Publishing/ThemeManager.php`](../../../app/Publishing/ThemeManager.php) | Twig receives primitive public values. Sanitized templates must output `page.content` exactly once. Application content is inserted after sanitization. Use this safe insertion boundary for homepage sections. |
| Authoring UI | [`resources/js/sections/Publishing.tsx`](../../../resources/js/sections/Publishing.tsx) | Existing full-page SEO and theme settings patterns can be reused, including inline errors and saved notices. Page editor already handles revisions and publication. |
| Workspace routes | [`app/Http/Controllers/WorkspaceController.php`](../../../app/Http/Controllers/WorkspaceController.php), [`routes/web.php`](../../../routes/web.php) | Extend the dedicated website route family and `publishingPage` dispatch. |
| Business identity | [`app/Http/Controllers/SettingsController.php`](../../../app/Http/Controllers/SettingsController.php), [`app/Support/Settings.php`](../../../app/Support/Settings.php) | `settings.business` holds company identity and finance-related details together. Introduce a public projection; never pass the complete record into public templates. |
| SEO and diagnostics | [`app/Publishing/SeoBuilder.php`](../../../app/Publishing/SeoBuilder.php), [`routes/publishing.php`](../../../routes/publishing.php) | Stable business/site/page IDs, social metadata, robots, canonicals, sitemap and basic diagnostics exist. Extend them from the same public identity source. |
| Files | [`app/Http/Controllers/FilesController.php`](../../../app/Http/Controllers/FilesController.php) | Local raster images are decoded/re-encoded; draft assets require publishing permission; private job files cannot become public page images. Reuse these boundaries. |
| Permissions | [`app/Support/Access.php`](../../../app/Support/Access.php) | Marketing has `publishing.manage`; sensitive company settings require broader settings access. Maintain a distinction between public content editing and legal/billing identity changes. |
| Persistence | [`app/Contracts/RecordRepository.php`](../../../app/Contracts/RecordRepository.php) | Generic versioned records support transactions and locks. Extend records through this interface; avoid SQL-specific persistence in the new application services. |
| Public typography and CSP | [`resources/views/public/site.css`](../../../resources/views/public/site.css), [`app/Http/Middleware/CmsSecurity.php`](../../../app/Http/Middleware/CmsSecurity.php) | Public fonts are hardcoded system/Georgia stacks; production styles and fonts use self-only CSP origins. A local font catalog fits the existing security model. |

## 3. Dedicated settings pages and permissions

Proposed browser routes:

- `/app/website/homepage`: ordered section list, field editor, upload controls, draft status, preview and publish.
- `/app/website/appearance`: color tokens, heading/body fonts, typography scale, button style, spacing, logo and favicon.
- `/app/website/navigation`: header and footer links, primary CTA, utility information, social links and mobile navigation behavior.
- `/app/website/local-seo`: public business identity, real locations, service areas, hours, profile links, NAP checklist and observation history.
- `/app/website/local-seo/business-profile`: dedicated editor for the authorized public-profile draft.
- `/app/website/local-seo/locations` and `/app/website/local-seo/locations/{id}`: real-location list and full-page detail editor.
- `/app/website/local-seo/page-plan`: coverage checklist with links to relevant content drafts.
- `/app/website/seo`: retain the existing metadata defaults, verification and diagnostic page; link to the Local SEO page.

The existing `/app/settings/business` remains the privileged source for legal identity, billing address, tax identifiers, currency and timezone. A content editor can edit authorized public presentation without gaining permission to alter financial identity or expose a private address. If a canonical identity edit requires `settings.manage`, show a link to that existing page rather than copying a privileged form into the publishing API.

Settings forms must preserve unsaved values on validation failure and show field-specific errors. Use a persistent form footer or ordinary page actions for Save draft, Preview and Publish. Reordering must work with keyboard-accessible move controls as well as drag-and-drop. Switching section editors must not silently discard changes.

## 4. Reuse page publication for homepage sections

Add `home_sections` to the existing page record, permitted for `type: home`. Keep the existing BlockNote `content`, SEO, translations and revisions. The JSON below is conceptual and should be implemented as explicit validated types, not an unrestricted object.

```ts
type HomeSectionsV1 = {
  schema_version: 1;
  mode: "guided" | "expert";
  sections: HomepageSection[];
};

type HomepageSection = {
  id: string;                 // stable ID; unique within the page
  type: SectionType;
  enabled: boolean;
  variant: AllowedVariant;    // allowlisted for the chosen section type
  eyebrow?: string;
  heading?: string;
  body?: ContentDocument;    // existing supported BlockNote format
  image?: SectionImage;
  buttons?: SectionButton[];
  items?: TypedSectionItems; // schema is selected by section.type
};

type SectionImage = {
  media_id: string;           // ready local publishing raster image
  alt: string;
  decorative: boolean;
  focal_x: number;            // bounded 0–100
  focal_y: number;            // bounded 0–100
};

type SectionButton = {
  label: string;
  destination:
    | { kind: "page"; page_id: string }
    | { kind: "anchor"; anchor: AllowedAnchor }
    | { kind: "phone"; location_id?: string }
    | { kind: "url"; url: string };
  style: "primary" | "secondary" | "text";
};
```

Page references should resolve to current published URLs so later slug changes do not leave managed buttons stale. External URLs need the existing safe-scheme validation; reject executable, protocol-relative and unsupported links. Phone buttons should resolve from canonical public phone data rather than introducing another independently editable number.

Initial bounds: at most 24 sections, 2 buttons per section, 12 normal cards per section, 8 FAQs, and no arbitrary HTML/CSS/script inputs. Use shorter per-field limits for labels and headings, and an overall serialized document limit. Bound table/list nesting through the existing BlockNote renderer. Bounds can evolve through an explicit schema version rather than silently accepting new shapes.

Use a tagged/discriminated union for repeated content. A review item, project item and FAQ item must not share an unrestricted generic object. Reject unknown fields and duplicate section IDs. Require one visible hero/H1 in guided mode. Use generated section IDs for anchor targets rather than unrestricted DOM IDs.

### Provisional section vocabulary

| ID | Editable/referenced content | Publication requirement |
|---|---|---|
| `hero` | Eyebrow, one H1, introduction, image and up to two CTAs | Exactly one visible hero in guided mode; image is optional. |
| `trust` | Verified credentials, membership/badge image, factual label and supporting link | No invented awards, licenses, customer counts or rating claims. |
| `services` | Heading, introduction and service page references with image/description overrides | Managed links resolve only to published services. |
| `about` | Company introduction, image, factual differentiators and about-page CTA | Keep names and contact identity linked to canonical business data. |
| `process` | Ordered steps with headings, concise text and optional image | Describe the contractor's actual workflow. |
| `projects` | Case-study references, project images, summary, location and CTA | Use permissioned original work; do not imply client endorsement. |
| `reviews` | Attributed testimonial text, source link and permission/provenance fields | Visible testimony is editorial content; do not automatically manufacture aggregate-rating schema. |
| `service_areas` | Heading, served places, relevant page references and optional local image | Distinguish served cities from physical branch addresses. |
| `faq` | Question/answer repeaters | Render the same visible answers used by any applicable FAQ markup. |
| `contact` | Heading, introduction, canonical contact details and application-owned request form | Retain anti-abuse controls and CRM lead attribution. |
| `offers` | Real offer details, eligibility, dates and destination | Hide expired offers; avoid claims unsupported by an actual offer. |
| `financing` | Factual availability, provider link, terms summary and CTA | Do not imply approval or invent rates and payment examples. |
| `maintenance` | Real agreement options, inclusions, frequency and CTA | Enable only when the company provides these services. |
| `articles` | Selected published articles, heading and collection link | Do not link private drafts. |
| `lead_tool` | Registered application tool, introduction and CTA | Respect provider configuration and disabled/unavailable state. |
| `careers` | Genuine vacancies or careers-page reference | Do not publish fabricated vacancies or JobPosting data. |

The research synthesis determines recommended defaults, section count and trade variations. Optional sections should remain disabled until the business has suitable content; an empty form must not generate fictional public proof.

### Save and publish behavior

1. Save a versioned page draft with the same `expected_version` mechanism already used by `PublishingService`.
2. Validate sections, managed links and media relationships on save and again on publish.
3. Preview drafts through the existing authorized, non-indexable preview flow.
4. During publication, atomically snapshot sections and promote their draft images alongside existing content/layout/social media.
5. Extend revision restore to include `home_sections`; restore creates a draft until explicitly published.
6. Continue to serve the previous published snapshot during edits or failed/stale publication attempts.

Do not silently convert existing GrapesJS pages to guided sections. Existing installations retain their current output until the owner explicitly chooses guided mode. Expert mode retains GrapesJS project JSON; switching modes must retain the other draft representation and explain which representation will render.

## 5. Global appearance, navigation and fonts

Use a versioned `settings.website` record with draft data, a published snapshot, audit events and expected-version checks. Appearance and navigation are site-wide, so they should not be duplicated inside every page or theme. A failed publish must preserve the previous public settings snapshot.

```ts
type WebsiteSettingsV1 = {
  schema_version: 1;
  appearance: {
    colors: {
      primary: string; accent: string; background: string;
      surface: string; text: string; muted: string;
    };
    heading_font_id: string;
    body_font_id: string;
    type_scale: "compact" | "standard" | "large";
    button_radius: "square" | "soft" | "rounded";
    content_width: "standard" | "wide";
    section_spacing: "compact" | "standard" | "generous";
    logo_media_id?: string;
    favicon_media_id?: string;
  };
  navigation: {
    primary: ManagedLink[];
    footer: ManagedLink[];
    header_button?: SectionButton;
    show_client_portal: boolean;
  };
};
```

Validate six-digit hex tokens and convert them to application-owned CSS custom properties. Provide color-pair contrast diagnostics for body text, buttons and links. Keep safe defaults when a value is absent. Changes to the public website must not change authenticated workspace typography or layout.

The Google Fonts library should be a curated, searchable catalog of approved families with readable sample text, available weights, scripts/subsets and pairing presets. Catalog entries resolve server-side to local WOFF2 assets. Initially ship a small useful set with family-specific license and attribution files; the research synthesis can nominate the family shortlist. Do not describe a small catalog as the complete Google Fonts collection.

Font implementation constraints:

- Serve selected fonts locally with correct MIME types, cache headers and `font-display: swap`.
- Limit a page to two chosen families and a bounded set of weights; preload only a critical selected face when justified.
- Include every distributed font's actual license and retained notices in the release package.
- Resolve font IDs from the catalog, never interpolate arbitrary CSS or fetch a user-supplied font URL.
- Retain system fallbacks if a file is missing; show an admin diagnostic instead of breaking the public page.
- Preserve the current self-only production font/style CSP. A future automatic catalog downloader would need its own bounded, allowlisted fetch and verification design; it is outside this increment.
- Generate/re-encode favicon derivatives from a permitted local raster upload; do not add SVG/script execution through branding uploads.

## 6. Local SEO identity and consistency pack

### Canonical identity and privacy

Maintain distinct concepts:

- **Business/legal identity:** the company name, legal name, billing address, tax details and financial settings required by back-office documents.
- **Public operating profile:** the real business name, public telephone, public contact email, approved address visibility, hours, services and areas served.
- **Physical branch:** an actual staffed operating location with its own verified public details and, where appropriate, its own public page and profile links.
- **Service-area city:** a place where the company performs work. It does not imply an office, address, branch or separate business entity in that city.

Do not use a city center, virtual office, postal box, billing address or residential address as a public branch without an explicit verified business decision. An address hidden from public presentation must remain absent from the footer, contact pages, page source, JSON-LD, social data and theme context. Permission to edit marketing copy does not grant permission to publish a previously private address.

Normalize and resolve public data through a single public-profile service. Feed the same result into footer/contact rendering, theme context, schema and the copy/exportable NAP sheet. Keep the full legal/billing record outside that projection. Display formatting may differ from machine normalization without automatically flagging an inconsistency.

Suggested records:

```ts
type BusinessLocation = {
  id: string;
  kind: "primary" | "branch";
  name: string;
  public_phone: string;
  public_email?: string;
  public_address?: PostalAddress;
  address_visibility: "visible" | "hidden";
  opening_hours: HoursRule[];
  special_hours: SpecialHoursRule[];
  service_areas: ServedArea[];
  profile_urls: ProfileLink[];
  maps_url?: string;
  public_page_id?: string;
  verified_at?: string;
  verified_by?: string;
};

type CitationObservation = {
  location_id: string;
  directory: string;
  listing_url: string;
  canonical_revision: number;
  observed_name?: string;
  observed_address?: PostalAddress;
  observed_phone?: string;
  observed_website?: string;
  observed_hours?: HoursRule[];
  checked_at: string;
  checked_by: string;
  provenance: "manual" | "imported" | "connector";
  evidence_url?: string;
  evidence_note?: string;
  comparison_status: "unchecked" | "matches" | "difference" | "needs_review" | "not_applicable" | "fetch_blocked" | "stale";
  differing_fields: string[];
  correction_status: "none" | "assigned" | "submitted" | "awaiting_review" | "corrected" | "rechecked";
  correction_owner?: string;
  notes?: string;
};
```

The Local SEO page should show canonical NAP, listing URLs, observed values, comparison details, last checked date and a correction queue. Provide a CSV or other portable NAP export and review checklist. A saved listing URL is not verification of that listing's content. Manual notes and imported snapshots must retain provenance; stale or unavailable evidence remains visibly unverified. No claim of automatic off-site consistency should be made without an actual current data source.

Do not issue arbitrary server requests to listing URLs in the first increment. Any later crawler or connector requires SSRF controls, bounded requests, error reporting and preservation of source/time provenance. Treat external listing text as untrusted data.

### Page plan and structured data

Add a page-coverage view based on real published content: primary services, about/team, contact, real branches, useful service-area pages, projects/case studies, FAQ, resources, and relevant financing/maintenance/careers pages. Include policy/legal links where the business actually has the applicable pages. Show required editorial inputs, draft/published status and broken references.

Service-area pages need useful original information about work performed, availability and local evidence. Do not generate arbitrary city permutations, empty doorway pages or fictional branch pages. Page counts in the research are descriptive observations, not a target that every business must copy.

Extend `SeoBuilder` from verified public-profile data: real address where visible, public phone, hours, profile links, served areas and stable physical-location IDs. Keep application-owned business identity references protected. FAQ data must match rendered answers. Reviews and trust badges do not automatically qualify a site for search features or justify aggregate-rating markup.

## 7. Theme compatibility and rendering boundaries

The current theme implementation has two relevant constraints:

1. `ThemeManager::render()` filters `site` data to scalars, so the existing `service_areas` array is currently discarded from Twig context. Add only explicitly approved structured public values if extending this context.
2. Its HTML purifier allows theme images only under `/theme-assets/{themeId}/assets/images/...`; arbitrary `/files/...` image markup is removed.

Use the existing `page.content` insertion token for an application-rendered homepage section body. The complete section body, including already validated local media, is substituted after Twig sanitization. This preserves theme isolation and gives existing themes a supported content path without weakening arbitrary template image rules.

Older themes may render their own hero before `page.content`; therefore compatibility is not automatically visually lossless. Preserve existing themes, show the active theme's supported capabilities, and provide a guided-homepage-compatible starter template with no duplicate hero. Before activating guided mode, preview the actual theme. A future optional `homepage` widget/capability must enforce exactly-once rendering and define how older templates behave.

Application-owned header/footer and theme-owned header/footer also need an explicit contract. Global navigation and public identity cannot be promised to update an arbitrary legacy theme that hardcodes them. The compatible starter should consume managed navigation/public-profile output. Document unsupported capabilities in the theme settings screen rather than silently claiming success.

Keep the document head, canonical metadata, social tags, verification, JSON-LD and widget scripts outside uploaded theme control. Theme activation must preserve the currently published content and SEO metadata. Continue to prohibit template PHP, executable JavaScript, unsafe CSS URLs and external template retrieval.

## 8. Staged implementation backlog

| Stage | Work | Completion gate |
|---|---|---|
| A — Contracts and compatible renderer | Public-profile/privacy contract before any new public rendering; typed section schemas; legacy-mode handling; validated renderer; page snapshot/revision/media integration | New sections render safely without exposing billing identity, while existing published pages remain unchanged until explicitly converted. |
| B — Homepage settings | Dedicated route; section list; accessible reorder; typed repeaters; image picker; save/preview/publish | An editor can build and publish the research-recommended homepage without opening a popup editor or writing code. |
| C — Appearance and navigation | Versioned site settings; color tokens; local font catalog/licenses; branding; managed navigation | Desktop/mobile public pages reflect published settings, with workspace UI and CSP preserved. |
| D — Local identity | Public-profile projection; visibility controls; hours; actual branches; service areas; shared footer/contact/schema output | A canonical identity edit propagates consistently; hidden address information never appears publicly. |
| E — Local SEO work queue | Citation observations/provenance; normalized comparison; NAP export; page-coverage and internal-link diagnostics | The user can distinguish confirmed mismatches, unverified observations and missing editorial pages. |
| F — Presets and release verification | Apply research-selected defaults and trade variations; compatible starter theme; regression checks; package font assets/notices | A fresh packaged installation can complete the full editing/publication flow with no external font dependency. |

Automated listing crawling, directory account changes, automatic city-page generation and unbounded live font downloads are outside this increment. They are separate capabilities, not prerequisites for a useful manual Local SEO pack.

## 9. Meaningful acceptance tests

### Publication and content

- A saved draft changes the authorized preview but leaves the public homepage unchanged until published.
- Reordering and disabling sections changes the public order and visibility only after publication; one valid H1 remains.
- Restoring a revision restores sections and their references into a draft without overwriting the public snapshot.
- A stale publish receives a conflict and does not promote draft media or partly publish site settings.
- Unknown section types/variants, excess items, unsafe URLs, executable markup and invalid nested data are rejected.
- Private job/payroll files cannot be used as homepage images; publishing valid draft images is atomic with the page snapshot.
- Managed page links follow current published slugs; archived/unpublished references produce clear diagnostics and safe rendering.
- Forms and registered lead tools retain anti-abuse controls and valid CRM attribution after reordering or theme activation.

### Appearance and theme behavior

- Invalid font IDs, CSS fragments and remote font URLs are rejected; selected catalog fonts load locally under the existing CSP.
- Font assets and their licenses survive release packaging and a clean installation without Node or a runtime font API.
- Default and compatible ZIP themes render the homepage body once with selected local images and intact metadata.
- Legacy themes remain usable; unsupported guided/navigation capabilities are visible before publication rather than producing duplicate sections silently.
- Public website settings do not change the authenticated application interface.
- Mobile navigation, image dimensions/alt text, keyboard reorder, focus behavior and color-pair diagnostics work on the published page and editor.

### Identity and Local SEO

- One canonical public phone/name/address feeds visible contact details, footer and corresponding JSON-LD fields consistently.
- A hidden address is absent from rendered markup, JSON-LD, public JSON, theme context and public exports while remaining available only to authorized billing workflows.
- A service-area city does not generate a branch address, location entity or placeholder landing page.
- Actual branches have stable distinct IDs and truthful page/profile associations; deleting a page does not fabricate a replacement branch.
- Citation comparison preserves source, timestamp and provenance; manual data is never described as automatically verified.
- Normalization handles ordinary formatting differences without losing the original observation or treating missing evidence as a match.
- Displayed phone digits match the intended `tel:` destination, with approved alternate/tracking numbers handled explicitly.
- Billing-detail edits never change the published public profile; publishing a public-profile revision marks older citation comparisons stale.
- Hidden precise coordinates stay absent from maps, public media metadata and image descriptions.
- Citation CSV exports neutralize formula-prefixed cells while preserving the underlying source record.
- FAQ markup matches visible answers; testimonials do not trigger invented ratings or review counts.

### UI and access

- Direct settings routes render complete forms with inline save/error feedback; ordinary settings editing does not launch modals.
- Publishing staff can edit authorized website content; clients, technicians and anonymous users cannot access drafts or mutation endpoints.
- Privileged legal/billing identity edits remain subject to their existing permission boundary.
- Failed uploads/saves and version conflicts preserve entered content and provide a recovery action.

Extend relevant existing suites rather than introducing duplicate test harnesses: `tests/Feature/PublishingTest.php`, `PublishingMediaTest.php`, `PublishingThemeTest.php`, `ThemeSettingsTest.php`, `HttpSecurityTest.php`, `resources/js/__tests__/settings-pages.test.tsx`, and `tests/Browser/workspace.spec.ts`. Run the narrowest changed tests first, then appropriate publication/security and release-package regressions. Preserve the requested author watermark on new first-party main files.
