/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { lazy, Suspense, useEffect, useRef, useState } from "react";
import "../../css/settings-pages.css";
import {
    ArrowLeft,
    ArrowUpRight,
    Check,
    ExternalLink,
    FileText,
    Globe,
    LayoutTemplate,
    Plus,
    RotateCcw,
    Save,
    Search,
    Upload,
} from "lucide-react";
import { api, date, formValues, label, type RecordData } from "../api";
import {
    Badge,
    Button,
    DataTable,
    Empty,
    ErrorNotice,
    Field,
    Loading,
    Saved,
    SelectField,
    Tabs,
    TextField,
    Toolbar,
    useData,
} from "../components/ui";
const Writer = lazy(() => import("../components/Writer"));
const LayoutDesigner = lazy(() => import("../components/LayoutDesigner"));
const pageTypes = [
    "home",
    "service",
    "service-area",
    "branch",
    "about",
    "contact",
    "article",
    "case-study",
    "collection",
    "faq",
    "careers",
    "job",
    "tool",
    "page",
];
export default function Publishing({
    onToast,
    publishingPage,
}: {
    onToast: (t: string) => void;
    publishingPage?: string;
}) {
    if (publishingPage === "seo") return <SeoSettings />;
    return <PublishingPages onToast={onToast} />;
}
function PublishingPages({ onToast }: { onToast: (t: string) => void }) {
    const state = useData<RecordData[]>("/api/v1/pages");
    const [query, setQuery] = useState("");
    const [editing, setEditing] = useState<RecordData | null | false>(false);
    const [filter, setFilter] = useState("all");
    const requestedPage = new URLSearchParams(window.location.search).get(
        "edit",
    );
    useEffect(() => {
        if (requestedPage && state.data) {
            const page = state.data.find((item) => item.id === requestedPage);
            if (page) setEditing(page);
        }
    }, [requestedPage, state.data]);
    if (editing !== false)
        return (
            <PageEditor
                record={editing}
                onClose={() => {
                    if (requestedPage) {
                        window.location.assign("/app/pages");
                        return;
                    }
                    setEditing(false);
                    state.reload();
                }}
                onToast={onToast}
            />
        );
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>Website & content</h1>
                    <p>
                        Useful pages that help customers find you and choose
                        you.
                    </p>
                </div>
                <div className="heading-actions">
                    <a className="button secondary" href="/app/website/seo">
                        <Search size={15} />
                        SEO settings
                    </a>
                    <a
                        className="button secondary"
                        href="/"
                        target="_blank"
                        rel="noreferrer"
                    >
                        <ExternalLink size={15} />
                        View website
                    </a>
                    <Button onClick={() => setEditing(null)}>
                        <Plus size={16} />
                        Create page
                    </Button>
                </div>
            </div>
            <nav className="website-shortcuts" aria-label="Website settings">
                <a href="/app/website/homepage">Homepage sections</a>
                <a href="/app/website/appearance">Colors & fonts</a>
                <a href="/app/website/navigation">Navigation</a>
                <a href="/app/website/local-seo">
                    Local SEO & business details
                </a>
                <a href="/app/website/local-seo/page-plan">Page plan</a>
            </nav>
            <section className="panel">
                <Toolbar
                    query={query}
                    setQuery={setQuery}
                    placeholder="Find a page…"
                >
                    <select
                        aria-label="Filter publication status"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                    >
                        <option value="all">All pages</option>
                        <option value="published">Published</option>
                        <option value="draft">Drafts</option>
                    </select>
                </Toolbar>
                <ErrorNotice message={state.error} />
                {state.loading ? (
                    <Loading />
                ) : (
                    <DataTable
                        rows={(state.data ?? []).filter(
                            (p) =>
                                (filter === "all" || p.status === filter) &&
                                `${p.title} ${p.slug}`
                                    .toLowerCase()
                                    .includes(query.toLowerCase()),
                        )}
                        onRow={setEditing}
                        columns={[
                            {
                                key: "title",
                                label: "Page",
                                render: (p) => (
                                    <div className="cell-person">
                                        <span className="page-icon">
                                            <FileText size={17} />
                                        </span>
                                        <span>
                                            <strong>{p.title}</strong>
                                            <small>
                                                /
                                                {p.type === "home"
                                                    ? ""
                                                    : `p/${p.slug}`}
                                            </small>
                                        </span>
                                    </div>
                                ),
                            },
                            {
                                key: "type",
                                label: "Type",
                                render: (p) => label(p.type),
                            },
                            {
                                key: "status",
                                label: "Status",
                                render: (p) => (
                                    <Badge>{p.status ?? "draft"}</Badge>
                                ),
                            },
                            { key: "locale", label: "Language" },
                            {
                                key: "updated_at",
                                label: "Last updated",
                                render: (p) => date(p.updated_at),
                            },
                        ]}
                        empty={
                            <Empty
                                title="Make your business easy to find"
                                description="Create your first service page, article, or case study."
                                action={
                                    <Button
                                        variant="secondary"
                                        onClick={() => setEditing(null)}
                                    >
                                        Create a page
                                    </Button>
                                }
                            />
                        }
                    />
                )}
            </section>
        </>
    );
}
function PageEditor({
    record,
    onClose,
    onToast,
}: {
    record: RecordData | null;
    onClose: () => void;
    onToast: (t: string) => void;
}) {
    const [page, setPage] = useState<any>(
        record ?? {
            title: "",
            slug: "",
            type: "page",
            locale: "en-US",
            content: [],
            excerpt: "",
            seo: { robots: "index,follow", schema_mode: "auto" },
            layout: {},
        },
    );
    const [tab, setTab] = useState("Write");
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [dirty, setDirty] = useState(false);
    const [slugEdited, setSlugEdited] = useState(Boolean(record?.id));
    const [saveState, setSaveState] = useState("");
    const [revisions, setRevisions] = useState<RecordData[] | null>(null);
    const live = useRef(page);
    live.current = page;
    const pending = useRef(false);
    function update(key: string, value: unknown) {
        setPage((p: any) => ({ ...p, [key]: value }));
        setDirty(true);
        setSaveState("Unsaved changes");
    }
    function seo(key: string, value: unknown) {
        setPage((p: any) => ({ ...p, seo: { ...p.seo, [key]: value } }));
        setDirty(true);
        setSaveState("Unsaved changes");
    }
    async function save(auto = false) {
        if (pending.current) return;
        pending.current = true;
        setBusy(!auto);
        setError("");
        const snapshot = live.current;
        try {
            const r = await api(
                `pages${snapshot.id ? `/${snapshot.id}` : ""}`,
                snapshot.id ? "PUT" : "POST",
                { ...snapshot, expected_version: snapshot.version },
            );
            setPage((current: any) =>
                current === snapshot
                    ? r.data
                    : { ...current, id: r.data.id, version: r.data.version },
            );
            if (live.current === snapshot) setDirty(false);
            setSaveState("Saved");
            if (!auto) onToast("Page saved");
            return r.data;
        } catch (e) {
            setError((e as Error).message);
            setSaveState("Save failed");
        } finally {
            setBusy(false);
            pending.current = false;
        }
    }
    useEffect(() => {
        if (!dirty || !page.id) return;
        const timer = setTimeout(() => {
            save(true);
        }, 2500);
        return () => clearTimeout(timer);
    }, [page, dirty]);
    async function publish() {
        setBusy(true);
        const saved = await save();
        if (!saved) {
            setBusy(false);
            return;
        }
        setBusy(true);
        try {
            const r = await api(`pages/${saved.id}/${"publish"}`, "POST", {
                expected_version: saved.version,
            });
            setPage(r.data);
            onToast("Page published");
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <div className="editor-heading">
                <button
                    className="icon-button"
                    aria-label="Back to pages"
                    onClick={onClose}
                >
                    <ArrowLeft size={20} />
                </button>
                <div>
                    <h1>{page.id ? page.title : "Create a page"}</h1>
                    <p>
                        {page.id ? (
                            <>
                                <Badge>{page.status ?? "draft"}</Badge>
                                <span className="save-state">{saveState}</span>
                            </>
                        ) : (
                            "Write something useful for your next customer."
                        )}
                    </p>
                </div>
                <div className="heading-actions">
                    {page.id && page.status === "published" && (
                        <Button
                            variant="ghost"
                            busy={busy}
                            onClick={async () => {
                                setBusy(true);
                                try {
                                    const r = await api(
                                        `pages/${page.id}/unpublish`,
                                        "POST",
                                        { expected_version: page.version },
                                    );
                                    setPage(r.data);
                                    onToast("Page unpublished");
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            Unpublish
                        </Button>
                    )}
                    {page.id && (
                        <a
                            href={`/api/v1/pages/${page.id}/preview`}
                            target="_blank"
                            rel="noreferrer"
                            className="button secondary"
                        >
                            <ExternalLink size={14} />
                            Preview
                        </a>
                    )}
                    <Button
                        variant="secondary"
                        busy={busy}
                        onClick={() => save()}
                    >
                        <Save size={15} />
                        Save draft
                    </Button>
                    <Button busy={busy} onClick={publish}>
                        {page.status === "published"
                            ? "Publish changes"
                            : "Publish page"}
                    </Button>
                </div>
            </div>
            <ErrorNotice message={error} />
            <p className="help-text">
                Draft images are private until publication. Published images
                remain public.
            </p>
            <div className="editor-shell">
                <Tabs
                    tabs={[
                        "Write",
                        "Page settings",
                        "SEO",
                        "Social",
                        "Schema",
                        "Layout",
                        "Revisions",
                    ]}
                    active={tab}
                    onChange={async (t) => {
                        setTab(t);
                        if (t === "Revisions" && page.id) {
                            try {
                                const r = await api(
                                    `pages/${page.id}/revisions`,
                                );
                                setRevisions(r.data);
                            } catch (e) {
                                setError((e as Error).message);
                            }
                        }
                    }}
                />
                {tab === "Write" && (
                    <div className="writing-page">
                        <input
                            className="page-title-input"
                            aria-label="Page title"
                            value={page.title}
                            placeholder="Give your page a title"
                            onChange={(e) => {
                                update("title", e.target.value);
                                if (!page.id && !slugEdited)
                                    update(
                                        "slug",
                                        e.target.value
                                            .toLowerCase()
                                            .replace(/[^a-z0-9]+/g, "-")
                                            .replace(/-$/, ""),
                                    );
                            }}
                        />
                        <Suspense fallback={<Loading />}>
                            <Writer
                                key={page.id ?? "new"}
                                initialContent={page.content}
                                onChange={(content) =>
                                    update("content", content)
                                }
                            />
                        </Suspense>
                    </div>
                )}
                {tab === "Page settings" && (
                    <div className="settings-form">
                        <div className="form-grid">
                            <Field
                                label="Page title"
                                value={page.title}
                                onChange={(e) =>
                                    update("title", e.target.value)
                                }
                            />
                            <Field
                                label="URL slug"
                                value={page.slug}
                                onChange={(e) => {
                                    setSlugEdited(true);
                                    update("slug", e.target.value);
                                }}
                                hint="Published URL changes create a permanent redirect."
                            />
                            <SelectField
                                label="Page type"
                                value={page.type}
                                onChange={(e) => update("type", e.target.value)}
                            >
                                {pageTypes.map((t) => (
                                    <option key={t} value={t}>
                                        {label(t)}
                                    </option>
                                ))}
                            </SelectField>
                            <SelectField
                                label="Language and region"
                                value={page.locale}
                                onChange={(e) =>
                                    update("locale", e.target.value)
                                }
                            >
                                <option value="en-US">English (US)</option>
                                <option value="en-CA">English (Canada)</option>
                                <option value="fr-CA">French (Canada)</option>
                            </SelectField>
                            <Field
                                label="Translation group"
                                value={page.translation_group ?? ""}
                                onChange={(e) =>
                                    update("translation_group", e.target.value)
                                }
                                hint="Use the same group for genuinely translated versions."
                            />
                        </div>
                        <TextField
                            label="Page summary"
                            value={page.excerpt ?? ""}
                            onChange={(e) => update("excerpt", e.target.value)}
                        />
                    </div>
                )}
                {tab === "SEO" && (
                    <div className="settings-form">
                        <h2>Search appearance</h2>
                        <p className="muted">
                            Clear descriptions help people understand what is on
                            this page.
                        </p>
                        <Field
                            label="SEO title"
                            value={page.seo?.title ?? ""}
                            placeholder={page.title}
                            onChange={(e) => seo("title", e.target.value)}
                        />
                        <TextField
                            label="Meta description"
                            value={page.seo?.description ?? ""}
                            onChange={(e) => seo("description", e.target.value)}
                        />
                        <Field
                            label="Canonical URL"
                            type="url"
                            placeholder="Automatically generated from this page"
                            value={page.seo?.canonical ?? ""}
                            onChange={(e) => seo("canonical", e.target.value)}
                        />
                        <SelectField
                            label="Search visibility"
                            value={page.seo?.robots ?? "index,follow"}
                            onChange={(e) => seo("robots", e.target.value)}
                        >
                            <option value="index,follow">
                                Allow indexing and link discovery
                            </option>
                            <option value="noindex,follow">
                                Hide from search, allow link discovery
                            </option>
                            <option value="noindex,nofollow">
                                Hide from search and link discovery
                            </option>
                        </SelectField>
                        <div className="search-preview">
                            <small>
                                {window.location.origin} / p / {page.slug}
                            </small>
                            <h3>
                                {page.seo?.title ||
                                    page.title ||
                                    "Your page title"}
                            </h3>
                            <p>
                                {page.seo?.description ||
                                    page.excerpt ||
                                    "Add a useful description of this page."}
                            </p>
                            <small>Approximate search preview</small>
                        </div>
                    </div>
                )}
                {tab === "Social" && (
                    <div className="settings-form">
                        <h2>Social sharing</h2>
                        <Field
                            label="Share title"
                            value={page.seo?.og_title ?? ""}
                            placeholder={page.title}
                            onChange={(e) => seo("og_title", e.target.value)}
                        />
                        <TextField
                            label="Share description"
                            value={page.seo?.og_description ?? ""}
                            onChange={(e) =>
                                seo("og_description", e.target.value)
                            }
                        />
                        <Field
                            label="Upload social image"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            onChange={async (e) => {
                                const file = e.target.files?.[0];
                                if (!file) return;
                                try {
                                    const form = new FormData();
                                    form.append("file", file);
                                    form.append("visibility", "draft");
                                    const r = await api("files", "POST", form);
                                    seo("og_image", `/files/${r.data.id}`);
                                    onToast("Social image uploaded");
                                } catch (e) {
                                    setError((e as Error).message);
                                }
                            }}
                        />
                        <Field
                            label="Social image URL"
                            value={page.seo?.og_image ?? ""}
                            onChange={(e) => seo("og_image", e.target.value)}
                        />
                        <Field
                            label="Image description"
                            value={page.seo?.og_image_alt ?? ""}
                            onChange={(e) =>
                                seo("og_image_alt", e.target.value)
                            }
                        />
                        <SelectField
                            label="X card layout"
                            value={
                                page.seo?.twitter_card ?? "summary_large_image"
                            }
                            onChange={(e) =>
                                seo("twitter_card", e.target.value)
                            }
                        >
                            <option value="summary_large_image">
                                Large image
                            </option>
                            <option value="summary">Summary</option>
                        </SelectField>
                        <div className="social-preview">
                            {page.seo?.og_image && (
                                <img
                                    src={page.seo.og_image}
                                    alt={
                                        page.seo.og_image_alt ??
                                        "Social preview"
                                    }
                                />
                            )}
                            <div>
                                <small>{window.location.hostname}</small>
                                <h3>
                                    {page.seo?.og_title ||
                                        page.title ||
                                        "Your page title"}
                                </h3>
                                <p>
                                    {page.seo?.og_description || page.excerpt}
                                </p>
                            </div>
                        </div>
                    </div>
                )}
                {tab === "Schema" && (
                    <div className="settings-form">
                        <h2>Structured data</h2>
                        <p className="muted">
                            Mark up information that is visible and accurate on
                            this page.
                        </p>
                        <SelectField
                            label="Schema mode"
                            value={page.seo?.schema_mode ?? "auto"}
                            onChange={(e) => seo("schema_mode", e.target.value)}
                        >
                            <option value="auto">
                                Automatic, based on page type
                            </option>
                            <option value="custom">Custom JSON-LD</option>
                            <option value="off">Off for this page</option>
                        </SelectField>
                        {page.seo?.schema_mode === "auto" && (
                            <div className="notice info">
                                <FileText size={19} />
                                <span>
                                    The CMS generates structured data for{" "}
                                    {label(page.type)} pages using your verified
                                    business details.
                                </span>
                            </div>
                        )}
                        {page.seo?.schema_mode === "custom" && (
                            <SchemaEditor
                                value={page.seo?.schema_custom ?? {}}
                                onChange={(v) => seo("schema_custom", v)}
                            />
                        )}
                    </div>
                )}
                {tab === "Layout" && (
                    <Suspense fallback={<Loading />}>
                        <LayoutDesigner
                            initialProject={page.layout}
                            onChange={(project) => update("layout", project)}
                        />
                    </Suspense>
                )}
                {tab === "Revisions" && (
                    <div className="settings-form">
                        <h2>Revision history</h2>
                        {!page.id ? (
                            <p className="muted">
                                Save this page to start its revision history.
                            </p>
                        ) : revisions === null ? (
                            <Loading />
                        ) : revisions.length ? (
                            revisions.map((r, i) => (
                                <div className="list-row" key={r.id ?? i}>
                                    <span>
                                        <strong>
                                            Revision{" "}
                                            {r.page_version ??
                                                r.version ??
                                                r.revision ??
                                                i + 1}
                                        </strong>
                                        <small>{r.title ?? page.title}</small>
                                    </span>
                                    <span>
                                        {date(r.created_at, {
                                            month: "short",
                                            day: "numeric",
                                            hour: "numeric",
                                            minute: "2-digit",
                                        })}
                                    </span>
                                </div>
                            ))
                        ) : (
                            <Empty
                                title="No previous revisions"
                                description="Previous versions appear when this page is updated."
                            />
                        )}
                    </div>
                )}
            </div>
        </>
    );
}
function SchemaEditor({
    value,
    onChange,
}: {
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const [raw, setRaw] = useState(JSON.stringify(value, null, 2));
    const [error, setError] = useState("");
    return (
        <>
            <TextField
                className="code-input"
                label="JSON-LD"
                rows={14}
                value={raw}
                onChange={(e) => {
                    setRaw(e.target.value);
                    try {
                        const parsed = JSON.parse(e.target.value);
                        onChange(parsed);
                        setError("");
                    } catch {
                        setError("Enter valid JSON before saving.");
                    }
                }}
            />
            <ErrorNotice message={error} />
        </>
    );
}
export function Themes({
    onToast,
    themePage,
    themeId,
}: {
    onToast: (t: string) => void;
    themePage?: string;
    themeId?: string;
}) {
    if (themePage === "upload") return <ThemeUpload />;
    if (themePage === "settings")
        return <ThemeSettingsPage key={themeId} themeId={themeId} />;
    return <ThemeLibrary onToast={onToast} />;
}
function ThemeLibrary({ onToast }: { onToast: (t: string) => void }) {
    const [themes, setThemes] = useState<RecordData[]>([]);
    const [active, setActive] = useState<string | null>(null);
    const [error, setError] = useState("");
    const [saved, setSaved] = useState("");
    const [canRollback, setCanRollback] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(true);
    async function load() {
        try {
            const r = await api("themes");
            setThemes(r.data);
            setActive(r.active_theme ?? null);
            setCanRollback(r.can_rollback === true);
            setLoaded(true);
            return true;
        } catch (e) {
            setError((e as Error).message);
            return false;
        } finally {
            setLoading(false);
        }
    }
    useEffect(() => {
        load();
    }, []);
    async function changeTheme(
        action: "activate" | "rollback" | "default",
        id?: string,
    ) {
        setBusy(true);
        setError("");
        setSaved("");
        try {
            await api(
                action === "activate"
                    ? `themes/${id}/activate`
                    : `themes/${action}`,
                "POST",
                {},
            );
            if (await load()) {
                setSaved(
                    action === "default"
                        ? "Built-in theme activated."
                        : action === "rollback"
                          ? "Previous theme restored."
                          : "Theme activated.",
                );
            }
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>Website themes</h1>
                    <p>A familiar business, with a look that is all yours.</p>
                </div>
                <div className="heading-actions">
                    <a
                        className="button secondary"
                        href="/api/v1/themes/starter"
                    >
                        Download starter ZIP
                    </a>
                    <Button
                        variant="secondary"
                        busy={busy}
                        disabled={!canRollback || !loaded}
                        onClick={() => changeTheme("rollback")}
                    >
                        <RotateCcw size={15} />
                        Restore previous
                    </Button>
                    <a className="button primary" href="/app/themes/upload">
                        <Upload size={16} />
                        Upload theme ZIP
                    </a>
                </div>
            </div>
            <ErrorNotice message={error} />
            {saved && <Saved>{saved}</Saved>}
            {loading ? (
                <Loading />
            ) : !loaded ? (
                <Button
                    variant="secondary"
                    onClick={() => {
                        setLoading(true);
                        setError("");
                        void load();
                    }}
                >
                    Try again
                </Button>
            ) : (
                <div className="theme-grid">
                    <article
                        className={`theme-card ${active === null ? "active" : ""}`}
                    >
                        <div className="theme-preview" aria-hidden="true">
                            <div className="mini-page">
                                <div className="mini-hero">
                                    <div>
                                        <i />
                                        <i />
                                        <i />
                                        <b />
                                    </div>
                                    <div className="mini-picture">
                                        <LayoutTemplate size={40} />
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="theme-info">
                            <div>
                                <h2>Built-in contractor theme</h2>
                                <p>Included with your CMS</p>
                            </div>
                            {active === null && (
                                <Badge tone="green">Active</Badge>
                            )}
                        </div>
                        <div className="theme-info">
                            <div>
                                <p>Guided homepage · Managed navigation</p>
                                <p className="muted">
                                    Edit your sections, colors, fonts, header,
                                    and footer in website settings.
                                </p>
                            </div>
                        </div>
                        <div className="theme-actions">
                            <a
                                className="button secondary"
                                href="/app/website/appearance"
                            >
                                Website appearance
                            </a>
                            {active !== null && (
                                <Button
                                    busy={busy}
                                    onClick={() => changeTheme("default")}
                                >
                                    Use built-in theme
                                </Button>
                            )}
                        </div>
                    </article>
                    {themes.map((theme) => (
                        <article
                            className={`theme-card ${active === theme.id ? "active" : ""}`}
                            key={theme.id}
                        >
                            <div className="theme-preview">
                                <div className="mini-browser">
                                    <span />
                                    <span />
                                    <span />
                                </div>
                                <div className="mini-page">
                                    <div className="mini-nav">
                                        <span />
                                        <span />
                                    </div>
                                    <div className="mini-hero">
                                        <div>
                                            <i />
                                            <i />
                                            <i />
                                            <b />
                                        </div>
                                        <div className="mini-picture">
                                            <LayoutTemplate size={40} />
                                        </div>
                                    </div>
                                    <div className="mini-columns">
                                        <span />
                                        <span />
                                        <span />
                                    </div>
                                </div>
                            </div>
                            <div className="theme-info">
                                <div>
                                    <h2>
                                        {theme.name ?? theme.manifest?.name}
                                    </h2>
                                    <p>
                                        Version{" "}
                                        {theme.theme_version ??
                                            theme.manifest?.version ??
                                            theme.version}
                                    </p>
                                </div>
                                {active === theme.id && (
                                    <Badge tone="green">Active</Badge>
                                )}
                            </div>
                            <div className="theme-info">
                                <div>
                                    <p>
                                        {theme.manifest?.capabilities?.includes(
                                            "guided-homepage",
                                        )
                                            ? "Guided homepage supported"
                                            : "Expert homepage layouts only"}
                                    </p>
                                    <p>
                                        {theme.manifest?.capabilities?.includes(
                                            "managed-navigation",
                                        )
                                            ? "Header and footer follow website settings"
                                            : "Header and footer are controlled by this theme"}
                                    </p>
                                    {!theme.manifest?.capabilities?.includes(
                                        "guided-homepage",
                                    ) && (
                                        <p className="muted">
                                            Requires an expert homepage. Publish
                                            a guided homepage with the built-in
                                            theme or the current Workshop
                                            starter.
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="theme-actions">
                                <a
                                    className="button secondary"
                                    target="_blank"
                                    rel="noreferrer"
                                    href={`/api/v1/themes/${theme.id}/preview`}
                                >
                                    <ExternalLink size={14} />
                                    Preview
                                </a>
                                <a
                                    className="button secondary"
                                    href={`/app/themes/${encodeURIComponent(theme.id)}/settings`}
                                >
                                    Configure
                                </a>
                                {active !== theme.id && (
                                    <Button
                                        busy={busy}
                                        onClick={() =>
                                            changeTheme("activate", theme.id)
                                        }
                                    >
                                        Activate theme
                                    </Button>
                                )}
                            </div>
                        </article>
                    ))}
                    {!themes.length && (
                        <Empty
                            title="Start with your own theme"
                            description="Upload a compatible theme ZIP, preview it, and activate it when it looks right."
                            action={
                                <a
                                    className="button secondary"
                                    href="/app/themes/upload"
                                >
                                    Upload a theme
                                </a>
                            }
                        />
                    )}
                </div>
            )}
            <section className="theme-help">
                <LayoutTemplate size={22} />
                <div>
                    <h3>Your content stays with you</h3>
                    <p>
                        Themes style your public website. Pages, SEO settings,
                        customer records, and your team workspace stay in the
                        CMS.
                    </p>
                    <p className="muted">
                        Packages support Twig templates, CSS, images, fonts, and
                        section definitions. Maximum compressed size: 25 MB.
                    </p>
                </div>
            </section>
        </>
    );
}

function SeoSettings() {
    const state = useData<any>("/api/v1/seo/settings");
    const diagnostics = useData<RecordData[]>("/api/v1/seo/diagnostics");
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState("");
    const [image, setImage] = useState("");
    useEffect(() => {
        if (state.data?.og_image) setImage(state.data.og_image);
    }, [state.data]);
    return (
        <>
            <a href="/app/pages" className="text-link settings-back">
                <ArrowLeft size={16} /> Website & content
            </a>
            <div className="page-heading">
                <div>
                    <h1>Website SEO settings</h1>
                    <p>
                        Set useful defaults, verify your site, and review
                        publishing issues.
                    </p>
                </div>
            </div>
            <section className="panel settings-edit-page">
                <div className="settings-form">
                    {saved && <Saved>SEO defaults saved.</Saved>}
                    {state.loading ? (
                        <Loading />
                    ) : state.error ? (
                        <ErrorNotice message={state.error} />
                    ) : (
                        <form
                            onChange={() => setSaved(false)}
                            onSubmit={async (e) => {
                                e.preventDefault();
                                setBusy(true);
                                setError("");
                                setSaved(false);
                                try {
                                    await api("seo/settings", "PUT", {
                                        ...formValues(e.currentTarget),
                                        og_image: image,
                                    });
                                    setSaved(true);
                                    diagnostics.reload();
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            <ErrorNotice message={error || state.error} />
                            <TextField
                                label="Default description"
                                name="description"
                                defaultValue={state.data?.description ?? ""}
                                maxLength={500}
                            />
                            <Field
                                label="Google Search Console verification token"
                                name="google_verification"
                                defaultValue={
                                    state.data?.google_verification ?? ""
                                }
                                hint="Paste the content value from Google's verification meta tag."
                            />
                            <Field
                                label="Default social image"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={async (e) => {
                                    const file = e.target.files?.[0];
                                    if (!file) return;
                                    try {
                                        const form = new FormData();
                                        form.append("file", file);
                                        form.append("visibility", "public");
                                        const r = await api(
                                            "files",
                                            "POST",
                                            form,
                                        );
                                        setImage(`/files/${r.data.id}`);
                                    } catch (e) {
                                        setError((e as Error).message);
                                    }
                                }}
                            />
                            {image && (
                                <img
                                    src={image}
                                    className="seo-image-preview"
                                    alt="Default social sharing preview"
                                />
                            )}
                            <Field
                                label="Social image description"
                                name="og_image_alt"
                                defaultValue={state.data?.og_image_alt ?? ""}
                            />
                            <div className="form-footer">
                                <a
                                    className="button secondary"
                                    href="/sitemap.xml"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    View sitemap
                                </a>
                                <Button busy={busy}>Save SEO defaults</Button>
                            </div>
                        </form>
                    )}
                    <div className="seo-diagnostics">
                        <h3>Publishing diagnostics</h3>
                        <ErrorNotice message={diagnostics.error} />
                        {diagnostics.loading ? (
                            <Loading />
                        ) : diagnostics.data?.length ? (
                            diagnostics.data.map((issue, index) => (
                                <div className="list-row" key={index}>
                                    <span>
                                        <strong>{label(issue.type)}</strong>
                                        <small>{issue.message}</small>
                                    </span>
                                </div>
                            ))
                        ) : (
                            <div className="notice success">
                                <Check size={16} />
                                No issues found in published pages.
                            </div>
                        )}
                    </div>
                </div>
            </section>
        </>
    );
}

function ThemeUpload() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [uploaded, setUploaded] = useState<RecordData | null>(null);
    return (
        <>
            <a href="/app/themes" className="text-link settings-back">
                <ArrowLeft size={16} /> Website themes
            </a>
            <div className="page-heading">
                <div>
                    <h1>Upload a theme</h1>
                    <p>
                        Add your theme package, then preview and activate it
                        from the library.
                    </p>
                </div>
            </div>
            <section className="panel settings-edit-page">
                <form
                    className="settings-form"
                    onSubmit={async (event) => {
                        event.preventDefault();
                        const form = event.currentTarget;
                        const file = (
                            form.elements.namedItem("file") as HTMLInputElement
                        ).files?.[0];
                        if (!file) return;
                        setError("");
                        setUploaded(null);
                        if (file.size > 25 * 1024 * 1024) {
                            setError("Choose a theme ZIP smaller than 25 MB.");
                            return;
                        }
                        setBusy(true);
                        try {
                            const body = new FormData();
                            body.append("file", file);
                            const response = await api("themes", "POST", body);
                            setUploaded(response.data);
                            form.reset();
                        } catch (error) {
                            setError((error as Error).message);
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    <ErrorNotice message={error} />
                    {uploaded && (
                        <Saved>
                            Theme uploaded and validated. Open the theme library
                            to preview and activate it.
                        </Saved>
                    )}
                    <Field
                        label="Theme ZIP"
                        name="file"
                        type="file"
                        accept=".zip,application/zip"
                        required
                        onChange={() => setUploaded(null)}
                    />
                    <ul className="theme-upload-requirements">
                        <li>Maximum compressed size: 25 MB.</li>
                        <li>
                            Include a theme manifest and supported templates,
                            CSS, images, and fonts.
                        </li>
                        <li>
                            Uploaded themes are validated before they become
                            available in your library.
                        </li>
                    </ul>
                    <div className="form-footer">
                        <a
                            className="button secondary"
                            href="/api/v1/themes/starter"
                        >
                            Download starter ZIP
                        </a>
                        <Button busy={busy}>
                            <Upload size={16} /> Upload and validate
                        </Button>
                    </div>
                    {uploaded && (
                        <p>
                            <a className="text-link" href="/app/themes">
                                Open theme library <ArrowUpRight size={15} />
                            </a>
                        </p>
                    )}
                </form>
            </section>
        </>
    );
}
function ThemeSettingsPage({ themeId }: { themeId?: string }) {
    const state = useData<RecordData[]>("/api/v1/themes");
    const theme = state.data?.find((item) => item.id === themeId);
    return (
        <>
            <a href="/app/themes" className="text-link settings-back">
                <ArrowLeft size={16} /> Website themes
            </a>
            <div className="page-heading">
                <div>
                    <h1>
                        {theme
                            ? `Configure ${theme.name ?? theme.manifest?.name ?? "theme"}`
                            : "Theme settings"}
                    </h1>
                    <p>Adjust the options included with this theme.</p>
                </div>
                {theme && (
                    <a
                        className="button secondary"
                        href={`/api/v1/themes/${encodeURIComponent(theme.id)}/preview`}
                        target="_blank"
                        rel="noreferrer"
                    >
                        <ExternalLink size={15} /> Preview theme
                    </a>
                )}
            </div>
            <section className="panel settings-edit-page">
                <div className="settings-form">
                    {state.loading ? (
                        <Loading />
                    ) : state.error ? (
                        <ErrorNotice message={state.error} />
                    ) : theme ? (
                        <ThemeSettings key={theme.id} theme={theme} />
                    ) : (
                        <ErrorNotice message="This theme could not be found. Return to the theme library to choose an available theme." />
                    )}
                </div>
            </section>
        </>
    );
}
function ThemeSettings({ theme }: { theme: RecordData }) {
    const state = useData<any>(`/api/v1/themes/${theme.id}/settings`);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [saved, setSaved] = useState(false);
    const fields = Object.entries(state.data?.schema?.properties ?? {}) as [
        string,
        any,
    ][];
    return (
        <>
            {saved && <Saved>Theme settings saved.</Saved>}
            <ErrorNotice message={state.error || error} />
            {state.loading ? (
                <Loading />
            ) : (
                state.data && (
                    <form
                        onChange={() => setSaved(false)}
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError("");
                            setSaved(false);
                            const form = new FormData(e.currentTarget);
                            const values = Object.fromEntries(
                                fields.map(([key, field]) => [
                                    key,
                                    field.type === "boolean"
                                        ? form.has(key)
                                        : field.type === "integer"
                                          ? Number(form.get(key))
                                          : String(form.get(key) ?? ""),
                                ]),
                            );
                            try {
                                const result = await api(
                                    `themes/${theme.id}/settings`,
                                    "PUT",
                                    {
                                        values,
                                        expected_version: state.data.version,
                                    },
                                );
                                state.setData(result.data);
                                setSaved(true);
                            } catch (e) {
                                setError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {fields.length ? (
                            fields.map(([key, field]) => {
                                const value =
                                    state.data.values?.[key] ?? field.default;
                                const title = field.title ?? label(key);
                                const required =
                                    state.data.schema.required?.includes(key) ??
                                    false;
                                if (field.type === "boolean")
                                    return (
                                        <label
                                            key={key}
                                            className="check-field"
                                        >
                                            <input
                                                name={key}
                                                type="checkbox"
                                                defaultChecked={Boolean(value)}
                                            />
                                            {title}
                                        </label>
                                    );
                                if (Array.isArray(field.enum))
                                    return (
                                        <SelectField
                                            key={key}
                                            name={key}
                                            label={title}
                                            defaultValue={value ?? ""}
                                            required={required}
                                        >
                                            {!required && (
                                                <option value="">
                                                    Use default
                                                </option>
                                            )}
                                            {field.enum.map((option: any) => (
                                                <option
                                                    key={String(option)}
                                                    value={String(option)}
                                                >
                                                    {String(option)}
                                                </option>
                                            ))}
                                        </SelectField>
                                    );
                                return (
                                    <Field
                                        key={key}
                                        label={title}
                                        name={key}
                                        type={
                                            field.format === "color"
                                                ? "color"
                                                : field.type === "integer"
                                                  ? "number"
                                                  : "text"
                                        }
                                        defaultValue={
                                            value ??
                                            (field.format === "color"
                                                ? "#2563eb"
                                                : "")
                                        }
                                        required={required}
                                        min={field.minimum}
                                        max={field.maximum}
                                        maxLength={field.maxLength}
                                        step={
                                            field.type === "integer"
                                                ? 1
                                                : undefined
                                        }
                                        hint={field.description}
                                    />
                                );
                            })
                        ) : (
                            <p className="muted">
                                This theme has no configurable options.
                            </p>
                        )}
                        <div className="form-footer">
                            <a className="button secondary" href="/app/themes">
                                Back to themes
                            </a>
                            {fields.length > 0 && (
                                <Button busy={busy}>Save theme settings</Button>
                            )}
                        </div>
                    </form>
                )
            )}
        </>
    );
}
