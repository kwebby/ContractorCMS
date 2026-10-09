<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# ContractorCMS theme developer guide

A theme controls the public website's body layout and styling. ContractorCMS keeps ownership of accounts, CRM, forms, payments, SEO metadata and structured data. You can build and package a theme with a text editor and ZIP utility; PHP, Node and Composer are not required on the theme author's computer.

This guide describes **theme API 1**, implemented in [`ThemeManager`](../app/Publishing/ThemeManager.php), [`ThemeSettings`](../app/Publishing/ThemeSettings.php) and [`LayoutRenderer`](../app/Publishing/LayoutRenderer.php). Application customization is covered in [Development](DEVELOPMENT.md). Installation of the platform is covered in [Installation](INSTALLATION.md).

## Build your first theme

1. Copy [`themes/contractorcms-starter`](themes/contractorcms-starter) to a working folder, or download the Workshop starter from the theme library in the application. The repository example and downloadable Workshop starter are separate themes using the same API.
2. Change `name` in `theme.json` to your theme's name and retain `api_version: 1`. Start a new theme at `version: "1.0.0"`.
3. Edit `templates/home.twig` and `templates/page.twig` for layout, `assets/style.css` for presentation, and `settings.schema.json` for the controls the site owner will see.
4. Keep the `page.content`, `widgets.header` and `widgets.footer` slots. Keep the guided-homepage branch in `home.twig` so the homepage editor can supply its own heading and service-request form.
5. Package the **contents** of the working folder, with `theme.json` at the ZIP root. See the commands below.
6. In the application, open **Themes → Upload theme**, choose the ZIP and upload it. Validation finishes before the package enters the theme library.
7. Open the theme's dedicated **Settings** page and set its values. Open **Preview**, then activate it from the library when ready.
8. Test the homepage and a regular published page on desktop and mobile. Use **Restore previous** to switch back, or **Use built-in theme** to return to the application theme.

The example has no external assets or package dependencies. It uses the platform's locally hosted font library and public appearance settings. Its MIT license permits adaptation while retaining its copyright notice.

The current editable Workshop starter is version **1.1.0**, using `api_version: 1`. Download it from Website → Themes → Download starter. The starter supports guided homepages and managed navigation. Older API 1 packages can continue to provide expert layouts without claiming these capabilities.

## Package and manifest

```text
theme.json
settings.schema.json
templates/page.twig
templates/home.twig
assets/style.css
assets/images/example.webp
assets/fonts/example.woff2
preview.webp
LICENSE.txt
```

```json
{
  "name": "Workshop",
  "version": "1.1.0",
  "api_version": 1,
  "templates": {
    "page": "templates/page.twig",
    "home": "templates/home.twig"
  },
  "assets": ["assets/style.css"],
  "widgets": ["contact", "header", "footer"],
  "capabilities": ["guided-homepage", "managed-navigation"]
}
```

The renderer selects a template by page type and falls back to `page`. Capabilities and widgets must be lists of distinct supported strings. `managed-navigation` requires the manifest to declare both `header` and `footer`. The CMS verifies the rendered contract; a declaration alone is insufficient.

| Manifest field | Contract |
|---|---|
| `name` | Nonempty string, at most 100 bytes |
| `version` | Semantic version such as `1.0.0` or `1.1.0-beta.1`; upload a new package for a new version |
| `api_version` | Integer `1` |
| `templates` | Mapping of at most 20 page-type keys to existing `templates/*.twig` paths; `page` is required |
| `assets` | List of at most 100 existing assets; listed `.css` files become stylesheets in list order |
| `widgets` | Distinct entries from `contact`, `header`, `footer` |
| `capabilities` | Distinct entries from `guided-homepage`, `managed-navigation` |

Current page-type keys are `home`, `service`, `service-area`, `branch`, `about`, `contact`, `article`, `case-study`, `collection`, `faq`, `careers`, `job`, `tool` and `page`. To add a service layout, create `templates/service.twig` and add `"service": "templates/service.twig"` to the manifest. This selects a layout for an existing page type; a theme cannot register routes or invent an application page type.

`settings.schema.json`, `preview.webp`, `LICENSE.txt`, `sections/*.json` and `pages/*.json` are optional to the validator. Include a license for distribution. The current theme library uses its own preview presentation: `preview.webp` is an accepted package asset, not a promised custom thumbnail. Uploaded page/section JSON is retained as authoring material; it is **not automatically imported into site pages**. Do not package README files, dependency directories or build-tool configuration inside the upload ZIP.

