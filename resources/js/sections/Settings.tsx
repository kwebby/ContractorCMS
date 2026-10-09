/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useEffect, useState } from "react";
import "../../css/settings-pages.css";
import {
    Activity,
    ArrowLeft,
    Building2,
    Check,
    ChevronRight,
    CreditCard,
    FileText,
    KeyRound,
    Mail,
    Save,
    ShieldCheck,
    Sparkles,
} from "lucide-react";
import { api, formValues, label, type RecordData } from "../api";
import {
    Badge,
    Button,
    ErrorNotice,
    Field,
    Loading,
    Saved,
    SelectField,
    TextField,
    useData,
} from "../components/ui";
const groups = [
    {
        id: "email-templates",
        name: "Email templates",
        description:
            "Write clear messages for important customer and team updates.",
        icon: Mail,
    },
    {
        id: "business",
        name: "Business profile",
        description: "Your company, service area, and regional settings.",
        icon: Building2,
    },
    {
        id: "mail",
        name: "Email delivery",
        description: "Connect your outgoing email server.",
        icon: Mail,
    },
    {
        id: "payments",
        name: "Payment gateways",
        description: "Connect your own Stripe and PayPal accounts.",
        icon: CreditCard,
    },
    {
        id: "invoice-design",
        name: "Invoice designer",
        description: "Make every invoice recognizably yours.",
        icon: FileText,
    },
    {
        id: "ai",
        name: "AI assistants",
        description: "Configure provider access and daily usage limits.",
        icon: Sparkles,
    },
    {
        id: "security",
        name: "Account security",
        description: "Protect your account with a second sign-in step.",
        icon: ShieldCheck,
    },
    {
        id: "health",
        name: "System health",
        description: "Check the services that keep your business running.",
        icon: Activity,
    },
];
export default function Settings({
    settingsPage,
}: {
    settingsPage?: string;
    onToast?: (t: string) => void;
}) {
    const item = groups.find((g) => g.id === settingsPage);
    if (!settingsPage) {
        return (
            <>
                <div className="page-heading">
                    <div>
                        <h1>Settings</h1>
                        <p>
                            Manage your business, communications, and workspace.
                        </p>
                    </div>
                </div>
                <nav
                    aria-label="Settings categories"
                    className="panel settings-directory"
                >
                    {groups.map((group) => (
                        <a key={group.id} href={`/app/settings/${group.id}`}>
                            <group.icon size={21} aria-hidden="true" />
                            <span>
                                <strong>{group.name}</strong>
                                <small>{group.description}</small>
                            </span>
                            <ChevronRight size={18} aria-hidden="true" />
                        </a>
                    ))}
                </nav>
            </>
        );
    }
    return (
        <>
            <a href="/app/settings" className="text-link settings-back">
                <ArrowLeft size={16} /> All settings
            </a>
            <div className="page-heading">
                <div>
                    <h1>{item?.name ?? "Settings page unavailable"}</h1>
                    <p>
                        {item?.description ??
                            "Choose a settings category to continue."}
                    </p>
                </div>
            </div>
            {item ? (
                <div className="settings-layout">
                    <nav
                        aria-label="Settings categories"
                        className="settings-nav"
                    >
                        {groups.map((group) => (
                            <a
                                key={group.id}
                                href={`/app/settings/${group.id}`}
                                className={
                                    settingsPage === group.id ? "active" : ""
                                }
                                aria-current={
                                    settingsPage === group.id
                                        ? "page"
                                        : undefined
                                }
                            >
                                <group.icon size={17} aria-hidden="true" />
                                <span>{group.name}</span>
                                <ChevronRight size={14} aria-hidden="true" />
                            </a>
                        ))}
                    </nav>
                    <section
                        className="panel settings-panel"
                        aria-label={item.name}
                    >
                        {settingsPage === "security" ? (
                            <Security />
                        ) : settingsPage === "email-templates" ? (
                            <EmailTemplates />
                        ) : settingsPage === "health" ? (
                            <Health />
                        ) : (
                            <SettingsForm
                                key={settingsPage}
                                group={settingsPage}
                            />
                        )}
                    </section>
                </div>
            ) : (
                <ErrorNotice message="This settings page does not exist." />
            )}
        </>
    );
}
function SettingsForm({ group }: { group: string }) {
    const state = useData<any>(
        group === "invoice-design"
            ? "/api/v1/invoice-design"
            : `/api/v1/settings/${group}`,
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [saved, setSaved] = useState(false);
    const [testEmail, setTestEmail] = useState("");
    const [testStatus, setTestStatus] = useState("");
    const [accent, setAccent] = useState("#2563eb");
    const d = state.data ?? {};
    useEffect(() => {
        if (d.accent_color) setAccent(d.accent_color);
    }, [d.accent_color]);
    async function save(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setBusy(true);
        setError("");
        setSaved(false);
        const form = e.currentTarget;
        const values: any = formValues(form);
        for (const name of [
            "stripe_enabled",
            "paypal_enabled",
            "enabled",
            "show_quantity",
            "show_tax",
        ]) {
            const input = form.elements.namedItem(
                name,
            ) as HTMLInputElement | null;
            if (input) values[name] = input.checked;
        }
        for (const name of [
            "port",
            "daily_request_limit",
            "daily_token_limit",
        ]) {
            if (values[name]) values[name] = Number(values[name]);
        }
        if ("service_areas" in values)
            values.service_areas = values.service_areas
                .split(",")
                .map((s: string) => s.trim())
                .filter(Boolean);
        try {
            await api(
                group === "invoice-design"
                    ? "invoice-design"
                    : `settings/${group}`,
                "PUT",
                values,
            );
            setSaved(true);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    if (state.loading) return <Loading />;
    if (state.error)
        return (
            <div className="settings-form">
                <ErrorNotice message={state.error} />
                <Button variant="secondary" onClick={state.reload}>
                    Try again
                </Button>
            </div>
        );
    return (
        <form
            onSubmit={save}
            onChange={() => setSaved(false)}
            className="settings-form"
        >
            <ErrorNotice message={error || state.error} />
            {saved && <Saved />}
            <fieldset
                disabled={busy}
                style={{ border: 0, margin: 0, padding: 0, minWidth: 0 }}
            >
                {group === "business" && (
                    <>
                        <div className="form-grid">
                            <Field
                                label="Business name"
                                name="name"
                                defaultValue={d.name}
                                required
                            />
                            <Field
                                label="Legal company name"
                                name="legal_name"
                                defaultValue={d.legal_name}
                            />
                            <Field
                                label="Business email"
                                type="email"
                                name="email"
                                defaultValue={d.email}
                            />
                            <Field
                                label="Tax / registration ID"
                                name="tax_id"
                                defaultValue={d.tax_id}
                            />
                            <Field
                                label="Logo URL"
                                name="logo_url"
                                defaultValue={d.logo_url}
                            />
                            <Field
                                label="Business phone"
                                name="phone"
                                defaultValue={d.phone}
                            />
                            <Field
                                label="Street address"
                                name="address"
                                defaultValue={d.address}
                            />
                            <Field
                                label="City"
                                name="city"
                                defaultValue={d.city}
                            />
                            <Field
                                label="State / province"
                                name="region"
                                defaultValue={d.region}
                            />
                            <Field
                                label="ZIP / postal code"
                                name="postal_code"
                                defaultValue={d.postal_code}
                            />
                            <SelectField
                                label="Country"
                                name="country"
                                defaultValue={d.country ?? "US"}
                            >
                                <option value="US">United States</option>
                                <option value="CA">Canada</option>
                            </SelectField>
                            <SelectField
                                label="Currency"
                                name="currency"
                                defaultValue={d.currency ?? "USD"}
                            >
                                <option value="USD">USD</option>
                                <option value="CAD">CAD</option>
                            </SelectField>
                            <Field
                                label="Time zone"
                                name="timezone"
                                defaultValue={d.timezone ?? "America/New_York"}
                                required
                            />
                            <SelectField
                                label="Primary trade"
                                name="trade"
                                defaultValue={d.trade ?? "GeneralContractor"}
                            >
                                {[
                                    "GeneralContractor",
                                    "Plumber",
                                    "Electrician",
                                    "HVACBusiness",
                                    "RoofingContractor",
                                ].map((t) => (
                                    <option key={t}>{t}</option>
                                ))}
                            </SelectField>
                        </div>
                        <Field
                            label="Service areas"
                            name="service_areas"
                            defaultValue={
                                Array.isArray(d.service_areas)
                                    ? d.service_areas.join(", ")
                                    : (d.service_areas ?? "")
                            }
                            hint="Separate real cities or neighborhoods with commas."
                        />
                    </>
                )}
                {group === "mail" && (
                    <>
                        <div className="form-grid">
                            <Field
                                label="SMTP host"
                                name="host"
                                placeholder="smtp.example.com"
                                defaultValue={d.host}
                                required
                            />
                            <Field
                                label="Port"
                                name="port"
                                type="number"
                                min="1"
                                max="65535"
                                defaultValue={d.port ?? 587}
                                required
                            />
                            <SelectField
                                label="Encryption"
                                name="encryption"
                                defaultValue={d.encryption ?? "tls"}
                            >
                                <option value="tls">STARTTLS (TLS)</option>
                                <option value="ssl">Implicit TLS (SSL)</option>
                            </SelectField>
                            <Field
                                label="Username"
                                name="username"
                                defaultValue={d.username}
                                autoComplete="off"
                            />
                            <Field
                                label="Password"
                                type="password"
                                name="password"
                                defaultValue={d.password ?? ""}
                                autoComplete="new-password"
                            />
                            <Field
                                label="From name"
                                name="from_name"
                                defaultValue={d.from_name}
                                required
                            />
                            <Field
                                label="From email"
                                type="email"
                                name="from_address"
                                defaultValue={d.from_address}
                                required
                            />
                            <Field
                                label="Reply-to email"
                                type="email"
                                name="reply_to"
                                defaultValue={d.reply_to}
                            />
                        </div>
                        <Field
                            label="Send a test to"
                            type="email"
                            value={testEmail}
                            onChange={(e) => setTestEmail(e.target.value)}
                            placeholder="you@example.com"
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            busy={busy}
                            disabled={!testEmail}
                            onClick={async () => {
                                setBusy(true);
                                setError("");
                                try {
                                    const r = await api("mail/test", "POST", {
                                        email: testEmail,
                                    });
                                    setTestStatus(
                                        r.message ?? "Test email queued.",
                                    );
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            Send test email
                        </Button>
                        {testStatus && (
                            <div className="notice success" role="status">
                                {testStatus}
                            </div>
                        )}
                        <p className="help-text">
                            Email is queued for delivery. The server's scheduled
                            worker must be running.
                        </p>
                    </>
                )}
                {group === "payments" && (
                    <>
                        <h3>Stripe</h3>
                        <label className="check-field">
                            <input
                                type="checkbox"
                                name="stripe_enabled"
                                defaultChecked={d.stripe_enabled}
                            />
                            Enable Stripe payments
                        </label>
                        <Field
                            label="Publishable key"
                            name="stripe_publishable_key"
                            defaultValue={d.stripe_publishable_key}
                        />
                        <Field
                            label="Secret key"
                            type="password"
                            name="stripe_secret_key"
                            defaultValue={d.stripe_secret_key ?? ""}
                            autoComplete="new-password"
                        />
                        <Field
                            label="Webhook signing secret"
                            type="password"
                            name="stripe_webhook_secret"
                            defaultValue={d.stripe_webhook_secret ?? ""}
                            autoComplete="new-password"
                        />
                        <div className="copy-value">
                            <small>Stripe webhook URL</small>
                            <code>
                                {window.location.origin}
                                /api/v1/payments/webhooks/stripe
                            </code>
                        </div>
                        <h3 className="form-section-title">PayPal</h3>
                        <label className="check-field">
                            <input
                                type="checkbox"
                                name="paypal_enabled"
                                defaultChecked={d.paypal_enabled}
                            />
                            Enable PayPal payments
                        </label>
                        <SelectField
                            label="Environment"
                            name="paypal_mode"
                            defaultValue={d.paypal_mode ?? "sandbox"}
                        >
                            <option value="sandbox">Sandbox</option>
                            <option value="live">Live</option>
                        </SelectField>
                        <Field
                            label="Client ID"
                            name="paypal_client_id"
                            defaultValue={d.paypal_client_id}
                        />
                        <Field
                            label="Client secret"
                            type="password"
                            name="paypal_client_secret"
                            defaultValue={d.paypal_client_secret ?? ""}
                            autoComplete="new-password"
                        />
                        <Field
                            label="Webhook ID"
                            name="paypal_webhook_id"
                            defaultValue={d.paypal_webhook_id}
                        />
                        <div className="copy-value">
                            <small>PayPal webhook URL</small>
                            <code>
                                {window.location.origin}
                                /api/v1/payments/webhooks/paypal
                            </code>
                        </div>
                    </>
                )}
                {group === "invoice-design" && (
                    <>
                        <div
                            className="invoice-sample"
                            style={{ borderTopColor: accent }}
                        >
                            <span style={{ color: accent }}>INVOICE</span>
                            <strong>Your business name</strong>
                            <div>
                                Customer details <span>INV-000001</span>
                            </div>
                            <hr />
                            <div>
                                Service and materials <span>$1,250.00</span>
                            </div>
                            <div>
                                <strong>Total due</strong>
                                <strong>$1,250.00</strong>
                            </div>
                            <small>Style preview · Sample information</small>
                        </div>
                        <div className="form-grid">
                            <Field
                                label="Accent color"
                                name="accent_color"
                                type="color"
                                value={accent}
                                onChange={(e) => setAccent(e.target.value)}
                            />
                            <SelectField
                                label="Typeface"
                                name="font"
                                defaultValue={d.font ?? "DejaVu Sans"}
                            >
                                <option>DejaVu Sans</option>
                                <option>DejaVu Serif</option>
                                <option>Helvetica</option>
                            </SelectField>
                            <SelectField
                                label="Paper size"
                                name="paper"
                                defaultValue={d.paper ?? "letter"}
                            >
                                <option value="letter">US Letter</option>
                                <option value="a4">A4</option>
                            </SelectField>
                        </div>
                        <div className="checkbox-row">
                            <label className="check-field">
                                <input
                                    type="checkbox"
                                    name="show_quantity"
                                    defaultChecked={d.show_quantity !== false}
                                />
                                Show quantities
                            </label>
                            <label className="check-field">
                                <input
                                    type="checkbox"
                                    name="show_tax"
                                    defaultChecked={d.show_tax !== false}
                                />
                                Show tax breakdown
                            </label>
                        </div>
                        <TextField
                            label="Payment instructions"
                            name="payment_instructions"
                            defaultValue={d.payment_instructions}
                        />
                        <TextField
                            label="Terms"
                            name="terms"
                            defaultValue={d.terms}
                        />
                        <TextField
                            label="Footer"
                            name="footer"
                            defaultValue={d.footer}
                        />
                    </>
                )}
                {group === "ai" && (
                    <>
                        <label className="check-field">
                            <input
                                type="checkbox"
                                name="enabled"
                                defaultChecked={d.enabled}
                            />
                            Enable AI assistants and lead tools
                        </label>
                        <Field
                            label="OpenAI API key"
                            name="api_key"
                            type="password"
                            defaultValue={d.api_key ?? ""}
                            autoComplete="new-password"
                        />
                        <Field
                            label="Model"
                            name="model"
                            defaultValue={d.model ?? ""}
                            placeholder="Enter an available model ID"
                            required
                        />
                        <div className="form-grid">
                            <Field
                                label="Daily request limit"
                                name="daily_request_limit"
                                type="number"
                                min="1"
                                defaultValue={d.daily_request_limit ?? 100}
                            />
                            <Field
                                label="Daily token limit"
                                name="daily_token_limit"
                                type="number"
                                min="1"
                                defaultValue={d.daily_token_limit ?? 100000}
                            />
                        </div>
                        <div className="notice info">
                            <Sparkles size={20} />
                            <span>
                                Public tools use only information supplied in
                                the request. Review generated drafts before
                                using them in a job or estimate.
                            </span>
                        </div>
                    </>
                )}
            </fieldset>
            <div className="form-footer">
                <Button busy={busy}>
                    <Save size={15} />
                    Save settings
                </Button>
            </div>
        </form>
    );
}
function Security() {
    const [setup, setSetup] = useState<any>(null);
    const [error, setError] = useState("");
    const [saved, setSaved] = useState(false);
    const [busy, setBusy] = useState(false);
    return (
        <div className="settings-form">
            <ErrorNotice message={error} />
            {saved ? (
                <Saved>Two-factor authentication is enabled.</Saved>
            ) : (
                <>
                    <h3>Authenticator app</h3>
                    <p className="muted">
                        Use an authenticator app to generate a code each time
                        you sign in.
                    </p>
                    {!setup ? (
                        <form
                            onSubmit={async (e) => {
                                e.preventDefault();
                                setBusy(true);
                                setError("");
                                try {
                                    const r = await api(
                                        "security/mfa/setup",
                                        "POST",
                                        formValues(e.currentTarget),
                                    );
                                    setSetup(r.data ?? r);
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            <Field
                                label="Confirm your password"
                                name="password"
                                type="password"
                                required
                                autoComplete="current-password"
                            />
                            <Button busy={busy}>
                                <KeyRound size={16} />
                                Set up two-factor authentication
                            </Button>
                        </form>
                    ) : (
                        <form
                            onSubmit={async (e) => {
                                e.preventDefault();
                                setBusy(true);
                                try {
                                    await api(
                                        "security/mfa/confirm",
                                        "POST",
                                        formValues(e.currentTarget),
                                    );
                                    setSaved(true);
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            <p>
                                Add this secret in your authenticator app, then
                                enter the generated code.
                            </p>
                            <div className="copy-value">
                                <code>{setup.secret}</code>
                            </div>
                            <Field
                                label="Six-digit code"
                                name="code"
                                inputMode="numeric"
                                pattern="[0-9]{6}"
                                maxLength={6}
                                required
                                autoComplete="one-time-code"
                            />
                            <Button busy={busy}>Verify and enable</Button>
                        </form>
                    )}
                </>
            )}
        </div>
    );
}
function Health() {
    const state = useData<any>("/api/v1/health");
    if (state.loading) return <Loading />;
    const entries = Object.entries(state.data ?? {});
    return (
        <div className="settings-form">
            <ErrorNotice message={state.error} />
            {entries.map(([key, value]) => (
                <div className="health-row" key={key}>
                    <span>{label(key)}</span>
                    <strong>
                        {typeof value === "boolean" ? (
                            <Badge tone={value ? "green" : "amber"}>
                                {value ? "Ready" : "Needs attention"}
                            </Badge>
                        ) : typeof value === "object" ? (
                            JSON.stringify(value)
                        ) : (
                            String(value ?? "Not available")
                        )}
                    </strong>
                </div>
            ))}
            <Button variant="secondary" onClick={state.reload}>
                Refresh checks
            </Button>
        </div>
    );
}
export function Automations() {
    const settings = useData<any>("/api/v1/settings/ai");
    const tools = [
        {
            name: "Service-visit preparation",
            slug: "service-visit",
            description:
                "Turn customer symptoms and access details into a useful visit brief.",
        },
        {
            name: "Project scope generator",
            slug: "project-scope",
            description:
                "Help customers organize goals, requirements, and open decisions.",
        },
        {
            name: "Quote comparison",
            slug: "quote-comparison",
            description:
                "Organize stated inclusions, exclusions, and unknowns across quotes.",
        },
    ];
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>AI & automations</h1>
                    <p>
                        Give your customers a useful first step. Give your team
                        a better brief.
                    </p>
                </div>
                <a href="/app/settings/ai" className="button secondary">
                    <Sparkles size={15} />
                    Configure AI
                </a>
            </div>
            <ErrorNotice message={settings.error} />
            <div className="notice info">
                <Sparkles size={20} />
                <span>
                    {settings.data?.enabled
                        ? "AI tools are enabled. Requests are subject to your configured daily limits."
                        : "Connect an AI provider and enable tools in settings to accept requests."}
                </span>
            </div>
            <section className="panel">
                <div className="panel-heading">
                    <h2>Public lead tools</h2>
                    <span className="count">3 tools</span>
                </div>
                {tools.map((tool) => (
                    <div className="tool-row" key={tool.slug}>
                        <span className="tool-icon">
                            <Sparkles size={21} />
                        </span>
                        <div>
                            <h3>{tool.name}</h3>
                            <p>{tool.description}</p>
                        </div>
                        <a
                            className="button secondary"
                            href={`/tools/${tool.slug}`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Open tool
                            <ChevronRight size={15} />
                        </a>
                    </div>
                ))}
            </section>
            <section className="panel lower-panel">
                <div className="panel-heading">
                    <h2>Delivery and follow-up</h2>
                    <Mail size={20} />
                </div>
                <div className="settings-form">
                    <p>
                        Invitations, customer messages, and business
                        notifications are delivered through the durable email
                        queue.
                    </p>
                    <p className="muted">
                        Check system health for delivery issues and worker
                        status. Lead ownership and follow-up dates are managed
                        in the CRM.
                    </p>
                    <a href="/app/settings/health" className="text-link">
                        View system health
                        <ChevronRight size={15} />
                    </a>
                </div>
            </section>
        </>
    );
}

function EmailTemplates() {
    const state = useData<RecordData[]>("/api/v1/email-templates");
    const [selected, setSelected] = useState<RecordData | null>(null);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState("");
    const [preview, setPreview] = useState<{
        subject: string;
        body: string;
    } | null>(null);
    if (state.loading) return <Loading />;
    return (
        <div className="settings-form">
            <ErrorNotice message={error || state.error} />
            {saved && <Saved>Email template saved.</Saved>}
            <SelectField
                label="Message template"
                value={selected?.key ?? selected?.id ?? ""}
                onChange={(e) => {
                    setSaved(false);
                    setPreview(null);
                    setError("");
                    setSelected(
                        state.data?.find(
                            (t) => (t.key ?? t.id) === e.target.value,
                        ) ?? null,
                    );
                }}
            >
                <option value="">Choose a template</option>
                {state.data?.map((t) => (
                    <option key={t.key ?? t.id} value={t.key ?? t.id}>
                        {label(t.key ?? t.id)}
                    </option>
                ))}
            </SelectField>
            {selected && (
                <form
                    key={selected.key ?? selected.id}
                    onChange={() => setSaved(false)}
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setBusy(true);
                        setError("");
                        try {
                            const r = await api(
                                `email-templates/${selected.key ?? selected.id}`,
                                "PUT",
                                {
                                    ...formValues(e.currentTarget),
                                    expected_version: selected.version ?? 0,
                                },
                            );
                            setSelected(r.data);
                            state.setData(
                                (state.data ?? []).map((template) =>
                                    (template.key ?? template.id) ===
                                    (selected.key ?? selected.id)
                                        ? r.data
                                        : template,
                                ),
                            );
                            setSaved(true);
                            setPreview(null);
                        } catch (e) {
                            setError((e as Error).message);
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    <Field
                        label="Subject"
                        name="subject"
                        required
                        defaultValue={selected.subject ?? ""}
                    />
                    <TextField
                        label="Message body"
                        name="body"
                        rows={12}
                        required
                        defaultValue={selected.body ?? ""}
                    />
                    <p className="help-text">
                        Keep the existing template placeholders so each message
                        includes the correct recipient, document, and action
                        links.
                    </p>
                    {selected.placeholders && (
                        <div className="copy-value">
                            <small>Available placeholders</small>
                            <code>
                                {Array.isArray(selected.placeholders)
                                    ? selected.placeholders.join(", ")
                                    : JSON.stringify(selected.placeholders)}
                            </code>
                        </div>
                    )}
                    <div className="form-footer">
                        <Button
                            type="button"
                            variant="secondary"
                            busy={busy}
                            onClick={async () => {
                                setBusy(true);
                                setError("");
                                try {
                                    const variables = Object.fromEntries(
                                        (selected.placeholders ?? []).map(
                                            (key: string) => [
                                                key,
                                                key === "action_url"
                                                    ? `${window.location.origin}/app`
                                                    : `Sample ${label(key)}`,
                                            ],
                                        ),
                                    );
                                    const result = await api(
                                        `email-templates/${selected.key ?? selected.id}/preview`,
                                        "POST",
                                        { variables },
                                    );
                                    setPreview(result.data);
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            Preview saved version
                        </Button>
                        <Button busy={busy}>
                            <Save size={15} />
                            Save template
                        </Button>
                    </div>
                    {preview && (
                        <div className="email-preview">
                            <h3>{preview.subject}</h3>
                            <pre>{preview.body}</pre>
                        </div>
                    )}
                </form>
            )}
        </div>
    );
}
