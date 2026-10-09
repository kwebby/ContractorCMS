<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Contractor website research and CMS recommendation

Prepared 9 October 2026 for the US and Canada. Company research was delegated to **GPT-6.1 Sol** as requested. Scope: research and implementation blueprint; application code is unchanged by this research pass.

## Decision

Build a **16-type homepage section library**, with **8–10 sections active in a typical preset**. Offer separate service-trade, roofing and remodeling/project presets, then let each contractor edit images, text, headings, buttons, order and visibility on a dedicated settings page.

Use a **22-page starter plan as an example**, comprising the homepage plus 21 other pages, adjusted to the company's actual services, territory and project evidence. This is a manageable editorial starting point, not an SEO page-count requirement or an instruction to copy larger competitors' inventories.

Ship the Local SEO pack around a **canonical public business profile**, genuine branch records, reusable contact tokens, citation observations and a correction queue. Add global appearance/navigation settings and a locally served Google Fonts starter collection.

## Research files

- [Notes on all 24 businesses, with official sources](COMPANY-SITE-NOTES.md).
- [Company comparison CSV, including other-page counts](contractor-sites.csv).
- [Section frequencies by trade](section-frequencies.csv) and [counting method/limitations](PAGE-COUNT-METHOD.md).
- [Local SEO, appearance and Google Fonts specification](LOCAL-SEO-AND-APPEARANCE.md).
- [Implementation blueprint and current-code audit](IMPLEMENTATION-BLUEPRINT.md).

The company matrix and aggregate analysis supply the sample details and observed frequencies. Recommendations below are product-design judgments informed by that sample, not measured conversion-rate findings.

## 1. How to interpret the sample

The sample covers **24 businesses: 15 US and 9 Canadian, with four in each of six groups**: roofing, plumbing, electrical, HVAC, remodeling and general/design-build construction. Several companies provide more than one trade; the assigned category is for comparison, not an exclusive business classification. Larger regional businesses, franchise/local landing pages and commercial contractors are included, so this is not a representative random sample of small contractors.

Count homepage body sections separately from header, footer, floating chat, cookie notices and repeated mobile navigation. A service-card group counts as one section; each individual service card does not count as a separate section. Repeated CTAs may be separate placements without being separate content types.

Page inventories are either deduplicated HTML-candidate URLs advertised by fetched sitemaps or explicitly labeled lower bounds of observed official links. They are **not Google-indexed page counts**, traffic estimates or proof that every advertised URL works. Branch-path inventories are not comparable to corporate-wide inventories. Do not combine these different methods into one misleading average.

Images are described from accessible page/asset metadata; no competitor images are licensed for reuse by this research. Order and presence are based on accessible content/HTML observations, not a complete visual or accessibility audit of every site. Dynamic or blocked content can be missed.

### Observed homepage patterns

Each category counts once per site, even where it repeats. These are observed content blocks across all 24 extracts; an unobserved block is not proof of absence.

| Body-content category | Sites observed | Share of sample | Product implication |
|---|---:|---:|---|
| Hero/introduction | 20 | 83% | Core guided section; four extracts did not expose a verifiable hero |
| Service grid | 19 | 79% | Core service-card component linked to service pages |
| Customer reviews | 18 | 75% | Reusable attributable testimonials, hidden until evidence exists |
| About/company introduction | 15 | 63% | Editable company/team story |
| Benefits/differentiators | 14 | 58% | Combine with about or trust in compact presets |
| Separate CTA block | 13 | 54% | Reusable conversion section; this count excludes buttons inside hero blocks |
| Blog/resource feed | 11 | 46% | Optional curated published-content feed |
| Project gallery | 9 | 38% | Prioritize in project-led presets |
| Service-area body block | 9 | 38% | Dedicated coverage option; header/footer geography is not included in this count |
| Process | 8 | 33% | Useful reusable explanation, prioritized in project presets |
| Lead form | 8 | 33% | Application-owned form widget, distinct from links to a contact page |
| Offers | 7 | 29% | Optional and time-bounded |
| Financing / FAQ | 5 each | 21% each | Separate conditional sections; no universal financing claim |
| Maintenance membership | 4 | 17% | Trade-specific option rather than a universal default |

Source: [coded section-frequency dataset](section-frequencies.csv), derived from the linked official company observations.

The segmentation is more useful than an overall average:

- **Plumbing, electrical and HVAC (12 sites):** service grids were observed on 12/12 and reviews on 11/12. A direct service/booking preset fits the observed structure.
- **Remodeling and general/design-build (8 sites):** project galleries were observed on 7/8 and process blocks on 6/8. Project imagery and an explanation of how work proceeds deserve earlier placement.
- **Roofing (4 sites):** service grids and reviews appeared on 3/4, project galleries on 2/4. Use a hybrid service-and-project preset.

These frequencies support reusable components and differing presets; they do not demonstrate that one order converts better.

### What the other-page counts show

There are **18 sitemap inventories and 6 manual lower bounds**. Among the **17 company-wide sitemap inventories** (excluding the separately scoped Mister Sparky Houston inventory), advertised candidate counts range from **51 to 2,873**. These include archives, campaigns and accumulated articles/projects as well as core sales pages. The differences illustrate content and geographic scale, not a minimum number of pages needed to compete.