An installed package is immutable. Upload a new ZIP to change its templates or assets. Activation validates the candidate before changing the active database pointer. Theme activation and page publication share a lock, so a legacy theme cannot race a guided-homepage publication. Restore previous can switch between uploaded versions and the built-in theme; activating the current theme again preserves rollback history. The theme library includes a **Use built-in theme** action and visible compatibility labels.

Authenticated application screens keep their own interface. Theme configuration uses a dedicated page with inline validation and save feedback.

## Template context and owned slots

Templates receive only primitive public data:

| Value | Content |
|---|---|
| `site` | Approved public business scalar fields; hidden address fields are omitted |
| `page` | `title`, `excerpt`, `locale`, `guided_homepage` and the `content` slot |
| `settings` | Validated flat theme configuration values |
| `assets.base` | Local URL prefix for this theme's approved assets |
| `widgets.contact` | Application-owned service-request form |
| `widgets.header` | Application-owned navigation, branding and header action |
| `widgets.footer` | Application-owned footer navigation and public business details |

`site` allows `name`, `legal_name`, `phone`, `email`, `address`, `city`, `region`, `postal_code`, `country`, `trade` and `service_areas` when the supplied value is a scalar or null. Some fields can be absent; array-valued service areas are not exposed to Twig. Public address visibility is respected upstream. Use managed navigation and published page content for repeatable business data instead of hardcoding a second name/address/phone source in a template.

There is no `page.id`, page collection, image-upload object, menu array, settings-record object, authenticated user or asset filesystem handle in this context. `assets.base` is a URL prefix, for example `/theme-assets/<theme-id>/assets`, without a trailing slash.

Render `{{ page.content }}` exactly once. Render the contact widget at most once on ordinary pages. The CMS checks these rules with the optional `site` fields and `page.excerpt` both filled and empty, so a slot must not depend on whether a business has, for example, a phone number. A managed-navigation template must render header and footer exactly once each. Place these slots in body content, never in an HTML attribute. The renderer replaces opaque tokens with application-owned HTML only after sanitizing the template.

A guided homepage's content already contains its single H1 and any enabled contact section. When `page.guided_homepage` is true, the template must not add another H1 or render `widgets.contact`. The following works for both homepage modes:

```twig
<!-- Author: ramanpal singh | URL: https://kwebby.com -->
{{ widgets.header }}
<div class="workshop">
  {% if page.guided_homepage %}
    {{ page.content }}
  {% else %}
    <div class="workshop-hero">
      <h1>{{ page.title }}</h1>
      <p>{{ page.excerpt }}</p>
    </div>
    {{ page.content }}
    {{ widgets.contact }}
  {% endif %}
</div>
{{ widgets.footer }}
```

Do not implement a replacement lead form in uploaded templates. HTML forms and executable elements are not part of the theme allowlist; use the owned contact widget. The CMS retains request validation, consent handling, abuse controls and CRM attribution.

Available Twig tag: `if`. Filters: `escape`, `e`, `default`, `upper`, `lower`, `title`, `trim`. Raw filters, includes, loops, functions, object methods, framework services, database access and filesystem access are unsupported. Templates receive no private CRM, payroll, billing or credential records.

Twig HTML autoescaping is enabled. A value like `{{ settings.tagline }}` is text, not an HTML fragment. Use `if`/`else`/`endif` for conditional layout. Template inheritance, macros, `include`, `for`, `set`, `raw`, `source`, range expressions and custom functions are unavailable. Inside `{{ }}` and `{% %}`, the names `include`, `source`, `range`, `random` and `constant` and the `..`, `**` and `matches` operators are rejected, including as variable or setting names. Ordinary page text and Twig comments, such as `<p>Our service range</p>` or `<p>Loading...</p>`, are unaffected. Split visual styles into CSS files if useful, but repeat a small template wrapper instead of trying to include another Twig file.

