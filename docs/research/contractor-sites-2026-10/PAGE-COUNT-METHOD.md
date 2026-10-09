<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Page inventory and observation method

Checked 9 October 2026. The dataset covers 24 real company websites, with 15 US and 9 Canadian businesses. Groups contain four examples each; many firms perform several trades. This convenience sample cannot establish market-wide prevalence or which layouts convert best.

## What the numbers mean

- **S — sitemap-advertised candidate URLs (18 companies):** Read official robots.txt and standard XML sitemap endpoints. Traverse accepted sitemap indexes and collect **only direct `url/loc` entries**. Nested image/video locations do not count. Restrict to the company hostname, treating `www` aliases as the same inventory identity. Strip fragments and query strings, deduplicate, and exclude common assets, PDFs, feeds and administration routes. HTML-like paths without file extensions remain candidates. Archives, campaign pages and testimonials can be public pages and remain included.
- **M — manual observed-link lower bound (6 companies):** Count a small, explicit, deduplicated set of links from the available official homepage web extraction, including the homepage. These are not full menu crawls. The recorded lower bound indicates discoverable page candidates, not successful fetches of every route. Len's linked drain-cleaning target timed out; Jackson's contact link included an encoded space. Both limitations are recorded.
- Every count is reproducible from the `count_urls` array in `evidence/<id>.json`. The size of that array is the reported count. Counts are **not exact site totals**, search-index totals, analytics landing-page totals or per-URL HTTP validation results. A sitemap may omit pages, contain obsolete routes, include redirects, or include noindex pages. Lower bounds may be much smaller than the actual site.
- `non_home_page_count` answers the number of **other** discoverable page candidates by excluding the normalized sampled homepage and its confirmed successful final redirect URL from the actual inventory. Ashton redirects to a new company domain, so its resolved homepage is excluded too. It does not blindly subtract one: if a sitemap omits the homepage, every advertised candidate is another page (Cherry & Clark: 56). The S/M scope and limitations apply equally to this number.
- The collector caps traversal at 35 XML requests and approximately 120 seconds per site; individual requests have a 22-second timeout. `sitemap_capped`, `failed_sitemap_children` and `sitemap_inventory_partial` disclose incomplete traversal. Standard fallback endpoint errors do not invalidate an accepted sitemap tree. The completed 18 sitemap inventories had no unresolved referenced child or cap flag at capture time, but still cannot certify total site size.
- Corporate hostname inventories include all company regions, offices and content types. Bone Dry and Alair use small manual inventories limited to the sampled Indianapolis/Vancouver paths. Mister Sparky's count includes only advertised `/houston/` paths; its larger brand/network inventory is excluded. Local branch URL counts must not be compared as if they were entire network site sizes.

## Access and content limitations

Ordinary HTTP homepage retrieval succeeded for 18 companies. Bone Dry, Thomas Edison, BOWA, Alair and Jackson returned HTTP 403; Len timed out. Official pages were accessible through web retrieval, allowing real observations and manual URL inventories without attempting to bypass a login or submitting any form. No business was contacted. No account was used.

Section order follows retrieved document/text order conservatively, excluding evident navigation, repeated responsive content, footer content and overlays. Visibility, responsive behavior, sticky states, animation and lazy-loaded media were not exhaustively rendered. Four pages did not expose a verified hero. Image descriptions come from HTML asset metadata/official descriptions, not a full visual screenshot audit. No remote images were downloaded.

The word `cta` denotes a dedicated body call-to-action area; hero buttons do not automatically create a second CTA section. `reviews` includes visible customer-review/testimonial content; `credentials` covers award/association badges; `benefits` covers explanatory reasons to choose the company. `service_grid` is distinct from longer `service_details`, and physical `locations` are distinct from `service_area` coverage. Repeat occurrences remain in ordered records but count once in frequency totals.

## NAP assurance level

Visible business names, contact addresses and telephone numbers are recorded as on-site evidence. PO boxes, showroom appointment conditions, central vs branch phone roles, and physical location vs served city are noted explicitly. Some concrete on-site problems were observed, including mismatched phone labels/hrefs, city-label conflicts, empty schema address fields and unfilled location placeholders.

Risk flags contain both observed inconsistencies and modeling cautions; the prose notes distinguish these. For example, shared central/branch phones or a correctly labeled PO box are not automatically defects. No exhaustive on-site NAP certification is claimed.

**None of the 24 companies received an independent Google Business Profile or cross-platform directory NAP audit.** Local reviews/maps/schema on a company site are signals, not proof of consistency, eligibility, accuracy or ranking. A sampled lack of conflict is not an exhaustive on-site certification.

## Reproduction and files

- `observations.json`: reviewed paraphrased notes, category sequence, CTA actions, NAP and citations; source-of-truth editorial dataset.
- `contractor-sites.csv`: compact company dataset with count method, scope and partial flags.
- `COMPANY-SITE-NOTES.md`: readable notes with official homepage, supporting page and sitemap links.
- `section-frequencies.csv` / `.json`: body-section presence by site and sampling group; denominator 24.
- `evidence/*.json`: factual HTTP status, access timestamp, HTML hash, URL inventories, sitemap requests, canonical metadata, schema type names and numeric phone label/href pairs. Full copied page prose, testimonials, HTML and media are excluded from deliverables.
- `collect_sites.py`: read-only collection; requires `requests` and `beautifulsoup4` installed outside the application dependency tree. `python3 collect_sites.py expert` collects one site; no arguments collects all 24. Temporary inspection material is written under the system temporary directory and is not a research deliverable. Blocked-site manual web observations need a human/agent review on rerun.
- `render_research.py`: standard-library renderer. Run after collection/review to restore the documented manual inventories and regenerate CSV/notes/frequencies from observations and evidence.

These are research tools and documents only. Application code, dependencies, database and deployment are unchanged.