For example, the inventories contain 50 non-home URLs for West Houston Electric, 56 for Cherry & Clark, 212 for SOSNA, 220 for Reliant and 687 for Neil Kelly. The number excludes the normalized sampled homepage and any confirmed successful redirected homepage identity when they occur in the inventory; if a sitemap omits the homepage, the total and non-home counts can be equal. Branch and manually sampled counts retain their own scope/method labels. See [all 24 counts](contractor-sites.csv) and [reproducible inventories](PAGE-COUNT-METHOD.md).

## 2. Recommended homepage: ten available core sections

This is the universal core vocabulary. The contractor can disable sections without suitable content and reorder the rest. The header and footer are global settings, additional to the body sections below.

| Order | Section | Purpose | Editable fields and reasonable starting size |
|---|---|---|---|
| 1 | Hero | State the trade, service area and next step immediately | Heading, introduction, local image/mobile crop, primary request/booking button, secondary call/project button; maximum two CTAs |
| 2 | Trust strip | Present concise, verifiable reasons to trust the company | 3–5 credentials or factual claims, badge images, source links; no fictional ratings or certifications |
| 3 | Services | Help visitors select the work they need | Heading, description, 4–8 selected service cards with image/icon, title, short text and service-page link |
| 4 | About and differentiators | Show who will do the work and why the company fits | Team/truck image, company story, 3–5 concrete differentiators, about-page button |
| 5 | Process | Explain what happens after the visitor makes contact | 3–5 steps with heading, short explanation and optional image; accurate approval/scheduling expectations |
| 6 | Featured projects | Supply original evidence of completed work | 3–6 selected cases, before/after images where appropriate, broad location, service type, summary and case-study link |
| 7 | Customer reviews | Present attributable customer experience | 3–6 approved testimonials, source/date and link; optional factual source rating with verification date, independent of schema eligibility |
| 8 | Service areas | Confirm whether the contractor serves the visitor | Canonical coverage list, linked area pages, branch association, optional static map/local image and coverage CTA |
| 9 | FAQs | Answer common buying and service questions | 4–8 visible question/answer items, written in BlockNote's supported subset, optional further-help link |
| 10 | Contact/request | Convert intent into a properly routed inquiry | Heading, expectation text, application-owned form, canonical phone/hours, service/branch selector where relevant |

The default hero has one main heading and clear text on mobile. The final request block and header may repeat the same conversion action. Avoid a different primary offer in every section.

### Six optional section types

| Type | Enable when | Additional fields |
|---|---|---|
| Offers | There is a real current promotion | Offer text, eligibility, terms, start/end dates, CTA; expire automatically |
| Financing | An actual financing arrangement exists | Provider, approved description/terms, destination; no invented rates or approval promises |
| Maintenance | The company sells actual recurring service | Plan names, inclusions, cadence, approved prices if available, inquiry CTA |
| Articles/resources | Useful published content is maintained | Curated article references, introduction, image and resource-hub CTA |
| Lead tool | A configured, tested public tool is available | Registered tool type, description, image, result/email flow, CRM source and consent text |
| Careers | The company is hiring or has a genuine careers page | Role references or team invitation, location, image and application link |

These options expand the reusable library to **16 types**. A maximum of 24 section instances is a proposed product guardrail for the guided editor, not a search-engine limit. Lead tools are a product opportunity from the earlier plan; this study does not establish that competitors' AI tools improve conversion.

### Trade presets

| Preset | Initial body order | Starting number |
|---|---|---:|
| Plumbing/electrical/HVAC service | Hero → trust → services → reviews → about → process → service areas → contact | 8 |
| Roofing/exterior work | Hero → trust → services → projects → about → process → reviews → service areas → FAQ → contact | 10 |
| Remodeling/design-build | Hero → projects → services → about → process → reviews → service areas → FAQ → contact | 9 |
| Commercial/general construction | Hero → sectors/services → projects → about → process → credentials/trust → operating regions → contact | 8 |

In the first preset, add FAQ and an actual offer or maintenance block only when useful. A remodeler may add credentials as its tenth section. A commercial contractor should use consultation/prequalification language suited to its actual sales process rather than inheriting emergency-service copy. A new business without testimonials or permissioned projects hides those sections until it has evidence.

## 3. Other pages: a concrete starter plan

For a single contractor with six core services, three meaningful priority service areas and three publishable project examples:

| Page family | Number | Notes |
|---|---:|---|
| Homepage | 1 | Selected trade preset |
| Services hub | 1 | Overview and links to real services |
| Service detail pages | 6 | One for each actual service offering |
| Service-area hub | 1 | Accurate territory and branch relationships |
| Service-area detail pages | 3 | Unique useful local content, not substituted city names |
| About/team | 1 | One combined page initially |
| Projects hub | 1 | Original, approved work |
| Case-study pages | 3 | Actual completed projects with permissioned images |
| Reviews | 1 | Consolidate available verified/attributed testimonials |
| FAQ/help | 1 | Genuine company/service questions |
| Contact/request | 1 | Canonical contact information and inquiry flow |
| Privacy and terms | 2 | Business-approved policies reflecting actual site behavior |
| **Total** | **22** | **21 pages in addition to the homepage** |