Theme HTML passes through a restricted sanitizer. The allowed tags are `div`, `span`, `p`, `a`, `h1`–`h6`, `ul`, `ol`, `li`, `strong`, `em`, `b`, `i`, `u`, `br`, `hr`, `img`, `blockquote`, `table`, `thead`, `tbody`, `tr`, `th`, `td`, `dl`, `dt` and `dd`. Most layout tags allow `class`; links allow `href` and `title`; images allow `src`, `alt`, `width` and `height`. `id` attributes are removed so theme markup cannot collide with element IDs owned by the application; style by class instead. Other attributes and elements can be stripped or rejected. In particular, use `<div class="hero">` in ZIP templates rather than relying on `<section>`, `<main>`, `<header>` or `<footer>` surviving sanitization. Application-owned widgets can contain their own richer, trusted markup after slot insertion.

## Guided homepage content

The dedicated homepage editor stores a versioned `home_sections` document on the ordinary home page record. It supports these 16 types:

| Core sections | Optional sections |
|---|---|
| Hero, trust, services, about, process | Offers, financing, maintenance |
| Projects, reviews, service areas, FAQ, contact | Articles, lead tool, careers |

Four presets provide ordered starting points: service team, roofing/exteriors, remodeling/projects and multi-trade. They contain 8–10 section slots. Only hero and contact begin enabled; the contractor supplies genuine services, projects, credentials and reviews before enabling those sections.

Editors can change section order and visibility, variants, eyebrow text, headings, BlockNote writing, images with alt text/focal positions, up to two buttons and type-specific repeated items. Managed page buttons resolve from published pages; phone buttons use the canonical public number. Public tools appear only when configured and available. Offers respect their dates in the public business timezone. Testimonials require confirmed permission/provenance before publication and do not manufacture rating schema.

The document permits at most 24 sections, 12 ordinary repeated items per section, eight FAQ items, and 250 KB of serialized section data. Guided mode requires exactly one enabled hero and at most one enabled contact section. Writing inside sections uses H2–H6. Arbitrary HTML, scripts and unknown section fields are rejected.

Homepage save, preview, publication and revision restore use the existing page lifecycle. Switching between guided and expert mode retains the other representation. A legacy theme without `guided-homepage` cannot be activated over a published guided homepage, or used to publish one; choose a compatible theme or keep the page in expert mode.

## Global appearance and theme settings

Global appearance, navigation and public business identity are edited through dedicated website pages. They share a versioned draft/published settings record, separate from homepage publication. The application emits these CSS variables for compatible themes:

| Variables | Meaning |
|---|---|
| `--website-primary`, `--website-accent` | Brand/action colors |
| `--website-background`, `--website-surface` | Page and section surfaces |
| `--website-text`, `--website-muted`, `--website-border` | Text and border colors |
| `--website-heading-font`, `--website-body-font` | Selected local font stacks |
| `--website-radius` | Button radius |
| `--website-width` | Content width |
| `--website-spacing` | Section spacing |
| `--website-scale` | Typography scale multiplier |

Use these variables in theme CSS to honor global appearance choices. Managed header/footer widgets already use the website settings. A legacy theme can control its own header/footer; the theme library identifies that limitation.

The curated font library includes **Inter, Manrope, Montserrat, Source Sans 3, Open Sans, Work Sans, Lora and Merriweather**. The application ships local WOFF2 Latin/Latin Extended assets, family licenses, source references and hashes. Font faces use `font-display: swap`; public font selection does not require a visitor request to Google. This is an eight-family library, not the complete Google Fonts catalog. The public stylesheet/font CSP remains self-hosted.

Theme-specific settings remain a bounded object of at most 30 flat properties. Supported types: string, boolean and integer. Optional controls include bounded string enums, six-digit hex colors, string length, integer bounds, labels, descriptions and defaults. Required fields need valid defaults for a fresh install. External references and nested schemas are rejected.

```json
{
  "type": "object",
  "properties": {
    "accent": {"type": "string", "title": "Accent color", "format": "color", "default": "#27483d"},
    "tagline": {"type": "string", "title": "Tagline", "default": "Home services", "maxLength": 100}
  }
}
```

A color setting emits a variable such as `--theme-accent`. For example, use `background: var(--theme-accent, var(--website-primary))`. Settings updates require `expected_version` and revalidate templates with candidate values before saving. A conditional setting cannot remove a required navigation/content slot or introduce an extra guided H1/contact widget; a failed update preserves the previous settings and version.

## Package and publication boundaries

