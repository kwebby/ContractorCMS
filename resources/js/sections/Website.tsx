/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { lazy, Suspense, useEffect, useState, type ReactNode } from "react";
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Check,
    Copy,
    ExternalLink,
    GripVertical,
    Plus,
    Save,
    Trash2,
} from "lucide-react";
import {
    api,
    ApiError,
    date,
    label,
    localDateTime,
    type RecordData,
} from "../api";
import {
    Badge,
    Button,
    Empty,
    ErrorNotice,
    Field,
    Loading,
    Saved,
    SelectField,
    TextField,
    useData,
} from "../components/ui";
import "../../css/website-settings.css";
const Writer = lazy(() => import("../components/Writer"));

export type WebsitePage =
    | "homepage"
    | "appearance"
    | "navigation"
    | "local-seo"
    | "business-profile"
    | "locations"
    | "location"
    | "page-plan"
    | "citations";
type Data = Record<string, any>;
type ImageData = {
    media_id: string;
    alt: string;
    decorative: boolean;
    focal_x: number;
    focal_y: number;
};
type Section = {
    id: string;
    type: string;
    enabled: boolean;
    variant: string;
    eyebrow: string;
    heading: string;
    body: any[];
    image?: ImageData | null;
    buttons: Data[];
    items: Data[];
    tool_key?: string;
};
const sectionTypes = [
    "hero",
    "trust",
    "services",
    "about",
    "process",
    "projects",
    "reviews",
    "service_areas",
    "faq",
    "contact",
    "offers",
    "financing",
    "maintenance",
    "articles",
    "lead_tool",
    "careers",
];
const sectionNames: Record<string, string> = {
    hero: "Hero",
    trust: "Credentials & trust",
    services: "Services",
    about: "About & differentiators",
    process: "Work process",
    projects: "Featured projects",
    reviews: "Customer reviews",
    service_areas: "Service areas",
    faq: "Frequently asked questions",
    contact: "Contact & request form",
    offers: "Offers",
    financing: "Financing",
    maintenance: "Maintenance plans",
    articles: "Articles",
    lead_tool: "Lead tool",
    careers: "Careers",
};
const sectionHints: Record<string, string> = {
    hero: "Introduce your business with one clear headline and a useful next step.",
    trust: "Only include credentials and memberships your business holds.",
    reviews:
        "Use attributed testimony with permission. Ratings are not generated from these quotes.",
    service_areas:
        "List places you serve. These do not create physical branches.",
    contact:
        "Your canonical contact details and secure service request form appear here.",
    offers: "Enter genuine offers and their eligibility. Expired offers are hidden.",
    financing: "Describe actual availability and link to the provider's terms.",
    lead_tool:
        "Choose a configured application tool. Disabled tools remain unavailable.",
    careers: "Only describe genuine employment opportunities.",
};
const route = (page: WebsitePage) =>
    [
        "business-profile",
        "locations",
        "location",
        "page-plan",
        "citations",
    ].includes(page)
        ? `/app/website/local-seo/${page === "location" ? "locations" : page}`
        : `/app/website/${page}`;
const pageNames: Record<WebsitePage, string> = {
    homepage: "Homepage",
    appearance: "Colors & typography",
    navigation: "Navigation",
    "local-seo": "Local SEO",
    "business-profile": "Public business profile",
    locations: "Business locations",
    location: "Location details",
    "page-plan": "Page coverage",
    citations: "Listing observations",
};
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
const newId = () => `section-${crypto.randomUUID().slice(0, 12)}`;
const defaultProfile = (): Data => ({
    name: "",
    trade: "GeneralContractor",
    phone: "",
    email: "",
    address_visibility: "hidden",
    address: "",
    city: "",
    region: "",
    postal_code: "",
    country: "US",
    timezone: "America/New_York",
    service_areas: [],
    hours: [],
    special_hours: [],
    profile_urls: [],
});

export default function Website({
    page = "homepage",
    locationId,
}: {
    page?: WebsitePage;
    locationId?: string;
}) {
    const [dirty, setDirty] = useState(false);
    const [navigationNotice, setNavigationNotice] = useState("");
    return (
        <div className="website-workspace">
            <a
                className="text-link settings-back"
                href="/app/pages"
                onClick={(e) => {
                    if (dirty) {
                        e.preventDefault();
                        setNavigationNotice(
                            "Save your changes before leaving this editor.",
                        );
                    }
                }}
            >
                <ArrowLeft size={16} /> Website & content
            </a>
            <div className="page-heading">
                <div>
                    <h1>{pageNames[page]}</h1>
                    <p>Build a clear, useful website for your customers.</p>
                </div>
                <a
                    href="/"
                    target="_blank"
                    rel="noreferrer"
                    className="button secondary"
                >
                    <ExternalLink size={15} /> View website
                </a>
            </div>
            <nav className="website-nav" aria-label="Website settings">
                {(
                    [
                        "homepage",
                        "appearance",
                        "navigation",
                        "local-seo",
                    ] as WebsitePage[]
                ).map((item) => (
                    <a
                        key={item}
                        href={route(item)}
                        aria-current={
                            page === item ||
                            (item === "local-seo" &&
                                [
                                    "business-profile",
                                    "locations",
                                    "location",
                                    "page-plan",
                                    "citations",
                                ].includes(page))
                                ? "page"
                                : undefined
                        }
                        onClick={(e) => {
                            if (dirty) {
                                e.preventDefault();
                                setNavigationNotice(
                                    "Save your changes before opening another settings page.",
                                );
                            }
                        }}
                    >
                        {pageNames[item]}
                    </a>
                ))}
                <a
                    href="/app/website/seo"
                    onClick={(e) => {
                        if (dirty) {
                            e.preventDefault();
                            setNavigationNotice(
                                "Save your changes before opening SEO settings.",
                            );
                        }
                    }}
                >
                    SEO & social tags
                </a>
            </nav>
            <ErrorNotice
                message={navigationNotice && dirty ? navigationNotice : ""}
            />
            {page === "homepage" ? (
                <HomepageEditor onDirty={setDirty} />
            ) : page === "local-seo" || page === "page-plan" ? (
                <LocalSeo page={page} />
            ) : page === "citations" ? (
                <Citations onDirty={setDirty} />
            ) : (
                <SettingsEditor
                    key={`${page}-${locationId}`}
                    page={page}
                    locationId={locationId}
                    onDirty={setDirty}
                />
            )}
        </div>
    );
}