This example is `10 fixed pages + actual services + useful area pages + case studies = 10 + 6 + 3 + 3`. If there are no projects or testimonials yet, leave those pages as drafts or omit them. Fewer substantial pages are preferable to empty sections created only to meet this example's count.

Add one page for **each genuine branch**, plus optional financing, maintenance, current offers, careers and resource hubs when the business supports them. Individual resources or vacancies add pages over time. Genuine French/English localization adds actual translated URLs; the CMS should not manufacture translations or location pages to inflate inventory.

Suggested initial service choices are editorial examples to confirm with the contractor:

| Trade | Six possible service-page subjects |
|---|---|
| Roofing | Repair, replacement, inspection, maintenance, flat/commercial roofing, gutters |
| Plumbing | Drain cleaning, leak repair, water heaters, sewer work, fixtures, emergency service |
| Electrical | Panel work, EV charging, lighting, troubleshooting, surge protection, generators |
| HVAC | Cooling repair, cooling installation, heating repair, heating installation, maintenance, indoor air quality |
| Remodeling | Kitchens, bathrooms, basements, additions, whole-home remodeling, accessibility work |

Only publish services actually offered and appropriately staffed/qualified. The CMS needs templates for all these subjects, not prefilled claims that every contractor offers them.

## 4. What a business can edit without code

Use **Website → Homepage** for section content and ordering. Keep the familiar BlockNote writer for rich text, a local media library for imagery, bounded card/question repeaters, and explicit button destinations. Provide desktop/mobile previews, save draft, publish, revision restore and inline errors. No popup settings editor is needed.

Use **Website → Appearance** for colors, heading/body font, type scale, spacing, logo/favicon, width and button styles. Use **Website → Navigation** for the header, footer and mobile navigation. Provide at least the eight verified Google Fonts families in the appearance specification as a locally hosted starter set, with catalog search/extension as a separately bounded capability.

Use **Website → Local SEO** and its dedicated detail pages for canonical public identity, real locations, hours, served areas, profile URLs and citation checks. The existing SEO/social page continues to own metadata and schema defaults. Include a page-coverage checklist linking directly to drafts and incomplete content.

Safe managed references matter: a service card should point to a page record, a phone button to the canonical phone, and a project image to an authorized media record. Then a slug change, phone correction or image replacement can propagate without manually fixing every section.

## 5. Findings that should become product checks

The sampled [Reliant Plumbing homepage](https://reliantplumbing.com/) displayed one Bastrop phone number while its `tel:` destination used different digits. The retrieved HTML/text for a [Bone Dry Indianapolis page](https://bonedry.com/indianapolis/) also contained copy referring to Dayton; its rendered placement/visibility was not verified and it may belong to expanded navigation. These are specific on-site observations from this research date, not claims about their Google profiles or business service quality.

Translate those examples into two checks: validate displayed phone digits against the intended click destination, and preview all location-bound content together before publication. Allow legitimate office-specific/tracking numbers and genuinely multi-city content; flag uncertain differences for review rather than automatically rewriting them.

The Local SEO pack also needs hidden-address controls, branch-aware schema, visible-content/schema consistency and an external listing history. Google emphasizes accurate real-world business representation. [Google business guidelines](https://support.google.com/business/answer/3038177?hl=en)

FAQ content remains useful, but Google's FAQ rich-result feature ended in May 2026. Use it for helpful answers and optional semantic markup without promising rich results. [Google's update record](https://developers.google.com/search/updates#may-2026)

## 6. Implementation priorities

1. **Public identity and rendering contracts:** separate public profile from billing, define branch identity and managed contact/phone tokens; define guided-section snapshots and theme capabilities.
2. **Homepage editor:** ten core and six optional types, local images, BlockNote writing, CTAs, accessible ordering, preview/publication and rollback.
3. **Appearance/navigation:** versioned site settings, palette/contrast, approved local font families and licenses, header/footer/mobile controls.
4. **Local SEO pack:** truthful schema, citation observations/comparison, correction tasks and page-plan coverage.
5. **Presets and verification:** apply research-informed defaults, verify existing ZIP themes' capabilities, publication/media privacy, mobile rendering, stale edits and packaged font assets.

The full [implementation blueprint](IMPLEMENTATION-BLUEPRINT.md) identifies the current files, typed contracts, known theme constraints and acceptance tests. The largest integration constraint is legacy themes: arbitrary uploaded templates may hardcode a hero or footer. Managed content and navigation require an explicit compatible-theme contract and preview, not a promise that all existing templates will automatically adopt every setting.

## 7. What this study does not establish

The observations describe website structures and publicly discoverable information. They do not establish conversion rates, revenue, traffic, indexed-page totals, local-pack rankings or cross-directory NAP accuracy. No businesses were contacted, no forms were submitted and no external profiles were changed. No competitor text or images should be imported as the contractor's own content.