ZIP limits are 25 MiB compressed, 100 MiB extracted, 2,000 entries and 10 MiB per entry. Text files are limited to 250 KB and Twig to 50 KB. Unsafe paths, duplicate normalized paths, links, nested/encrypted archives, executable files, JavaScript, arbitrary embeds and server configuration are rejected. Raster images and WOFF2 fonts receive format checks. CSS imports, escapes and external URLs are prohibited; CSS URLs may reference local `images/` or `fonts/` assets.

Packages may retain `sections/*.json` and `pages/*.json` as authoring assets. Arbitrary uploaded Twig remains expert code; there is no promised lossless Twig-to-GrapesJS conversion. The visual designer edits GrapesJS project JSON with supported application components.

The CMS owns the document head, metadata, JSON-LD, canonical URLs, scripts and chat. Theme HTML is body content. Advanced structured data belongs in page SEO settings and cannot override protected business identities.

Draft publishing images remain private until first successful publication. Guided publication promotes images referenced by enabled sections; disabled section media stays draft. Published branding images are promoted through website-settings publication. Previously published assets remain public when a page is unpublished because other pages or external copies may still reference them. Private job/client files cannot be selected as website images.

## Images, fonts and CSS

Theme assets are versioned local files. They are served through `/theme-assets/{theme-id}/assets/...`, with an explicit content type and immutable cache headers. Treat every theme asset as publicly downloadable once the package is validated, including before activation; do not bundle private files. Re-upload a changed package rather than editing extracted files on the server or expecting an old URL's cache to refresh.

For a decorative image bundled with the theme:

```twig
<img src="{{ assets.base }}/images/team-work.webp" alt="Technician preparing tools" width="1200" height="800">
```

The file must exist at `assets/images/team-work.webp`. Only local theme images matching that path shape are allowed directly in a Twig `<img>`. Remote images, SVG, data URLs and `/files/...` image paths inserted directly into Twig are not supported. Customer-managed images belong in BlockNote, the guided homepage editor or the public branding settings; their approved HTML arrives through application slots.

From `assets/style.css`, use:

```css
/* Author: ramanpal singh | URL: https://kwebby.com */
.theme-hero { background-image: url("images/team-work.webp"); }
@font-face {
  font-family: "My Licensed Font";
  src: url("fonts/my-font.woff2") format("woff2");
  font-display: swap;
}
```

Relative CSS URLs must begin with `images/` or `fonts/`; use top-level `assets/*.css` when referencing these assets. This applies to every `url()`, quoted or unquoted, and to strings inside `image-set()`, `image()`, `src()` and `cross-fade()`. Paths containing `..` or whitespace, external origins, root-relative CSS URLs, data URLs and CSS `@import` are rejected. Other CSS strings, including custom property values, cannot begin with `http:`, `https:`, `//` or `data:`; attribute selectors such as `a[href^="tel:"]` and text such as `content: "Call: "` are allowed. Close every CSS string on the line where it starts. The validator checks allowed URL syntax, but authors must also ensure every referenced file is included and loads in preview. WOFF2 is the only font file format. Include the full required font/asset license notices in the root `LICENSE.txt`; separate `.txt` files under `assets/fonts/` are not an allowed package path. Do not add Google Fonts embed scripts or remote CSS imports.

Use a theme class prefix, such as `.contractorcms-starter`, to avoid unintended changes to platform widgets. The CMS includes its own public stylesheet, so test your selectors alongside the existing form, chat, header/footer and homepage styles. Avoid hiding headings, labels, consent text or error messages with CSS. Check visible keyboard focus, color contrast, zoom and narrow-screen wrapping.

The upload path grammar accepts letters, digits, underscores, forward slashes, hyphens and dots in the allowed positions. Use lowercase filenames consistently. Spaces, hidden files, `.DS_Store`, `__MACOSX` metadata files, absolute paths, symlinks and case-colliding duplicates are unsuitable for the package. Optional raster images must be valid PNG/JPEG/WebP with at most 24 million pixels.

## Guided pages, writing and expert layout

There are three distinct editable sources:

| Source | Edited in | Responsible for |
|---|---|---|
| `home_sections` JSON | Dedicated homepage editor | Ordered homepage sections, variants, images, buttons and section writing |
| BlockNote `content` JSON | Page writing editor | Long-form content; supported paragraph/heading/list/checklist/quote/table/image blocks |
| GrapesJS `layout` project JSON | Expert page layout designer | A page's arrangement of approved layout components |