function Validation({ error }: { error: Error | null }) {
    return (
        <>
            <ErrorNotice message={error?.message ?? ""} />
            {error instanceof ApiError &&
                Object.keys(error.errors).length > 0 && (
                    <ul
                        className="website-field-errors"
                        aria-label="Fields to review"
                    >
                        {Object.entries(error.errors).map(
                            ([field, messages]) => (
                                <li key={field}>
                                    <strong>{label(field)}</strong>:{" "}
                                    {messages.join(" ")}
                                </li>
                            ),
                        )}
                    </ul>
                )}
        </>
    );
}
function Group({
    title,
    hint,
    children,
}: {
    title: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <section className="website-group">
            <header>
                <h2>{title}</h2>
                {hint && <p>{hint}</p>}
            </header>
            {children}
        </section>
    );
}
function CheckField({
    caption,
    checked,
    onChange,
}: {
    caption: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <label className="check-field">
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
            />
            {caption}
        </label>
    );
}
function Choice({
    caption,
    value,
    options,
    onChange,
}: {
    caption: string;
    value: string;
    options: string[];
    onChange: (value: string) => void;
}) {
    return (
        <SelectField
            label={caption}
            value={value}
            onChange={(e) => onChange(e.target.value)}
        >
            {options.map((option) => (
                <option value={option} key={option}>
                    {label(option)}
                </option>
            ))}
        </SelectField>
    );
}
function TextInput({
    caption,
    value,
    onChange,
    ...props
}: {
    caption: string;
    value?: string;
    onChange: (value: string) => void;
} & Omit<React.InputHTMLAttributes<HTMLInputElement>, "value" | "onChange">) {
    return (
        <Field
            label={caption}
            value={value ?? ""}
            onChange={(e) => onChange(e.target.value)}
            {...props}
        />
    );
}
function ImageEditor({
    image,
    onChange,
    caption = "Image",
}: {
    image?: ImageData | null;
    onChange: (value: ImageData | null) => void;
    caption?: string;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    return (
        <div className="website-image-editor">
            <Field
                label={`${caption} upload`}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                disabled={busy}
                onChange={async (e) => {
                    const file = e.target.files?.[0];
                    if (!file) return;
                    setBusy(true);
                    setError("");
                    try {
                        const form = new FormData();
                        form.append("file", file);
                        form.append("visibility", "draft");
                        const result = await api("files", "POST", form);
                        onChange({
                            media_id: result.data.id,
                            alt: image?.alt ?? "",
                            decorative: image?.decorative ?? false,
                            focal_x: image?.focal_x ?? 50,
                            focal_y: image?.focal_y ?? 50,
                        });
                    } catch (error) {
                        setError((error as Error).message);
                    } finally {
                        setBusy(false);
                        e.target.value = "";
                    }
                }}
                hint="PNG, JPEG or WebP. New uploads stay private until publication."
            />
            <ErrorNotice message={error} />
            {busy && <p role="status">Uploading image…</p>}
            {image?.media_id && (
                <>
                    <div className="website-image-preview">
                        <img
                            src={`/files/${image.media_id}`}
                            alt={image.alt || caption}
                            style={{
                                objectPosition: `${image.focal_x}% ${image.focal_y}%`,
                            }}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onChange(null)}
                        >
                            <Trash2 size={14} /> Remove image
                        </Button>
                    </div>
                    <TextInput
                        caption={`${caption} description`}
                        value={image.alt}
                        onChange={(alt) => onChange({ ...image, alt })}
                        disabled={image.decorative}
                        maxLength={300}
                    />
                    <CheckField
                        caption="This image is decorative"
                        checked={image.decorative}
                        onChange={(decorative) =>
                            onChange({
                                ...image,
                                decorative,
                                alt: decorative ? "" : image.alt,
                            })
                        }
                    />
                    <div className="website-grid">
                        <Field
                            label="Horizontal focal point"
                            type="range"
                            min={0}
                            max={100}
                            value={image.focal_x}
                            onChange={(e) =>
                                onChange({
                                    ...image,
                                    focal_x: Number(e.target.value),
                                })
                            }
                        />
                        <Field
                            label="Vertical focal point"
                            type="range"
                            min={0}
                            max={100}
                            value={image.focal_y}
                            onChange={(e) =>
                                onChange({
                                    ...image,
                                    focal_y: Number(e.target.value),
                                })
                            }
                        />
                    </div>
                </>
            )}
        </div>
    );
}
function PageReference({
    value,
    onChange,
    pages,
    caption = "Linked page",
    required = false,
}: {
    value?: string;
    onChange: (value: string) => void;
    pages: RecordData[];
    caption?: string;
    required?: boolean;
}) {
    return (
        <SelectField
            label={caption}
            value={value ?? ""}
            onChange={(e) => onChange(e.target.value)}
            required={required}
        >
            <option value="">Choose a page</option>
            {pages.map((page) => (
                <option key={page.id} value={page.id}>
                    {page.title}
                    {page.status !== "published"
                        ? " (draft — publish before linking)"
                        : ""}
                </option>
            ))}
        </SelectField>
    );
}
function LinkEditor({
    link,
    onChange,
    pages,
    button = false,
    anchors = [],
}: {
    link: Data;
    onChange: (value: Data) => void;
    pages: RecordData[];
    button?: boolean;
    anchors?: string[];
}) {
    return (
        <div className="website-grid">
            <TextInput
                caption={button ? "Button label" : "Link label"}
                value={link.label}
                maxLength={60}
                onChange={(value) => onChange({ ...link, label: value })}
            />
            <Choice
                caption="Destination type"
                value={link.kind ?? "page"}
                options={
                    button
                        ? ["page", "url", "phone", "anchor"]
                        : ["page", "url"]
                }
                onChange={(kind) =>
                    onChange({
                        label: link.label ?? "",
                        kind,
                        ...(button ? { style: link.style ?? "primary" } : {}),
                        ...(kind === "page"
                            ? { page_id: "" }
                            : kind === "url"
                              ? { url: "" }
                              : kind === "anchor"
                                ? { anchor: "contact" }
                                : {}),
                    })
                }
            />
            {link.kind === "page" && (
                <PageReference
                    value={link.page_id}
                    pages={pages}
                    onChange={(page_id) => onChange({ ...link, page_id })}
                />
            )}
            {link.kind === "url" && (
                <TextInput
                    caption="Destination URL"
                    type="url"
                    value={link.url}
                    placeholder="https://example.com"
                    onChange={(url) => onChange({ ...link, url })}
                />
            )}
            {link.kind === "phone" && (
                <p className="website-help">
                    Uses the published business phone number.
                </p>
            )}
            {link.kind === "anchor" && (
                <SelectField
                    label="Page section"
                    value={link.anchor ?? "contact"}
                    onChange={(e) =>
                        onChange({ ...link, anchor: e.target.value })
                    }
                >
                    {[...new Set(["contact", ...anchors])].map((anchor) => (
                        <option key={anchor} value={anchor}>
                            {anchor}
                        </option>
                    ))}
                </SelectField>
            )}
            {button && (
                <Choice
                    caption="Button style"
                    value={link.style ?? "primary"}
                    options={["primary", "secondary", "text"]}
                    onChange={(style) => onChange({ ...link, style })}
                />
            )}
        </div>
    );
}