In guided homepage mode, `page.content` is the application's rendered `home_sections` document. In expert mode and on ordinary pages, it contains the rendered GrapesJS layout around the BlockNote writing. If the layout has no `cms-content` component, the writer content is appended. Keep one `cms-content` component for predictable positioning.

The expert designer ships Section, Heading, Text, Two columns, Divider, Link, Page content (`cms-content`) and Service request (`cms-contact`) blocks. Projects retain GrapesJS JSON; copying exported HTML into Twig does not preserve an editable project. The server accepts supported structural component types, local media and bounded styles. Executable components, arbitrary embeds and scripts are rejected even if added outside the UI. Layout JSON is limited to 1 MB, 2,000 visited nodes and depth 15; responsive stylesheet rules support a single min/max width in pixels.

**Choose one owner for an ordinary page's contact form.** The repository starter already appends `widgets.contact` in its ordinary-page templates. Do not also add a `cms-contact` block inside that page's expert layout, or you can display two request forms. Alternatively, remove `widgets.contact` from the ordinary-page template and place the owned form in the expert layout. Guided homepages always own their form within `page.content`.

For custom homepage section types or interactive widgets, edit application source and add validation/rendering/tests as described in [Development](DEVELOPMENT.md#adding-a-section-or-widget). A ZIP cannot register new JavaScript, server endpoints, database tables, editor controls or widget names.

## Package a ZIP

Keep documentation and development tooling outside the upload folder. The smallest complete theme needs a root manifest, its referenced page template and any referenced stylesheet. The repository starter uses six files:

```text
contractorcms-starter/
├── theme.json
├── settings.schema.json
├── LICENSE.txt
├── templates/
│   ├── home.twig
│   └── page.twig
└── assets/
    └── style.css
```

The ZIP must contain `theme.json`, **not** `contractorcms-starter/theme.json`.

### macOS or Linux

From the repository root, for a fresh output filename:

```bash
cd docs/themes/contractorcms-starter
zip -X ../../../contractorcms-starter-1.0.0.zip \
  theme.json settings.schema.json LICENSE.txt \
  templates/page.twig templates/home.twig assets/style.css
unzip -l ../../../contractorcms-starter-1.0.0.zip
```

`-X` omits extra file attributes. Explicit file arguments avoid including Finder metadata, `.git`, build files or the parent directory. Add every additional permitted template/image/font/CSS path deliberately. For an updated package, bump `version` and use a new ZIP filename; `zip` can otherwise retain old entries when updating an existing archive.

### Windows PowerShell

From the repository root, using a new filename:

```powershell
Push-Location docs/themes/contractorcms-starter
Compress-Archive -Path theme.json,settings.schema.json,LICENSE.txt,templates,assets `
  -DestinationPath ../../../contractorcms-starter-1.0.0.zip
Pop-Location
```

Inspect the archive in your ZIP viewer before uploading. Do not select the enclosing `contractorcms-starter` directory as the archive input. Neither command modifies or installs the application; the ZIP is installed through the theme library.

### Review before distributing

- Confirm the root manifest, semantic version and every referenced path.
- Use only supported files, Twig tags/filters and HTML attributes.
- Preview both a guided homepage and an ordinary page. The theme preview endpoint shows a representative published page, not every possible page type; check the remaining page types on a staging installation.
- Verify exactly one visible H1 on the guided homepage, one managed header/footer and one service-request form where intended.
- Change each setting, save it and confirm the required slots remain present.
- Check uploaded images, local fonts, mobile widths, keyboard focus and long headings.
- Check source HTML for canonical, robots, Open Graph, X card and JSON-LD output; these must remain application-owned.
- Submit the owned service-request form on a test installation and confirm the CRM lead appears with the right consent choices.
- Preserve third-party notices and remove private customer images or personal details before sharing the ZIP.

## Install, preview, update and roll back

The theme library is at `/app/themes`; upload is at `/app/themes/upload`; settings are at `/app/themes/{id}/settings`. Access requires the `publishing.manage` permission. The update screens are dedicated pages.

1. **Upload:** ContractorCMS inspects archive entries before extracting them into protected storage, validates files and exercises template contracts with default settings and with optional public business fields both filled and empty. Failed uploads are removed and do not change the active theme.
2. **Configure:** Set values on the theme's settings page. Settings are keyed by the uploaded theme ID. A new uploaded version starts with its schema defaults; saved settings are not automatically migrated between package IDs. Settings for an active theme take effect after saving and do not have a separate publication step.
3. **Preview:** Open the authenticated preview for the inactive candidate. It is `noindex` and does not change the active pointer. This checks a representative page; use staging to verify all page types.
4. **Activate:** The candidate is checked again with its saved settings, including compatibility with published guided homepages. If the active theme later fails while rendering a public page, that page falls back to the built-in theme and the failure is written to the application log. Activation and publication share a transaction lock. The active pointer changes only after successful validation.
5. **Roll back:** Restore previous returns to the prior uploaded theme or the built-in theme. The mechanism tracks the current and previous selection, rather than a chronological rollback log. Existing installed versions stay available in the library.
6. **Update:** Edit source outside the server, increment the manifest version, create a new ZIP and repeat. Do not edit immutable extracted package files.

Homepage publication, global website-settings publication and theme activation are independent. Saving a draft homepage or appearance configuration does not publish it. A theme-specific setting save and a theme activation do affect the selected theme immediately. Plan these steps on staging before a coordinated public redesign.

## Theme API routes

These are same-origin, cookie-authenticated application endpoints. They are not a public token API. Mutating requests require the current session's CSRF token; `resources/js/api.ts` is the application's reference client. Authorization still applies server-side.

| Method and path | Purpose |
|---|---|
| `GET /api/v1/themes` | List packages and current/previous availability |
| `GET /api/v1/themes/starter` | Download Workshop starter ZIP |
| `POST /api/v1/themes` | Multipart upload with file field named `file` |
| `GET /api/v1/themes/{id}/preview` | Authenticated HTML preview |
| `GET /api/v1/themes/{id}/settings` | Schema, current values and version |
| `PUT /api/v1/themes/{id}/settings` | Save `{ "values": { ... }, "expected_version": 0 }`; use the actual version returned by GET |
| `POST /api/v1/themes/{id}/activate` | Activate a validated package |
| `POST /api/v1/themes/default` | Select the built-in theme |
| `POST /api/v1/themes/rollback` | Restore the previous selection |

There is no public theme marketplace, remote updater, package installer hook or theme-deletion endpoint in this release.

## Troubleshooting

| Message or symptom | What to check |
|---|---|
| `A theme.json manifest is required` | Manifest is at ZIP root, UTF-8 JSON and under 100 KB; not wrapped in a parent folder |
| `Unsafe archive path` / `Prohibited theme file` | Remove hidden files, macOS metadata, scripts, server configuration, nested archives, dependency folders and unsupported extensions |
| Missing template/asset | Manifest paths are exact, case-consistent and included in the ZIP |
| Content/widget contract failure | Required slots appear once in every conditional branch, as text content rather than attribute values |
| Guided-homepage compatibility failure | Declare `guided-homepage`, retain its conditional template branch and omit an extra H1/contact widget in that branch |
| Empty or altered markup | Sanitizer removed an unsupported tag/attribute; use the allowlist above |
| Theme images disappear | Use the `assets.base` local image URL, correct raster extension and a real included file |
| CSS upload rejected | Remove `@import`, backslash escapes, HTML characters, external URLs and unsafe CSS constructs |
| Setting rejected | Check type, required default, bounded enum/color/length and whether a conditional removed a required slot |
| HTTP 409 on save | Another edit changed the version; reload, reconcile edits and save against the current version |
| Upload fails before CMS validation | Check PHP `upload_max_filesize`, `post_max_size`, web-server request limits and PHP ZIP extension; server limits can be below the CMS's 25 MiB cap |
| New CSS is not visible | Upload a new theme package and activate its ID; confirm the expected CSS is listed in its manifest |
| Global colors/fonts do not affect custom areas | Use the emitted `--website-*` variables instead of fixed custom values; the CMS cannot reinterpret arbitrary CSS |
| Public pages use the built-in theme unexpectedly | The active theme failed while rendering; check the application log, fix the package and upload a new version |
| Rollback unavailable | There is no prior selection recorded yet, or the previous package is incompatible with the currently published guided homepage |

For application bugs, include the ContractorCMS revision, theme manifest/API version, browser/server versions and the validation message. Share a minimal theme without business data or credentials.