function HomepageEditor({ onDirty }: { onDirty: (dirty: boolean) => void }) {
    const source = useData<RecordData[]>("/api/v1/pages");
    const presets = useData<Data>("/api/v1/website/presets");
    const [page, setPage] = useState<Data | null>(null);
    const [selected, setSelected] = useState("");
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<Error | null>(null);
    const [notice, setNotice] = useState("");
    const [newType, setNewType] = useState("services");
    const [preset, setPreset] = useState("service");
    const [revisions, setRevisions] = useState<RecordData[] | null>(null);
    const [dragging, setDragging] = useState("");
    const [editorGeneration, setEditorGeneration] = useState(0);
    useEffect(() => {
        if (source.data && !page) {
            const home =
                source.data.find(
                    (item) =>
                        item.type === "home" && item.status === "published",
                ) ??
                source.data.find(
                    (item) =>
                        item.type === "home" && item.status !== "archived",
                );
            setPage(
                home
                    ? clone(home)
                    : {
                          title: "Home",
                          slug: "home",
                          type: "home",
                          locale: "en-US",
                          content: [],
                          layout: [],
                          seo: { robots: "index,follow", schema_mode: "auto" },
                          home_sections: {
                              schema_version: 1,
                              mode: "expert",
                              sections: [],
                          },
                      },
            );
        }
    }, [source.data, page]);
    useEffect(() => onDirty(dirty), [dirty, onDirty]);
    const document = page?.home_sections ?? {
        schema_version: 1,
        mode: "expert",
        sections: [],
    };
    const sections: Section[] = document.sections ?? [];
    const section =
        sections.find((item) => item.id === selected) ?? sections[0];
    function updatePage(patch: Data) {
        setPage((current) => ({ ...current, ...patch }));
        setDirty(true);
        setNotice("");
    }
    function updateSections(next: Section[]) {
        updatePage({ home_sections: { ...document, sections: next } });
    }
    function updateSection(next: Section) {
        updateSections(
            sections.map((item) => (item.id === next.id ? next : item)),
        );
    }
    function move(id: string, to: number) {
        const from = sections.findIndex((item) => item.id === id);
        if (from < 0 || to < 0 || to >= sections.length) return;
        const next = [...sections];
        next.splice(to, 0, next.splice(from, 1)[0]);
        updateSections(next);
    }
    async function save(publish = false) {
        if (!page) return;
        setBusy(true);
        setError(null);
        setNotice("");
        try {
            const saved = await api(
                `pages${page.id ? `/${page.id}` : ""}`,
                page.id ? "PUT" : "POST",
                { ...page, expected_version: page.version },
            );
            setPage(saved.data);
            setDirty(false);
            if (publish) {
                const published = await api(
                    `pages/${saved.data.id}/publish`,
                    "POST",
                    { expected_version: saved.data.version },
                );
                setPage(published.data);
            }
            setNotice(
                publish
                    ? "Homepage published."
                    : "Homepage draft saved. Preview it before publishing.",
            );
        } catch (error) {
            setError(error as Error);
        } finally {
            setBusy(false);
        }
    }
    if (source.loading || (!page && !source.error)) return <Loading />;
    if (source.error)
        return (
            <>
                <ErrorNotice message={source.error} />
                <Button onClick={source.reload}>Try again</Button>
            </>
        );
    if (!page) return null;
    return (
        <>
            <Validation error={error} />
            {notice && <Saved>{notice}</Saved>}
            {error instanceof ApiError && error.status === 409 && (
                <div className="website-conflict">
                    <p>
                        Your edits are still here. Open the latest saved page in
                        another tab to compare, or explicitly reload it.
                    </p>
                    <a
                        href="/app/website/homepage"
                        target="_blank"
                        rel="noreferrer"
                        className="text-link"
                    >
                        Compare saved version
                    </a>
                    <Button
                        variant="secondary"
                        onClick={async () => {
                            setBusy(true);
                            try {
                                const latest = await api("pages");
                                const saved = latest.data.find(
                                    (item: RecordData) => item.id === page.id,
                                );
                                if (!saved)
                                    throw new Error(
                                        "The saved homepage is no longer available.",
                                    );
                                setPage(clone(saved));
                                setDirty(false);
                                setError(null);
                                setEditorGeneration((value) => value + 1);
                            } catch (failure) {
                                setError(failure as Error);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        Discard my edits and reload
                    </Button>
                </div>
            )}
            <div className="website-action-bar">
                <span className="website-save-state" role="status">
                    {dirty
                        ? "Unsaved changes"
                        : `Saved draft${page.version ? ` · version ${page.version}` : ""}`}
                </span>
                <div>
                    {page.id && (
                        <a
                            className="button secondary"
                            href={`/api/v1/pages/${page.id}/preview`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <ExternalLink size={14} /> Preview saved draft
                        </a>
                    )}
                    <Button
                        variant="secondary"
                        busy={busy}
                        onClick={() => save()}
                    >
                        <Save size={14} /> Save draft
                    </Button>
                    <Button busy={busy} onClick={() => save(true)}>
                        Publish homepage
                    </Button>
                </div>
            </div>
            <fieldset className="website-fieldset" disabled={busy}>
                <div className="website-editor-options">
                    <TextInput
                        caption="Page title"
                        value={page.title}
                        onChange={(title) => updatePage({ title })}
                        maxLength={180}
                    />
                    <Choice
                        caption="Editing mode"
                        value={document.mode}
                        options={["guided", "expert"]}
                        onChange={(mode) =>
                            updatePage({ home_sections: { ...document, mode } })
                        }
                    />
                </div>
                {document.mode !== "guided" ? (
                    <section className="panel website-padding">
                        <h2>Expert layout is active</h2>
                        <p>
                            Your existing content and GrapesJS layout are
                            preserved. Select guided mode to build the homepage
                            using sections. Switching modes only changes the
                            draft until you publish.
                        </p>
                        <a href="/app/pages" className="text-link">
                            Open the page library for expert editing
                        </a>
                    </section>
                ) : (
                    <>
                        <div className="website-preset-bar">
                            <SelectField
                                label="Trade preset"
                                value={preset}
                                onChange={(e) => setPreset(e.target.value)}
                            >
                                {Object.entries(presets.data ?? {}).map(
                                    ([key, value]: [string, any]) => (
                                        <option value={key} key={key}>
                                            {value.label}
                                        </option>
                                    ),
                                )}
                            </SelectField>
                            <Button
                                variant="secondary"
                                disabled={!presets.data?.[preset]}
                                onClick={() => {
                                    const next = clone(
                                        presets.data![preset].document,
                                    );
                                    updatePage({ home_sections: next });
                                    setSelected(next.sections?.[0]?.id ?? "");
                                    setEditorGeneration((value) => value + 1);
                                }}
                            >
                                Replace sections with preset
                            </Button>
                            <small>
                                Replaces this draft's section list. Saved
                                revisions remain available.
                            </small>
                        </div>
                        <ErrorNotice message={presets.error} />
                        <div className="website-home-editor">
                            <aside
                                className="website-section-list"
                                aria-label="Homepage sections"
                            >
                                <header>
                                    <h2>
                                        Sections{" "}
                                        <small>
                                            {
                                                sections.filter(
                                                    (item) => item.enabled,
                                                ).length
                                            }{" "}
                                            active
                                        </small>
                                    </h2>
                                    <p>
                                        Drag to reorder or use the arrow
                                        buttons.
                                    </p>
                                </header>
                                {sections.map((item, index) => (
                                    <div
                                        key={item.id}
                                        className={`website-section-row ${section?.id === item.id ? "selected" : ""}`}
                                        draggable
                                        onDragStart={(e) => {
                                            setDragging(item.id);
                                            e.dataTransfer.setData(
                                                "text/plain",
                                                item.id,
                                            );
                                        }}
                                        onDragOver={(e) => e.preventDefault()}
                                        onDrop={(e) => {
                                            e.preventDefault();
                                            move(
                                                dragging ||
                                                    e.dataTransfer.getData(
                                                        "text/plain",
                                                    ),
                                                index,
                                            );
                                            setDragging("");
                                        }}
                                    >
                                        <GripVertical
                                            size={15}
                                            aria-hidden="true"
                                        />
                                        <button
                                            type="button"
                                            className="website-section-select"
                                            onClick={() => setSelected(item.id)}
                                        >
                                            <strong>
                                                {item.heading ||
                                                    sectionNames[item.type]}
                                            </strong>
                                            <small>
                                                {sectionNames[item.type]} ·{" "}
                                                {item.enabled
                                                    ? "Visible"
                                                    : "Hidden"}
                                            </small>
                                        </button>
                                        <div className="website-order-buttons">
                                            <button
                                                type="button"
                                                aria-label={`Move ${item.heading || sectionNames[item.type]} up`}
                                                disabled={index === 0}
                                                onClick={() =>
                                                    move(item.id, index - 1)
                                                }
                                            >
                                                <ArrowUp size={13} />
                                            </button>
                                            <button
                                                type="button"
                                                aria-label={`Move ${item.heading || sectionNames[item.type]} down`}
                                                disabled={
                                                    index ===
                                                    sections.length - 1
                                                }
                                                onClick={() =>
                                                    move(item.id, index + 1)
                                                }
                                            >
                                                <ArrowDown size={13} />
                                            </button>
                                        </div>
                                    </div>
                                ))}
                                <div className="website-add-section">
                                    <SelectField
                                        label="Section type"
                                        value={newType}
                                        onChange={(e) =>
                                            setNewType(e.target.value)
                                        }
                                    >
                                        {sectionTypes.map((type) => (
                                            <option value={type} key={type}>
                                                {sectionNames[type]}
                                            </option>
                                        ))}
                                    </SelectField>
                                    <Button
                                        variant="secondary"
                                        disabled={sections.length >= 24}
                                        onClick={() => {
                                            const item: Section = {
                                                id: newId(),
                                                type: newType,
                                                enabled:
                                                    ![
                                                        "hero",
                                                        "contact",
                                                    ].includes(newType) ||
                                                    !sections.some(
                                                        (item) =>
                                                            item.type ===
                                                                newType &&
                                                            item.enabled,
                                                    ),
                                                variant: "standard",
                                                eyebrow: "",
                                                heading: sectionNames[newType],
                                                body: [],
                                                buttons: [],
                                                items: [],
                                                ...(newType === "lead_tool"
                                                    ? {
                                                          tool_key:
                                                              "service-preparation",
                                                      }
                                                    : {}),
                                            };
                                            updateSections([...sections, item]);
                                            setSelected(item.id);
                                        }}
                                    >
                                        <Plus size={15} /> Add section
                                    </Button>
                                </div>
                            </aside>
                            {section ? (
                                <SectionEditor
                                    key={`${section.id}-${page.version ?? 0}-${editorGeneration}`}
                                    section={section}
                                    pages={source.data ?? []}
                                    anchors={sections.map((item) => item.id)}
                                    onChange={updateSection}
                                    onDuplicate={() => {
                                        const duplicate = {
                                            ...clone(section),
                                            id: newId(),
                                            enabled:
                                                section.type === "hero"
                                                    ? false
                                                    : section.enabled,
                                        };
                                        updateSections([
                                            ...sections.slice(
                                                0,
                                                sections.indexOf(section) + 1,
                                            ),
                                            duplicate,
                                            ...sections.slice(
                                                sections.indexOf(section) + 1,
                                            ),
                                        ]);
                                        setSelected(duplicate.id);
                                    }}
                                    canDuplicate={sections.length < 24}
                                    onRemove={() => {
                                        updateSections(
                                            sections.filter(
                                                (item) =>
                                                    item.id !== section.id,
                                            ),
                                        );
                                        setSelected("");
                                    }}
                                />
                            ) : (
                                <section className="panel">
                                    <Empty
                                        title="Build your homepage"
                                        description="Choose a trade preset or add your first section. A guided homepage needs one visible hero."
                                    />
                                </section>
                            )}
                        </div>
                    </>
                )}
            </fieldset>
            {page.id && (
                <RevisionPanel
                    endpoint={`pages/${page.id}/revisions`}
                    records={revisions}
                    setRecords={setRevisions}
                    disabled={busy || dirty}
                    onError={setError}
                    onRestore={async (revision) => {
                        setBusy(true);
                        try {
                            const result = await api(
                                `pages/${page.id}/revisions/${revision.id}/restore`,
                                "POST",
                                { expected_version: page.version },
                            );
                            setPage(result.data);
                            setSelected("");
                            setDirty(false);
                            setNotice(
                                "Revision restored to draft. Publish when ready.",
                            );
                        } catch (error) {
                            setError(error as Error);
                        } finally {
                            setBusy(false);
                        }
                    }}
                />
            )}
        </>
    );
}

function SectionEditor({
    section,
    onChange,
    onDuplicate,
    onRemove,
    canDuplicate,
    pages,
    anchors,
}: {
    section: Section;
    onChange: (section: Section) => void;
    onDuplicate: () => void;
    onRemove: () => void;
    canDuplicate: boolean;
    pages: RecordData[];
    anchors: string[];
}) {
    const patch = (values: Data) => onChange({ ...section, ...values });
    const items = section.items ?? [];
    const noItems = ["hero", "contact", "lead_tool"].includes(section.type);
    function blankItem(): Data {
        if (section.type === "faq") return { question: "", answer: "" };
        if (section.type === "reviews")
            return { text: "", author: "", permission_confirmed: false };
        if (["services", "articles"].includes(section.type))
            return { page_id: "", title: "", text: "" };
        return { title: "", text: "" };
    }
    return (
        <section className="panel website-section-editor">
            <header className="website-editor-title">
                <div>
                    <span className="website-eyebrow">
                        {sectionNames[section.type]}
                    </span>
                    <h2>{section.heading || "Edit section"}</h2>
                </div>
                <div>
                    <Button
                        variant="ghost"
                        type="button"
                        disabled={!canDuplicate}
                        onClick={onDuplicate}
                    >
                        <Copy size={14} /> Duplicate
                    </Button>
                    <Button variant="ghost" type="button" onClick={onRemove}>
                        <Trash2 size={14} /> Remove
                    </Button>
                </div>
            </header>
            {sectionHints[section.type] && (
                <p className="website-help">{sectionHints[section.type]}</p>
            )}
            <CheckField
                caption="Show this section"
                checked={section.enabled}
                onChange={(enabled) => patch({ enabled })}
            />
            <div className="website-grid">
                <TextInput
                    caption="Eyebrow"
                    value={section.eyebrow}
                    maxLength={80}
                    onChange={(eyebrow) => patch({ eyebrow })}
                />
                <Choice
                    caption="Layout variant"
                    value={section.variant}
                    options={["standard", "split", "cards"]}
                    onChange={(variant) => patch({ variant })}
                />
            </div>
            <TextInput
                caption={
                    section.type === "hero"
                        ? "Main headline"
                        : "Section heading"
                }
                value={section.heading}
                maxLength={180}
                onChange={(heading) => patch({ heading })}
            />
            <div className="website-writing">
                <label>Section writing</label>
                <Suspense fallback={<Loading />}>
                    <Writer
                        key={section.id}
                        initialContent={section.body ?? []}
                        onChange={(body) => patch({ body })}
                    />
                </Suspense>
            </div>
            <details className="website-disclosure">
                <summary>Section image</summary>
                <ImageEditor
                    image={section.image}
                    onChange={(image) => patch({ image })}
                />
            </details>
            <Group title="Buttons" hint="Up to two clear actions per section.">
                {(section.buttons ?? []).map((button, index) => (
                    <div className="website-repeater" key={index}>
                        <LinkEditor
                            link={button}
                            pages={pages}
                            anchors={anchors}
                            button
                            onChange={(next) =>
                                patch({
                                    buttons: section.buttons.map((item, i) =>
                                        i === index ? next : item,
                                    ),
                                })
                            }
                        />
                        <Button
                            variant="ghost"
                            type="button"
                            onClick={() =>
                                patch({
                                    buttons: section.buttons.filter(
                                        (_, i) => i !== index,
                                    ),
                                })
                            }
                        >
                            Remove button {index + 1}
                        </Button>
                    </div>
                ))}
                <Button
                    variant="secondary"
                    type="button"
                    disabled={(section.buttons?.length ?? 0) >= 2}
                    onClick={() =>
                        patch({
                            buttons: [
                                ...(section.buttons ?? []),
                                {
                                    label: "",
                                    kind: "page",
                                    page_id: "",
                                    style: "primary",
                                },
                            ],
                        })
                    }
                >
                    <Plus size={14} /> Add button
                </Button>
            </Group>
            {section.type === "lead_tool" && (
                <Choice
                    caption="Lead tool"
                    value={section.tool_key ?? "service-preparation"}
                    options={[
                        "service-preparation",
                        "project-scope",
                        "quote-comparison",
                    ]}
                    onChange={(tool_key) => patch({ tool_key })}
                />
            )}
            {!noItems && (
                <Group
                    title={
                        section.type === "faq"
                            ? "Questions & answers"
                            : "Section items"
                    }
                >
                    {items.map((item, index) => (
                        <div className="website-repeater" key={index}>
                            <header>
                                <strong>
                                    {section.type === "faq"
                                        ? "Question"
                                        : "Item"}{" "}
                                    {index + 1}
                                </strong>
                                <div>
                                    <button
                                        className="icon-button"
                                        type="button"
                                        aria-label={`Move item ${index + 1} up`}
                                        disabled={index === 0}
                                        onClick={() => {
                                            const next = [...items];
                                            [next[index - 1], next[index]] = [
                                                next[index],
                                                next[index - 1],
                                            ];
                                            patch({ items: next });
                                        }}
                                    >
                                        <ArrowUp size={14} />
                                    </button>
                                    <button
                                        className="icon-button"
                                        type="button"
                                        aria-label={`Remove item ${index + 1}`}
                                        onClick={() =>
                                            patch({
                                                items: items.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            })
                                        }
                                    >
                                        <Trash2 size={14} />
                                    </button>
                                </div>
                            </header>
                            <SectionItem
                                type={section.type}
                                item={item}
                                pages={pages}
                                onChange={(next) =>
                                    patch({
                                        items: items.map((existing, i) =>
                                            i === index ? next : existing,
                                        ),
                                    })
                                }
                            />
                        </div>
                    ))}
                    <Button
                        variant="secondary"
                        type="button"
                        disabled={
                            items.length >= (section.type === "faq" ? 8 : 12)
                        }
                        onClick={() =>
                            patch({ items: [...items, blankItem()] })
                        }
                    >
                        <Plus size={14} />{" "}
                        {section.type === "faq" ? "Add question" : "Add item"}
                    </Button>
                </Group>
            )}
        </section>
    );
}
function SectionItem({
    type,
    item,
    pages,
    onChange,
}: {
    type: string;
    item: Data;
    pages: RecordData[];
    onChange: (item: Data) => void;
}) {
    const patch = (key: string, value: any) =>
        onChange({ ...item, [key]: value });
    if (type === "faq")
        return (
            <>
                <TextInput
                    caption="Question"
                    value={item.question}
                    onChange={(value) => patch("question", value)}
                    maxLength={300}
                />
                <TextField
                    label="Answer"
                    value={item.answer ?? ""}
                    onChange={(e) => patch("answer", e.target.value)}
                    maxLength={3000}
                />
            </>
        );
    if (type === "reviews")
        return (
            <>
                <TextField
                    label="Review text"
                    value={item.text ?? ""}
                    onChange={(e) => patch("text", e.target.value)}
                />
                <div className="website-grid">
                    <TextInput
                        caption="Reviewer attribution"
                        value={item.author}
                        onChange={(value) => patch("author", value)}
                    />
                    <TextInput
                        caption="Review source"
                        value={item.source}
                        onChange={(value) => patch("source", value)}
                    />
                    <TextInput
                        caption="Source URL"
                        type="url"
                        value={item.url}
                        onChange={(value) => patch("url", value)}
                    />
                    <TextInput
                        caption="Review date"
                        type="date"
                        value={item.date}
                        onChange={(value) => patch("date", value)}
                    />
                </div>
                <CheckField
                    caption="I have permission to publish this testimonial"
                    checked={Boolean(item.permission_confirmed)}
                    onChange={(value) => patch("permission_confirmed", value)}
                />
            </>
        );
    return (
        <>
            <TextInput
                caption="Item heading"
                value={item.title}
                onChange={(value) => patch("title", value)}
                maxLength={180}
            />
            <TextField
                label="Item description"
                value={item.text ?? ""}
                onChange={(e) => patch("text", e.target.value)}
                maxLength={2000}
            />
            <PageReference
                pages={pages.filter(
                    (page) =>
                        (
                            ({
                                services: ["service"],
                                articles: ["article"],
                                projects: ["case-study"],
                                service_areas: ["service-area"],
                            }) as Record<string, string[]>
                        )[type]?.includes(page.type) ?? true,
                )}
                value={item.page_id}
                onChange={(value) => patch("page_id", value)}
                required={["services", "articles"].includes(type)}
            />
            {!["services", "articles", "service_areas"].includes(type) && (
                <TextInput
                    caption="External destination (optional)"
                    type="url"
                    value={item.url}
                    onChange={(value) => patch("url", value)}
                />
            )}
            {type === "projects" && (
                <TextInput
                    caption="Project location"
                    value={item.location}
                    onChange={(value) => patch("location", value)}
                />
            )}
            {type === "offers" && (
                <>
                    <div className="website-grid">
                        <TextInput
                            caption="Offer begins"
                            type="date"
                            value={item.starts_at}
                            onChange={(value) => patch("starts_at", value)}
                        />
                        <TextInput
                            caption="Offer expires"
                            type="date"
                            value={item.ends_at}
                            onChange={(value) => patch("ends_at", value)}
                        />
                    </div>
                    <TextField
                        label="Offer terms & eligibility"
                        value={item.terms ?? ""}
                        onChange={(e) => patch("terms", e.target.value)}
                    />
                </>
            )}
            {type !== "service_areas" && (
                <details className="website-disclosure">
                    <summary>Item image</summary>
                    <ImageEditor
                        image={item.image}
                        onChange={(image) => patch("image", image)}
                    />
                </details>
            )}
        </>
    );
}
function RevisionPanel({
    endpoint,
    records,
    setRecords,
    disabled,
    onError,
    onRestore,
}: {
    endpoint: string;
    records: RecordData[] | null;
    setRecords: (records: RecordData[]) => void;
    disabled: boolean;
    onError: (error: Error) => void;
    onRestore: (record: RecordData) => Promise<void>;
}) {
    const [loading, setLoading] = useState(false);
    return (
        <section className="panel website-revisions">
            <div>
                <h2>Saved revisions</h2>
                <Button
                    variant="secondary"
                    busy={loading}
                    disabled={disabled}
                    onClick={async () => {
                        setLoading(true);
                        try {
                            const result = await api(endpoint);
                            setRecords(result.data);
                        } catch (error) {
                            onError(error as Error);
                        } finally {
                            setLoading(false);
                        }
                    }}
                >
                    Load revisions
                </Button>
            </div>
            {records && (
                <>
                    {records.length ? (
                        records.map((record) => (
                            <div className="list-row" key={record.id}>
                                <span>
                                    <strong>
                                        Version{" "}
                                        {record.page_version ??
                                            record.website_version ??
                                            record.version ??
                                            record.snapshot?.version ??
                                            record.id.slice(0, 8)}
                                    </strong>
                                    <small>{date(record.created_at)}</small>
                                </span>
                                <Button
                                    variant="secondary"
                                    disabled={disabled}
                                    onClick={() => onRestore(record)}
                                >
                                    Restore to draft
                                </Button>
                            </div>
                        ))
                    ) : (
                        <p>No saved revisions yet.</p>
                    )}
                </>
            )}
            <p className="website-help">
                Save your edits before restoring. Restoring creates a draft; the
                public website changes only when you publish.
            </p>
        </section>
    );
}

function SettingsEditor({
    page,
    locationId,
    onDirty,
}: {
    page: WebsitePage;
    locationId?: string;
    onDirty: (dirty: boolean) => void;
}) {
    const source = useData<Data>("/api/v1/website");
    const pages = useData<RecordData[]>("/api/v1/pages");
    const [record, setRecord] = useState<Data | null>(null);
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<Error | null>(null);
    const [notice, setNotice] = useState("");
    const [revisions, setRevisions] = useState<RecordData[] | null>(null);
    const [newLocationId] = useState(
        () => `location-${crypto.randomUUID().slice(0, 8)}`,
    );
    useEffect(() => {
        if (source.data && !record) {
            const next = clone(source.data);
            if (page === "location" && (!locationId || locationId === "new"))
                next.draft.locations = [
                    ...(next.draft.locations ?? []),
                    { ...defaultProfile(), id: newLocationId, page_id: null },
                ];
            setRecord(next);
        }
    }, [source.data, record, page, locationId, newLocationId]);
    useEffect(() => onDirty(dirty), [dirty, onDirty]);
    const update = (key: string, value: any) => {
        setRecord((current) => ({
            ...current,
            draft: { ...current!.draft, [key]: value },
        }));
        setDirty(true);
        setNotice("");
    };
    async function save(publish = false) {
        if (!record) return;
        setBusy(true);
        setError(null);
        setNotice("");
        try {
            const draft = clone(record.draft);
            for (const profile of [draft.profile, ...draft.locations]) {
                profile.service_areas = profile.service_areas
                    .map((area: string) => area.trim())
                    .filter(Boolean);
            }
            const saved = await api("website", "PUT", {
                expected_version: record.version,
                draft,
            });
            setRecord(saved.data);
            setDirty(false);
            if (publish) {
                const result = await api("website/publish", "POST", {
                    expected_version: saved.data.version,
                });
                setRecord(result.data);
            }
            setNotice(
                publish
                    ? "Website settings published."
                    : "Website draft saved. Public settings remain unchanged until publication.",
            );
        } catch (error) {
            setError(error as Error);
        } finally {
            setBusy(false);
        }
    }
    if (source.loading || (!record && !source.error)) return <Loading />;
    if (source.error)
        return (
            <>
                <ErrorNotice message={source.error} />
                <Button onClick={source.reload}>Try again</Button>
            </>
        );
    if (!record) return null;
    const draft = record.draft;
    const editingLocation = draft.locations?.find(
        (item: Data) =>
            item.id ===
            (locationId && locationId !== "new" ? locationId : newLocationId),
    );
    return (
        <>
            <Validation error={error} />
            {notice && <Saved>{notice}</Saved>}
            {error instanceof ApiError && error.status === 409 && (
                <div className="website-conflict">
                    <p>
                        Your changes have been preserved. Compare the latest
                        saved settings before replacing your draft.
                    </p>
                    <a
                        href={window.location.pathname}
                        target="_blank"
                        rel="noreferrer"
                        className="text-link"
                    >
                        Open saved settings for comparison
                    </a>
                    <Button
                        variant="secondary"
                        onClick={async () => {
                            setBusy(true);
                            try {
                                const latest = await api("website");
                                setRecord(clone(latest.data));
                                setDirty(false);
                                setError(null);
                            } catch (failure) {
                                setError(failure as Error);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        Discard my edits and reload
                    </Button>
                </div>
            )}
            <div className="website-action-bar">
                <span className="website-save-state" role="status">
                    {dirty
                        ? "Unsaved changes"
                        : record.has_unpublished_changes
                          ? "Saved draft · unpublished changes"
                          : "Published settings"}
                </span>
                <div>
                    <a
                        href="/api/v1/website/preview"
                        target="_blank"
                        rel="noreferrer"
                        className="button secondary"
                    >
                        <ExternalLink size={14} /> Preview saved draft
                    </a>
                    <Button
                        busy={busy}
                        variant="secondary"
                        onClick={() => save()}
                    >
                        <Save size={14} /> Save draft
                    </Button>
                    <Button busy={busy} onClick={() => save(true)}>
                        Publish website settings
                    </Button>
                </div>
            </div>
            <p className="website-help">
                Publishing applies all saved website settings: appearance,
                navigation, public profile, and locations. Homepage content has
                its own publish action.
            </p>
            <fieldset className="website-fieldset" disabled={busy}>
                {page === "appearance" && (
                    <Appearance
                        value={draft.appearance}
                        onChange={(value) => update("appearance", value)}
                    />
                )}
                {page === "navigation" && (
                    <Navigation
                        value={draft.navigation}
                        pages={pages.data ?? []}
                        onChange={(value) => update("navigation", value)}
                    />
                )}
                {page === "business-profile" && (
                    <section className="panel website-padding">
                        <ProfileEditor
                            value={draft.profile}
                            onChange={(value) => update("profile", value)}
                        />
                        <p className="website-help">
                            Financial identity, billing address and tax details
                            remain in{" "}
                            <a href="/app/settings/business">
                                Business settings
                            </a>
                            .
                        </p>
                    </section>
                )}
                {page === "locations" && (
                    <section className="panel website-padding">
                        <div className="website-editor-title">
                            <h2>Real business locations</h2>
                            <a
                                className="button primary"
                                href="/app/website/local-seo/locations/new"
                            >
                                <Plus size={14} /> Add location
                            </a>
                        </div>
                        <p className="website-help">
                            Add actual operating locations. Cities you serve
                            belong in service areas.
                        </p>
                        {draft.locations?.length ? (
                            draft.locations.map((location: Data) => (
                                <div className="list-row" key={location.id}>
                                    <a
                                        className="text-link"
                                        href={`/app/website/local-seo/locations/${location.id}`}
                                    >
                                        <strong>
                                            {location.name ||
                                                "Unnamed location"}
                                        </strong>
                                        <small>
                                            {location.city} ·{" "}
                                            {location.address_visibility ===
                                            "visible"
                                                ? "Public address"
                                                : "Address hidden"}
                                        </small>
                                    </a>
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            update(
                                                "locations",
                                                draft.locations.filter(
                                                    (item: Data) =>
                                                        item.id !== location.id,
                                                ),
                                            )
                                        }
                                    >
                                        Remove from draft
                                    </Button>
                                </div>
                            ))
                        ) : (
                            <Empty
                                title="No additional locations"
                                description="Your main business profile is managed separately. Add a branch only when it is a real operating location."
                            />
                        )}
                    </section>
                )}
                {page === "location" &&
                    (editingLocation ? (
                        <section className="panel website-padding">
                            <ProfileEditor
                                value={editingLocation}
                                onChange={(value) =>
                                    update(
                                        "locations",
                                        draft.locations.map((item: Data) =>
                                            item.id === editingLocation.id
                                                ? value
                                                : item,
                                        ),
                                    )
                                }
                            />
                            <PageReference
                                caption="Location page"
                                pages={pages.data ?? []}
                                value={editingLocation.page_id}
                                onChange={(page_id) =>
                                    update(
                                        "locations",
                                        draft.locations.map((item: Data) =>
                                            item.id === editingLocation.id
                                                ? {
                                                      ...item,
                                                      page_id: page_id || null,
                                                  }
                                                : item,
                                        ),
                                    )
                                }
                            />
                        </section>
                    ) : (
                        <ErrorNotice message="This location does not exist. Open Business locations to select or create one." />
                    ))}
            </fieldset>
            <RevisionPanel
                endpoint="website/revisions"
                records={revisions}
                setRecords={setRevisions}
                disabled={busy || dirty}
                onError={setError}
                onRestore={async (revision) => {
                    setBusy(true);
                    setError(null);
                    try {
                        const result = await api(
                            `website/revisions/${revision.id}/restore`,
                            "POST",
                            { expected_version: record.version },
                        );
                        setRecord(result.data);
                        setDirty(false);
                        setNotice(
                            "Website settings restored to draft. Review and publish when ready.",
                        );
                    } catch (error) {
                        setError(error as Error);
                    } finally {
                        setBusy(false);
                    }
                }}
            />
        </>
    );
}
function Appearance({
    value,
    onChange,
}: {
    value: Data;
    onChange: (value: Data) => void;
}) {
    const fonts = useData<Data[]>("/api/v1/website/fonts");
    const [search, setSearch] = useState("");
    const patch = (key: string, next: any) =>
        onChange({ ...value, [key]: next });
    const catalog = fonts.data ?? [];
    return (
        <section className="panel website-padding">
            <link rel="stylesheet" href="/assets/website-fonts.css" />
            <Group
                title="Brand colors"
                hint="These colors apply to the public website."
            >
                <div className="website-color-grid">
                    {Object.entries(value.colors ?? {}).map(([key, color]) => (
                        <label className="website-color-field" key={key}>
                            <span>{label(key)}</span>
                            <div>
                                <input
                                    type="color"
                                    aria-label={`${label(key)} color picker`}
                                    value={String(color)}
                                    onChange={(e) =>
                                        patch("colors", {
                                            ...value.colors,
                                            [key]: e.target.value,
                                        })
                                    }
                                />
                                <input
                                    aria-label={`${label(key)} color`}
                                    value={String(color)}
                                    pattern="#[0-9a-fA-F]{6}"
                                    maxLength={7}
                                    onChange={(e) =>
                                        patch("colors", {
                                            ...value.colors,
                                            [key]: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        </label>
                    ))}
                </div>
            </Group>
            <Group
                title="Typography"
                hint="Eight Google Fonts families, served locally. Only selected families are used on your website."
            >
                <ErrorNotice message={fonts.error} />
                <TextInput
                    caption="Search font library"
                    value={search}
                    onChange={setSearch}
                    placeholder="Find a font family…"
                />
                <div className="website-grid">
                    {["heading", "body"].map((kind) => (
                        <SelectField
                            key={kind}
                            label={`${label(kind)} font`}
                            value={value[`${kind}_font_id`]}
                            onChange={(e) =>
                                patch(`${kind}_font_id`, e.target.value)
                            }
                        >
                            {catalog.map((font) => (
                                <option key={font.id} value={font.id}>
                                    {font.name}
                                </option>
                            ))}
                        </SelectField>
                    ))}
                </div>
                <div className="website-font-library">
                    {catalog
                        .filter((font) =>
                            `${font.name} ${font.category}`
                                .toLowerCase()
                                .includes(search.toLowerCase()),
                        )
                        .map((font) => (
                            <article
                                className="website-font-preview"
                                key={font.id}
                                style={{
                                    fontFamily: `${font.family}, ${font.category === "serif" ? "serif" : "sans-serif"}`,
                                }}
                            >
                                <header>
                                    <strong>{font.name}</strong>
                                    <small>{font.category}</small>
                                </header>
                                <p>Good work. Clearly communicated.</p>
                                <small>
                                    {(font.weights ?? []).join(" · ")}
                                </small>
                                <div>
                                    <Button
                                        variant="ghost"
                                        type="button"
                                        onClick={() =>
                                            patch("heading_font_id", font.id)
                                        }
                                    >
                                        Use for headings{" "}
                                        {value.heading_font_id === font.id && (
                                            <Check size={13} />
                                        )}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        type="button"
                                        onClick={() =>
                                            patch("body_font_id", font.id)
                                        }
                                    >
                                        Use for body{" "}
                                        {value.body_font_id === font.id && (
                                            <Check size={13} />
                                        )}
                                    </Button>
                                </div>
                                {font.license_url && (
                                    <a
                                        href={font.license_url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Font license
                                    </a>
                                )}
                            </article>
                        ))}
                </div>
            </Group>
            <Group title="Layout & buttons">
                <div className="website-grid">
                    <Choice
                        caption="Type scale"
                        value={value.type_scale}
                        options={["compact", "standard", "large"]}
                        onChange={(next) => patch("type_scale", next)}
                    />
                    <Choice
                        caption="Button corners"
                        value={value.button_radius}
                        options={["square", "soft", "rounded"]}
                        onChange={(next) => patch("button_radius", next)}
                    />
                    <Choice
                        caption="Content width"
                        value={value.content_width}
                        options={["standard", "wide"]}
                        onChange={(next) => patch("content_width", next)}
                    />
                    <Choice
                        caption="Section spacing"
                        value={value.section_spacing}
                        options={["compact", "standard", "generous"]}
                        onChange={(next) => patch("section_spacing", next)}
                    />
                </div>
            </Group>
            <Group title="Logo & favicon">
                <div className="website-grid">
                    {["logo", "favicon"].map((kind) => (
                        <ImageEditor
                            key={kind}
                            caption={label(kind)}
                            image={
                                value[`${kind}_media_id`]
                                    ? {
                                          media_id: value[`${kind}_media_id`],
                                          alt: kind,
                                          decorative: false,
                                          focal_x: 50,
                                          focal_y: 50,
                                      }
                                    : null
                            }
                            onChange={(image) =>
                                patch(
                                    `${kind}_media_id`,
                                    image?.media_id ?? null,
                                )
                            }
                        />
                    ))}
                </div>
            </Group>
        </section>
    );
}
function Navigation({
    value,
    pages,
    onChange,
}: {
    value: Data;
    pages: RecordData[];
    onChange: (value: Data) => void;
}) {
    return (
        <section className="panel website-padding">
            {["primary", "footer"].map((key) => (
                <Group
                    key={key}
                    title={key === "primary" ? "Header links" : "Footer links"}
                    hint="Managed page links follow published slug changes."
                >
                    {(value[key] ?? []).map((link: Data, index: number) => (
                        <div className="website-repeater" key={index}>
                            <LinkEditor
                                link={link}
                                pages={pages}
                                onChange={(next) =>
                                    onChange({
                                        ...value,
                                        [key]: value[key].map(
                                            (item: Data, i: number) =>
                                                i === index ? next : item,
                                        ),
                                    })
                                }
                            />
                            <div className="website-row-actions">
                                <Button
                                    variant="ghost"
                                    type="button"
                                    disabled={index === 0}
                                    onClick={() => {
                                        const next = [...value[key]];
                                        [next[index - 1], next[index]] = [
                                            next[index],
                                            next[index - 1],
                                        ];
                                        onChange({ ...value, [key]: next });
                                    }}
                                >
                                    <ArrowUp size={14} /> Move up
                                </Button>
                                <Button
                                    variant="ghost"
                                    type="button"
                                    onClick={() =>
                                        onChange({
                                            ...value,
                                            [key]: value[key].filter(
                                                (_: Data, i: number) =>
                                                    i !== index,
                                            ),
                                        })
                                    }
                                >
                                    Remove link
                                </Button>
                            </div>
                        </div>
                    ))}
                    <Button
                        variant="secondary"
                        type="button"
                        disabled={
                            (value[key]?.length ?? 0) >=
                            (key === "primary" ? 8 : 20)
                        }
                        onClick={() =>
                            onChange({
                                ...value,
                                [key]: [
                                    ...(value[key] ?? []),
                                    { label: "", kind: "page", page_id: "" },
                                ],
                            })
                        }
                    >
                        <Plus size={14} /> Add{" "}
                        {key === "primary" ? "header" : "footer"} link
                    </Button>
                </Group>
            ))}
            <Group title="Header action">
                <CheckField
                    caption="Show a primary header button"
                    checked={Boolean(value.header_button)}
                    onChange={(enabled) =>
                        onChange({
                            ...value,
                            header_button: enabled
                                ? {
                                      label: "Call us",
                                      kind: "phone",
                                      style: "primary",
                                  }
                                : null,
                        })
                    }
                />
                {value.header_button && (
                    <LinkEditor
                        link={value.header_button}
                        pages={pages}
                        button
                        anchors={[
                            "request-service",
                            ...(
                                pages.find(
                                    (item) =>
                                        item.type === "home" &&
                                        item.status === "published",
                                )?.published?.home_sections?.sections ?? []
                            )
                                .filter((item: Section) => item.enabled)
                                .map((item: Section) => item.id),
                        ]}
                        onChange={(header_button) =>
                            onChange({ ...value, header_button })
                        }
                    />
                )}
                <CheckField
                    caption="Show client portal link"
                    checked={Boolean(value.show_client_portal)}
                    onChange={(show_client_portal) =>
                        onChange({ ...value, show_client_portal })
                    }
                />
            </Group>
        </section>
    );
}
function ProfileEditor({
    value,
    onChange,
}: {
    value: Data;
    onChange: (value: Data) => void;
}) {
    const patch = (key: string, next: any) =>
        onChange({ ...value, [key]: next });
    const days = [
        "Monday",
        "Tuesday",
        "Wednesday",
        "Thursday",
        "Friday",
        "Saturday",
        "Sunday",
    ];
    return (
        <>
            <Group
                title="Public business identity"
                hint="Use the real business name and contact details customers recognize."
            >
                <div className="website-grid">
                    <TextInput
                        caption="Public business name"
                        value={value.name}
                        onChange={(next) => patch("name", next)}
                        maxLength={180}
                    />
                    <Choice
                        caption="Business type"
                        value={value.trade}
                        options={[
                            "GeneralContractor",
                            "RoofingContractor",
                            "Plumber",
                            "Electrician",
                            "HVACBusiness",
                            "HomeAndConstructionBusiness",
                        ]}
                        onChange={(next) => patch("trade", next)}
                    />
                    <TextInput
                        caption="Public phone"
                        type="tel"
                        value={value.phone}
                        onChange={(next) => patch("phone", next)}
                    />
                    <TextInput
                        caption="Public email"
                        type="email"
                        value={value.email}
                        onChange={(next) => patch("email", next)}
                    />
                    <TextInput
                        caption="Timezone"
                        value={value.timezone}
                        onChange={(next) => patch("timezone", next)}
                        placeholder="America/Toronto"
                    />
                    <Choice
                        caption="Country"
                        value={value.country}
                        options={["US", "CA"]}
                        onChange={(next) => patch("country", next)}
                    />
                </div>
            </Group>
            <Group
                title="Address & service area"
                hint="A service-area business can keep its street address private."
            >
                <Choice
                    caption="Address visibility"
                    value={value.address_visibility}
                    options={["hidden", "visible"]}
                    onChange={(next) => patch("address_visibility", next)}
                />
                <p className="website-help">
                    {value.address_visibility === "hidden"
                        ? "Street address details will be omitted from the public website, theme data and structured data."
                        : "Publish only a genuine customer-facing operating address."}
                </p>
                <TextInput
                    caption="Street address"
                    value={value.address}
                    onChange={(next) => patch("address", next)}
                />
                <div className="website-grid">
                    <TextInput
                        caption="City"
                        value={value.city}
                        onChange={(next) => patch("city", next)}
                    />
                    <TextInput
                        caption="State / province"
                        value={value.region}
                        onChange={(next) => patch("region", next)}
                    />
                    <TextInput
                        caption="ZIP / postal code"
                        value={value.postal_code}
                        onChange={(next) => patch("postal_code", next)}
                    />
                </div>
                <TextField
                    label="Service areas (one per line)"
                    value={(value.service_areas ?? []).join("\n")}
                    onChange={(e) =>
                        patch("service_areas", e.target.value.split("\n"))
                    }
                />
            </Group>
            <Group
                title="Opening hours"
                hint="Use your actual customer-facing hours. Leave days absent when hours are not established."
            >
                {days.map((day) => {
                    const index = (value.hours ?? []).findIndex(
                        (row: Data) => row.day === day,
                    );
                    const row = value.hours?.[index];
                    const update = (next: Data) =>
                        patch(
                            "hours",
                            index < 0
                                ? [
                                      ...(value.hours ?? []),
                                      {
                                          day,
                                          opens: "09:00",
                                          closes: "17:00",
                                          closed: false,
                                          ...next,
                                      },
                                  ]
                                : value.hours.map(
                                      (existing: Data, i: number) =>
                                          i === index
                                              ? { ...existing, ...next }
                                              : existing,
                                  ),
                        );
                    return (
                        <div className="website-hours-row" key={day}>
                            <CheckField
                                caption={day}
                                checked={Boolean(row)}
                                onChange={(enabled) =>
                                    enabled
                                        ? update({})
                                        : patch(
                                              "hours",
                                              value.hours.filter(
                                                  (_: Data, i: number) =>
                                                      i !== index,
                                              ),
                                          )
                                }
                            />
                            {row ? (
                                <>
                                    <Field
                                        label={`${day} opens`}
                                        type="time"
                                        value={row.opens ?? ""}
                                        disabled={row.closed}
                                        onChange={(e) =>
                                            update({ opens: e.target.value })
                                        }
                                    />
                                    <Field
                                        label={`${day} closes`}
                                        type="time"
                                        value={row.closes ?? ""}
                                        disabled={row.closed}
                                        onChange={(e) =>
                                            update({ closes: e.target.value })
                                        }
                                    />
                                    <CheckField
                                        caption={`${day} closed`}
                                        checked={Boolean(row.closed)}
                                        onChange={(closed) =>
                                            update({ closed })
                                        }
                                    />
                                </>
                            ) : (
                                <small>Not specified</small>
                            )}
                        </div>
                    );
                })}
            </Group>
            <Group title="Holiday & special hours">
                {(value.special_hours ?? []).map((row: Data, index: number) => (
                    <div className="website-repeater" key={index}>
                        <div className="website-grid">
                            <TextInput
                                caption="Special date"
                                type="date"
                                value={row.date}
                                onChange={(date) =>
                                    patch(
                                        "special_hours",
                                        value.special_hours.map(
                                            (item: Data, i: number) =>
                                                i === index
                                                    ? { ...item, date }
                                                    : item,
                                        ),
                                    )
                                }
                            />
                            {["opens", "closes"].map((key) => (
                                <TextInput
                                    key={key}
                                    caption={`Special ${key}`}
                                    type="time"
                                    disabled={row.closed}
                                    value={row[key]}
                                    onChange={(next) =>
                                        patch(
                                            "special_hours",
                                            value.special_hours.map(
                                                (item: Data, i: number) =>
                                                    i === index
                                                        ? {
                                                              ...item,
                                                              [key]: next,
                                                          }
                                                        : item,
                                            ),
                                        )
                                    }
                                />
                            ))}
                        </div>
                        <CheckField
                            caption="Closed on this date"
                            checked={Boolean(row.closed)}
                            onChange={(closed) =>
                                patch(
                                    "special_hours",
                                    value.special_hours.map(
                                        (item: Data, i: number) =>
                                            i === index
                                                ? { ...item, closed }
                                                : item,
                                    ),
                                )
                            }
                        />
                        <Button
                            variant="ghost"
                            type="button"
                            onClick={() =>
                                patch(
                                    "special_hours",
                                    value.special_hours.filter(
                                        (_: Data, i: number) => i !== index,
                                    ),
                                )
                            }
                        >
                            Remove special hours
                        </Button>
                    </div>
                ))}
                <Button
                    variant="secondary"
                    type="button"
                    onClick={() =>
                        patch("special_hours", [
                            ...(value.special_hours ?? []),
                            {
                                date: "",
                                opens: "09:00",
                                closes: "17:00",
                                closed: false,
                            },
                        ])
                    }
                >
                    Add special hours
                </Button>
            </Group>
            <Group
                title="Business profiles & social links"
                hint="Saved URLs are references, not evidence that external listings match."
            >
                {(value.profile_urls ?? []).map((link: Data, index: number) => (
                    <div className="website-repeater" key={index}>
                        <div className="website-grid">
                            <TextInput
                                caption="Profile label"
                                value={link.label}
                                onChange={(label) =>
                                    patch(
                                        "profile_urls",
                                        value.profile_urls.map(
                                            (item: Data, i: number) =>
                                                i === index
                                                    ? { ...item, label }
                                                    : item,
                                        ),
                                    )
                                }
                            />
                            <TextInput
                                caption="Profile URL"
                                type="url"
                                value={link.url}
                                onChange={(url) =>
                                    patch(
                                        "profile_urls",
                                        value.profile_urls.map(
                                            (item: Data, i: number) =>
                                                i === index
                                                    ? { ...item, url }
                                                    : item,
                                        ),
                                    )
                                }
                            />
                        </div>
                        <Button
                            variant="ghost"
                            type="button"
                            onClick={() =>
                                patch(
                                    "profile_urls",
                                    value.profile_urls.filter(
                                        (_: Data, i: number) => i !== index,
                                    ),
                                )
                            }
                        >
                            Remove profile link
                        </Button>
                    </div>
                ))}
                <Button
                    variant="secondary"
                    type="button"
                    onClick={() =>
                        patch("profile_urls", [
                            ...(value.profile_urls ?? []),
                            { label: "", url: "" },
                        ])
                    }
                >
                    Add profile link
                </Button>
            </Group>
        </>
    );
}

function LocalSeo({ page }: { page: "local-seo" | "page-plan" }) {
    const source = useData<Data>("/api/v1/website/local-seo");
    if (source.loading) return <Loading />;
    if (source.error)
        return (
            <>
                <ErrorNotice message={source.error} />
                <Button onClick={source.reload}>Try again</Button>
            </>
        );
    const value = source.data ?? {};
    const coverage = value.coverage ?? [];
    return (
        <>
            <nav className="website-local-nav" aria-label="Local SEO settings">
                <a href={route("business-profile")}>Public business profile</a>
                <a href={route("locations")}>Locations</a>
                <a href={route("page-plan")}>Page coverage</a>
                <a href={route("citations")}>Listing observations</a>
            </nav>
            {page === "local-seo" && (
                <>
                    <div className="website-metrics">
                        {[
                            ["Published pages", value.counts?.published_pages],
                            ["Draft pages", value.counts?.draft_pages],
                            ["Listings to review", value.counts?.needs_review],
                            [
                                "Unverified observations",
                                value.counts?.unverified,
                            ],
                        ].map(([title, count]) => (
                            <div key={title}>
                                <span>{title}</span>
                                <strong>{count ?? 0}</strong>
                            </div>
                        ))}
                    </div>
                    <div className="website-grid">
                        <section className="panel website-padding">
                            <h2>Published contact identity</h2>
                            {value.profile?.name ? (
                                <>
                                    <p className="website-nap">
                                        <strong>{value.profile.name}</strong>
                                        <span>{value.profile.phone}</span>
                                        <span>{value.profile.email}</span>
                                        {value.profile.address_visibility ===
                                            "visible" && (
                                            <span>
                                                {[
                                                    value.profile.address,
                                                    value.profile.city,
                                                    value.profile.region,
                                                    value.profile.postal_code,
                                                ]
                                                    .filter(Boolean)
                                                    .join(", ")}
                                            </span>
                                        )}
                                    </p>
                                    <Badge>
                                        {value.profile.address_visibility ===
                                        "visible"
                                            ? "Public address"
                                            : "Address hidden"}
                                    </Badge>
                                </>
                            ) : (
                                <p>
                                    Save and publish your public business
                                    profile to establish a canonical identity.
                                </p>
                            )}
                            <p className="website-help">
                                This is the reference for your public website
                                and listing observations.
                            </p>
                            <a
                                className="text-link"
                                href={route("business-profile")}
                            >
                                Edit public profile
                            </a>
                        </section>
                        <section className="panel website-padding">
                            <h2>Consistency workflow</h2>
                            <ol className="website-checklist">
                                <li>
                                    Publish accurate business details and actual
                                    operating locations.
                                </li>
                                <li>
                                    Open each external listing and record what
                                    you observe.
                                </li>
                                <li>
                                    Compare differences, assign corrections, and
                                    recheck.
                                </li>
                            </ol>
                            <p className="website-help">
                                No external listing is automatically verified.
                                Old observations become stale when your public
                                identity changes.
                            </p>
                            <a className="text-link" href={route("citations")}>
                                Review listing observations
                            </a>
                        </section>
                    </div>
                    <section className="panel website-padding website-diagnostics">
                        <h2>Website diagnostics</h2>
                        {value.diagnostics?.length ? (
                            value.diagnostics.map(
                                (issue: Data, index: number) => (
                                    <div
                                        className="list-row"
                                        key={`${issue.code}-${index}`}
                                    >
                                        <span>
                                            <strong>{label(issue.code)}</strong>
                                            <small>{issue.message}</small>
                                            {typeof issue.path === "string" &&
                                                issue.path.startsWith(
                                                    "/app/",
                                                ) && (
                                                    <a
                                                        href={issue.path}
                                                        className="text-link"
                                                    >
                                                        Review settings
                                                    </a>
                                                )}
                                        </span>
                                        <Badge
                                            tone={
                                                issue.severity === "warning"
                                                    ? "amber"
                                                    : "blue"
                                            }
                                        >
                                            {issue.severity}
                                        </Badge>
                                    </div>
                                ),
                            )
                        ) : (
                            <p className="notice success">
                                <Check size={15} /> No on-site issues found in
                                these checks.
                            </p>
                        )}
                        <p className="website-help">
                            These checks cover the CMS data and content. Search
                            rankings and off-site consistency require separate
                            evidence.
                        </p>
                    </section>
                </>
            )}
            <section className="panel website-padding website-coverage">
                <div className="website-editor-title">
                    <h2>Content coverage</h2>
                    <a href="/app/pages" className="button secondary">
                        Open page library
                    </a>
                </div>
                <p className="website-help">
                    Create useful pages for your actual services, areas, and
                    work. Page counts are a planning aid, not a ranking target.
                </p>
                {coverage.map((item: Data) => (
                    <article key={item.type} className="website-coverage-row">
                        <header>
                            <div>
                                <h3>{item.label}</h3>
                                <p>{item.description}</p>
                            </div>
                            <div>
                                <Badge
                                    tone={
                                        item.published_count ? "green" : "amber"
                                    }
                                >{`${item.published_count ?? 0} published`}</Badge>
                                <span>{item.draft_count ?? 0} draft</span>
                            </div>
                        </header>
                        {item.pages?.length > 0 && (
                            <ul>
                                {item.pages.map((entry: Data) => (
                                    <li key={entry.id}>
                                        <a
                                            href={
                                                entry.edit_url || "/app/pages"
                                            }
                                        >
                                            {entry.title}
                                        </a>
                                        <span>{label(entry.status)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </article>
                ))}
            </section>
        </>
    );
}
function Citations({ onDirty }: { onDirty: (dirty: boolean) => void }) {
    const source = useData<RecordData[]>("/api/v1/website/citations");
    const website = useData<Data>("/api/v1/website");
    const team = useData<RecordData[]>("/api/v1/team");
    const [editing, setEditing] = useState<Data | null>(null);
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<Error | null>(null);
    const [notice, setNotice] = useState("");
    const [filter, setFilter] = useState("all");
    useEffect(() => onDirty(dirty), [dirty, onDirty]);
    const update = (key: string, value: any) => {
        setEditing((current) => ({ ...current, [key]: value }));
        setDirty(true);
        setNotice("");
    };
    return (
        <>
            <Validation error={error} />
            {notice && <Saved>{notice}</Saved>}
            <div className="website-action-bar">
                <a href="/app/website/local-seo" className="text-link">
                    <ArrowLeft size={14} /> Local SEO overview
                </a>
                <div>
                    <a
                        href="/api/v1/website/nap-export"
                        className="button secondary"
                    >
                        Export public NAP
                    </a>
                    <a
                        href="/api/v1/website/citations/export"
                        className="button secondary"
                    >
                        Export observations
                    </a>
                    <Button
                        disabled={dirty || busy}
                        onClick={() => {
                            setEditing({
                                location_id: "primary",
                                provider: "",
                                listing_url: "",
                                observed_name: "",
                                observed_address: "",
                                observed_phone: "",
                                observed_website: "",
                                checked_at: "",
                                notes: "",
                                correction_status: "none",
                                correction_owner: "",
                            });
                            setError(null);
                            setNotice("");
                        }}
                    >
                        Add observation
                    </Button>
                </div>
            </div>
            {editing && (
                <section className="panel website-padding website-citation-editor">
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError(null);
                            setNotice("");
                            try {
                                const payload = {
                                    location_id: editing.location_id,
                                    provider: editing.provider,
                                    listing_url: editing.listing_url,
                                    observed_name: editing.observed_name ?? "",
                                    observed_address:
                                        editing.observed_address ?? "",
                                    observed_phone:
                                        editing.observed_phone ?? "",
                                    observed_website:
                                        editing.observed_website ?? "",
                                    checked_at: editing.checked_at || null,
                                    notes: editing.notes ?? "",
                                    correction_status:
                                        editing.correction_status,
                                    correction_owner:
                                        editing.correction_owner ?? "",
                                    ...(editing.id
                                        ? { expected_version: editing.version }
                                        : {}),
                                };
                                const result = await api(
                                    `website/citations${editing.id ? `/${editing.id}` : ""}`,
                                    editing.id ? "PUT" : "POST",
                                    payload,
                                );
                                setEditing(result.data);
                                setDirty(false);
                                setNotice(
                                    "Observation saved with its manual provenance.",
                                );
                                source.reload();
                            } catch (error) {
                                setError(error as Error);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <fieldset className="website-fieldset" disabled={busy}>
                            <Group
                                title={
                                    editing.id
                                        ? "Edit listing observation"
                                        : "New listing observation"
                                }
                                hint="Record what you actually observed. A listing URL alone does not establish consistency."
                            >
                                <div className="website-grid">
                                    <SelectField
                                        label="Business location"
                                        value={editing.location_id}
                                        onChange={(e) =>
                                            update(
                                                "location_id",
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="primary">
                                            Primary business
                                        </option>
                                        {(
                                            website.data?.published
                                                ?.locations ?? []
                                        ).map((location: Data) => (
                                            <option
                                                key={location.id}
                                                value={location.id}
                                            >
                                                {location.name}
                                            </option>
                                        ))}
                                    </SelectField>
                                    <TextInput
                                        caption="Directory / provider"
                                        required
                                        value={editing.provider}
                                        onChange={(value) =>
                                            update("provider", value)
                                        }
                                        placeholder="Google Business Profile, Yelp…"
                                    />
                                    <TextInput
                                        caption="Listing URL"
                                        type="url"
                                        required
                                        value={editing.listing_url}
                                        onChange={(value) =>
                                            update("listing_url", value)
                                        }
                                    />
                                    <TextInput
                                        caption="Observed at"
                                        type="datetime-local"
                                        value={
                                            editing.checked_at
                                                ? localDateTime(
                                                      editing.checked_at,
                                                  )
                                                : ""
                                        }
                                        onChange={(value) =>
                                            update(
                                                "checked_at",
                                                value
                                                    ? new Date(
                                                          value,
                                                      ).toISOString()
                                                    : "",
                                            )
                                        }
                                    />
                                </div>
                                <div className="website-grid">
                                    <TextInput
                                        caption="Observed business name"
                                        value={editing.observed_name}
                                        onChange={(value) =>
                                            update("observed_name", value)
                                        }
                                    />
                                    <TextInput
                                        caption="Observed phone"
                                        value={editing.observed_phone}
                                        onChange={(value) =>
                                            update("observed_phone", value)
                                        }
                                    />
                                    <TextInput
                                        caption="Observed address"
                                        value={editing.observed_address}
                                        onChange={(value) =>
                                            update("observed_address", value)
                                        }
                                    />
                                    <TextInput
                                        caption="Observed website"
                                        type="url"
                                        value={editing.observed_website}
                                        onChange={(value) =>
                                            update("observed_website", value)
                                        }
                                    />
                                    <Choice
                                        caption="Correction status"
                                        value={editing.correction_status}
                                        options={[
                                            "none",
                                            "assigned",
                                            "submitted",
                                            "awaiting_review",
                                            "corrected",
                                            "rechecked",
                                        ]}
                                        onChange={(value) =>
                                            update("correction_status", value)
                                        }
                                    />
                                    <SelectField
                                        label="Correction owner"
                                        value={editing.correction_owner ?? ""}
                                        onChange={(e) =>
                                            update(
                                                "correction_owner",
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="">Unassigned</option>
                                        {(Array.isArray(team.data)
                                            ? team.data
                                            : []
                                        )
                                            .filter(
                                                (member) =>
                                                    member.active !== false &&
                                                    member.role !== "client",
                                            )
                                            .map((member) => (
                                                <option
                                                    key={member.id}
                                                    value={member.id}
                                                >
                                                    {member.name}
                                                </option>
                                            ))}
                                    </SelectField>
                                </div>
                                <p className="website-help">
                                    Observation times use your device's local
                                    timezone. Changing a correction status does
                                    not update the observed-at time or reverify
                                    the listing.
                                </p>
                                <ErrorNotice message={team.error} />
                                <TextField
                                    label="Evidence & correction notes"
                                    value={editing.notes ?? ""}
                                    onChange={(e) =>
                                        update("notes", e.target.value)
                                    }
                                />
                                {editing.comparison && (
                                    <div className="website-comparison">
                                        <Badge>
                                            {editing.comparison.status}
                                        </Badge>
                                        <p>
                                            {editing.comparison.differing_fields
                                                ?.length
                                                ? `Differences: ${editing.comparison.differing_fields.map(label).join(", ")}`
                                                : "Review the observed fields and check date before drawing conclusions."}
                                        </p>
                                    </div>
                                )}
                            </Group>
                            <div className="form-footer">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => {
                                        setEditing(null);
                                        setDirty(false);
                                        setError(null);
                                    }}
                                >
                                    {dirty
                                        ? "Discard edits and close"
                                        : "Close editor"}
                                </Button>
                                <Button busy={busy}>Save observation</Button>
                            </div>
                        </fieldset>
                    </form>
                </section>
            )}
            <section className="panel website-padding">
                <div className="website-editor-title">
                    <h2>Listing observations</h2>
                    <Choice
                        caption="Filter observations"
                        value={filter}
                        options={[
                            "all",
                            "unchecked",
                            "matches",
                            "difference",
                            "needs_review",
                            "stale",
                        ]}
                        onChange={setFilter}
                    />
                </div>
                <ErrorNotice message={source.error} />
                {source.loading ? (
                    <Loading />
                ) : (source.data ?? []).filter(
                      (item) =>
                          filter === "all" ||
                          item.comparison?.status === filter,
                  ).length ? (
                    (source.data ?? [])
                        .filter(
                            (item) =>
                                filter === "all" ||
                                item.comparison?.status === filter,
                        )
                        .map((item) => (
                            <div className="website-citation-row" key={item.id}>
                                <div>
                                    <strong>{item.provider}</strong>
                                    <a
                                        href={item.listing_url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Open listing <ExternalLink size={12} />
                                    </a>
                                    <small>
                                        {item.checked_at
                                            ? `Observed ${date(item.checked_at)} · ${item.provenance ?? "manual"}`
                                            : "Not checked"}
                                    </small>
                                </div>
                                <Badge>
                                    {item.comparison?.status ?? "unchecked"}
                                </Badge>
                                <span>{label(item.correction_status)}</span>
                                <Button
                                    variant="secondary"
                                    disabled={dirty || busy}
                                    onClick={() => {
                                        setEditing(clone(item));
                                        setError(null);
                                        setNotice("");
                                    }}
                                >
                                    Edit observation
                                </Button>
                            </div>
                        ))
                ) : (
                    <Empty
                        title="No observations in this view"
                        description="Inspect a listing and add the observed details to start a manual comparison."
                    />
                )}
            </section>
        </>
    );
}
