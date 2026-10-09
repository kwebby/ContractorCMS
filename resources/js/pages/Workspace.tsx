/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useEffect, useRef, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import {
    Bell,
    BriefcaseBusiness,
    CalendarDays,
    ChartNoAxesCombined,
    Check,
    ChevronDown,
    ChevronRight,
    ChevronsUpDown,
    ClipboardList,
    ListTodo,
    CreditCard,
    ExternalLink,
    FileText,
    Globe,
    Grid2X2,
    HardHat,
    House,
    LayoutTemplate,
    LogOut,
    Menu,
    MessageSquare,
    PanelLeftClose,
    Plus,
    Search,
    Settings2,
    ShieldCheck,
    Sparkles,
    Users,
    UsersRound,
    WalletCards,
    Wrench,
    X,
} from "lucide-react";
import { api, initials, label, type RecordData, type User } from "../api";
import { Button, ErrorNotice, Field, useData } from "../components/ui";
import Dashboard, { Reports } from "../sections/Dashboard";
import Records, { Schedule } from "../sections/Records";
import Finance, { Payroll } from "../sections/Finance";
import Publishing, { Themes } from "../sections/Publishing";
import { Messages, Notifications, Team } from "../sections/Communications";
import Settings, { Automations } from "../sections/Settings";
import { Tasks, Calendar } from "../sections/Planning";
import Website, { type WebsitePage } from "../sections/Website";
const groups = [
    {
        label: "Workspace",
        items: [
            ["dashboard", "Overview", House],
            ["leads", "Leads", Users],
            ["customers", "Customers", UsersRound],
            ["jobs", "Jobs", Wrench],
            ["tasks", "Tasks", ListTodo],
            ["calendar", "Calendar", CalendarDays],
        ],
    },
    {
        label: "Business",
        items: [
            ["estimates", "Estimates", ClipboardList],
            ["invoices", "Invoices", FileText],
            ["messages", "Messages", MessageSquare],
            ["team", "Team", BriefcaseBusiness],
            ["payroll", "Payroll", WalletCards],
            ["reports", "Reports", ChartNoAxesCombined],
        ],
    },
    {
        label: "Grow",
        items: [
            ["pages", "Website & content", Globe],
            ["themes", "Themes", LayoutTemplate],
            ["automations", "AI & automations", Sparkles],
        ],
    },
] as const;
const roleSections: Record<string, string[]> = {
    client: [
        "dashboard",
        "jobs",
        "schedule",
        "calendar",
        "estimates",
        "invoices",
        "messages",
        "notifications",
    ],
    technician: [
        "dashboard",
        "tasks",
        "jobs",
        "schedule",
        "calendar",
        "messages",
        "payroll",
        "notifications",
    ],
    dispatcher: [
        "dashboard",
        "tasks",
        "customers",
        "jobs",
        "schedule",
        "calendar",
        "messages",
        "notifications",
    ],
    office: [
        "dashboard",
        "tasks",
        "leads",
        "customers",
        "jobs",
        "schedule",
        "calendar",
        "estimates",
        "invoices",
        "messages",
        "notifications",
    ],
    estimator: [
        "dashboard",
        "tasks",
        "calendar",
        "leads",
        "customers",
        "estimates",
        "jobs",
        "messages",
        "notifications",
    ],
    finance: [
        "dashboard",
        "tasks",
        "calendar",
        "customers",
        "invoices",
        "estimates",
        "reports",
        "messages",
        "notifications",
    ],
    payroll: ["dashboard", "payroll", "messages", "notifications"],
    marketing: [
        "dashboard",
        "tasks",
        "calendar",
        "leads",
        "pages",
        "themes",
        "messages",
        "notifications",
    ],
};
export default function Workspace({
    user,
    business = {},
    section = "dashboard",
    settingsPage,
    themePage,
    themeId,
    publishingPage,
    websitePage,
    locationId,
    currentPath,
}: {
    user: User;
    business: any;
    section: string;
    currentPath?: string;
    settingsPage?: string;
    themePage?: "upload" | "settings";
    themeId?: string;
    publishingPage?: "seo";
    websitePage?: WebsitePage;
    locationId?: string;
}) {
    const { flash } = usePage<any>().props;
    const current =
        section === "overview" || section === "" ? "dashboard" : section;
    const [mobile, setMobile] = useState(false);
    const [toast, setToast] = useState("");
    const [search, setSearch] = useState("");
    const [searchOpen, setSearchOpen] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);
    const reauthRef = useRef<HTMLElement>(null);
    const pendingReauth = useRef<((ok: boolean) => void) | null>(null);
    const canSearchCustomers = [
        "owner",
        "office",
        "dispatcher",
        "estimator",
        "finance",
    ].includes(user.role);
    const customers = useData<RecordData[]>(
        canSearchCustomers ? "/api/v1/customers" : null,
    );
    const [reauth, setReauth] = useState<((ok: boolean) => void) | null>(null);
    const [reauthError, setReauthError] = useState("");
    const [reauthBusy, setReauthBusy] = useState(false);
    const allowed =
        roleSections[user.role] ??
        (user.role === "owner"
            ? null
            : ["dashboard", "messages", "notifications"]);
    const can = (id: string) => !allowed || allowed.includes(id);
    const displayTitle =
        current === "dashboard"
            ? "Overview"
            : current === "pages"
              ? "Website & content"
              : label(current);
    useEffect(() => {
        if (!toast) return;
        const timeout = setTimeout(() => setToast(""), 4500);
        return () => clearTimeout(timeout);
    }, [toast]);
    useEffect(() => {
        setMobile(false);
    }, [section, currentPath]);
    useEffect(() => {
        if (reauth) {
            reauthRef.current?.scrollIntoView({
                behavior: "smooth",
                block: "center",
            });
        }
    }, [reauth]);
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key === "k") {
                e.preventDefault();
                searchRef.current?.focus();
                setSearchOpen(true);
            }
            if (e.key === "Escape") setSearchOpen(false);
        };
        window.addEventListener("keydown", handler);
        return () => window.removeEventListener("keydown", handler);
    }, []);
    function finishReauth(request: (ok: boolean) => void, confirmed: boolean) {
        if (pendingReauth.current !== request) return;
        pendingReauth.current = null;
        request(confirmed);
        setReauth(null);
        setReauthBusy(false);
    }
    useEffect(() => {
        const cancel = () => {
            const request = pendingReauth.current;
            if (request) finishReauth(request, false);
        };
        const handler = (event: Event) => {
            cancel();
            const request = (event as CustomEvent).detail.resolve;
            pendingReauth.current = request;
            setReauth(() => request);
            setReauthError("");
        };
        window.addEventListener("contractorcms:reauth", handler);
        window.addEventListener("popstate", cancel);
        const removeBefore = router.on("before", cancel);
        return () => {
            const request = pendingReauth.current;
            pendingReauth.current = null;
            request?.(false);
            window.removeEventListener("contractorcms:reauth", handler);
            window.removeEventListener("popstate", cancel);
            removeBefore();
        };
    }, []);
    function render() {
        if (!can(current))
            return (
                <div className="access-message">
                    <ShieldCheck size={32} />
                    <h1>This workspace is restricted</h1>
                    <p>Ask your administrator if your role needs access.</p>
                </div>
            );
        switch (current) {
            case "dashboard":
                return (
                    <Dashboard
                        user={user}
                        business={business}
                        onToast={setToast}
                    />
                );
            case "leads":
            case "customers":
            case "jobs":
                return (
                    <Records kind={current} onToast={setToast} user={user} />
                );
            case "schedule":
                return <Schedule onToast={setToast} user={user} />;
            case "tasks":
                return <Tasks onToast={setToast} user={user} />;
            case "calendar":
                return <Calendar onToast={setToast} user={user} />;
            case "estimates":
            case "invoices":
                return (
                    <Finance
                        kind={current}
                        onToast={setToast}
                        user={user}
                        currency={business.currency}
                    />
                );
            case "messages":
                return <Messages user={user} onToast={setToast} />;
            case "notifications":
                return <Notifications onToast={setToast} />;
            case "team":
                return <Team onToast={setToast} />;
            case "payroll":
                return <Payroll user={user} onToast={setToast} />;
            case "pages":
                if (websitePage)
                    return (
                        <Website
                            key={`${websitePage}-${locationId ?? ""}`}
                            page={websitePage}
                            locationId={locationId}
                        />
                    );
                return (
                    <Publishing
                        onToast={setToast}
                        publishingPage={publishingPage}
                    />
                );
            case "themes":
                return (
                    <Themes
                        onToast={setToast}
                        themePage={themePage}
                        themeId={themeId}
                    />
                );
            case "settings":
                return <Settings settingsPage={settingsPage} />;
            case "automations":
                return <Automations />;
            case "reports":
                return <Reports business={business} />;
            default:
                return (
                    <Dashboard
                        user={user}
                        business={business}
                        onToast={setToast}
                    />
                );
        }
    }
    return (
        <div className="app-shell">
            <Head title={displayTitle} />
            {mobile && (
                <button
                    aria-label="Close navigation"
                    className="sidebar-overlay"
                    onClick={() => setMobile(false)}
                />
            )}
            <aside className={`sidebar ${mobile ? "open" : ""}`}>
                <Link className="brand" href="/app">
                    <span className="brand-mark">
                        <HardHat size={23} strokeWidth={2} />
                    </span>
                    <span>
                        ContractorCMS
                    </span>
                </Link>
                <button
                    className="business-switch"
                    onClick={() =>
                        router.visit(
                            user.role === "owner"
                                ? "/app/settings"
                                : "/security",
                        )
                    }
                >
                    <span className="business-avatar">
                        {initials(business.name ?? "Your Business")}
                    </span>
                    <span>
                        <strong>{business.name ?? "Your business"}</strong>
                        <small>
                            {business.city
                                ? `${business.city}${business.region ? `, ${business.region}` : ""}`
                                : "Business workspace"}
                        </small>
                    </span>
                    <ChevronsUpDown size={14} />
                </button>
                <nav className="main-nav" aria-label="Main navigation">
                    {groups.map((group) => {
                        const items = group.items.filter(([id]) => can(id));
                        return items.length > 0 ? (
                            <div className="nav-group" key={group.label}>
                                <p>{group.label}</p>
                                {items.map(([id, name, Icon]) => (
                                    <Link
                                        key={id}
                                        href={
                                            id === "dashboard"
                                                ? "/app"
                                                : `/app/${id}`
                                        }
                                        className={`nav-item ${current === id ? "active" : ""}`}
                                        aria-current={
                                            current === id ? "page" : undefined
                                        }
                                    >
                                        <Icon size={18} strokeWidth={1.8} />
                                        <span>
                                            {id === "payroll" &&
                                            user.role === "technician"
                                                ? "My payslips"
                                                : name}
                                        </span>
                                        {current === id && (
                                            <span className="nav-active-dot" />
                                        )}
                                    </Link>
                                ))}
                            </div>
                        ) : null;
                    })}
                </nav>
                <div className="sidebar-bottom">
                    <a
                        className="nav-item"
                        href="/"
                        target="_blank"
                        rel="noreferrer"
                    >
                        <ExternalLink size={17} />
                        <span>View website</span>
                    </a>
                    {["owner", "admin"].includes(user.role) && (
                        <Link
                            className={`nav-item ${current === "settings" ? "active" : ""}`}
                            href="/app/settings"
                        >
                            <Settings2 size={18} />
                            <span>Settings</span>
                        </Link>
                    )}
                    <div className="sidebar-user">
                        <span className="avatar user-avatar">
                            {initials(user.name)}
                        </span>
                        <span>
                            <strong>{user.name}</strong>
                            <small>{label(user.role)}</small>
                        </span>
                        <button
                            className="icon-button"
                            aria-label="Sign out"
                            onClick={() => router.post("/logout")}
                        >
                            <LogOut size={17} />
                        </button>
                    </div>
                </div>
            </aside>
            <div className="main-column">
                <header className="topbar">
                    <button
                        className="icon-button mobile-menu"
                        aria-label="Open navigation"
                        onClick={() => setMobile(true)}
                    >
                        <Menu size={21} />
                    </button>
                    <div className="breadcrumb">
                        <span>
                            {user.role === "client"
                                ? "Client portal"
                                : "Workspace"}
                        </span>
                        <ChevronRight size={14} />
                        <strong>{displayTitle}</strong>
                    </div>
                    <div className="topbar-right">
                        {canSearchCustomers && (
                            <div className="global-search">
                                <Search size={16} />
                                <input
                                    ref={searchRef}
                                    value={search}
                                    onFocus={() => setSearchOpen(true)}
                                    onChange={(e) => {
                                        setSearch(e.target.value);
                                        setSearchOpen(true);
                                    }}
                                    placeholder="Search customers…"
                                    aria-label="Search customers"
                                />
                                <kbd>⌘ K</kbd>
                                {searchOpen && (
                                    <>
                                        <button
                                            className="search-dismiss"
                                            aria-label="Close search"
                                            onClick={() => setSearchOpen(false)}
                                        />
                                        <div className="search-results">
                                            <p>
                                                {search
                                                    ? "Customer results"
                                                    : "Find a customer"}
                                            </p>
                                            {search ? (
                                                (customers.data ?? [])
                                                    .filter((c) =>
                                                        `${c.name} ${c.email}`
                                                            .toLowerCase()
                                                            .includes(
                                                                search.toLowerCase(),
                                                            ),
                                                    )
                                                    .slice(0, 6)
                                                    .map((c) => (
                                                        <a
                                                            key={c.id}
                                                            href={`/app/customers?q=${encodeURIComponent(c.name)}`}
                                                        >
                                                            <span className="avatar small">
                                                                {initials(
                                                                    c.name,
                                                                )}
                                                            </span>
                                                            <span>
                                                                <strong>
                                                                    {c.name}
                                                                </strong>
                                                                <small>
                                                                    {c.email}
                                                                </small>
                                                            </span>
                                                            <ChevronRight
                                                                size={14}
                                                            />
                                                        </a>
                                                    ))
                                            ) : (
                                                <small>
                                                    Search by customer name or
                                                    email.
                                                </small>
                                            )}
                                            {search &&
                                                !(customers.data ?? []).some(
                                                    (c) =>
                                                        `${c.name} ${c.email}`
                                                            .toLowerCase()
                                                            .includes(
                                                                search.toLowerCase(),
                                                            ),
                                                ) && (
                                                    <small>
                                                        No customers match your
                                                        search.
                                                    </small>
                                                )}
                                        </div>
                                    </>
                                )}
                            </div>
                        )}
                        <Link
                            href="/app/notifications"
                            className="icon-button topbar-notifications"
                            aria-label="Notifications"
                        >
                            <Bell size={19} />
                        </Link>
                        <span className="topbar-separator" />
                        <span className="avatar small topbar-avatar">
                            {initials(user.name)}
                        </span>
                    </div>
                </header>
                <main className="workspace-content" id="main-content">
                    {flash?.success && (
                        <div className="notice success">{flash.success}</div>
                    )}
                    {reauth && (
                        <section
                            className="panel reauth-panel"
                            ref={reauthRef}
                            aria-labelledby="reauth-heading"
                        >
                            <div className="panel-heading">
                                <div>
                                    <h2 id="reauth-heading">
                                        Confirm your password
                                    </h2>
                                    <p>
                                        This change affects your account or
                                        financial settings. Your edits are kept
                                        below.
                                    </p>
                                </div>
                            </div>
                            <form
                                className="settings-form"
                                onSubmit={async (e) => {
                                    e.preventDefault();
                                    setReauthBusy(true);
                                    try {
                                        await api(
                                            "security/confirm-password",
                                            "POST",
                                            {
                                                password: new FormData(
                                                    e.currentTarget,
                                                ).get("password"),
                                            },
                                        );
                                        finishReauth(reauth, true);
                                    } catch (e) {
                                        if (pendingReauth.current === reauth)
                                            setReauthError(
                                                (e as Error).message,
                                            );
                                    } finally {
                                        if (pendingReauth.current === reauth)
                                            setReauthBusy(false);
                                    }
                                }}
                            >
                                <ErrorNotice message={reauthError} />
                                <Field
                                    label="Current password"
                                    name="password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                    autoFocus
                                />
                                <div className="form-footer">
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={reauthBusy}
                                        onClick={() => {
                                            finishReauth(reauth, false);
                                        }}
                                    >
                                        Cancel confirmation
                                    </Button>
                                    <Button busy={reauthBusy}>
                                        Confirm and continue
                                    </Button>
                                </div>
                            </form>
                        </section>
                    )}
                    <div inert={reauth ? true : undefined}>{render()}</div>
                </main>
                <footer className="workspace-footer">
                    <span>{business.name ?? "Your business"} workspace</span>
                    <span>Built for a good day's work.</span>
                </footer>
            </div>
            {toast && (
                <div className="toast" role="status">
                    <span>
                        <Check size={15} />
                    </span>
                    {toast}
                    <button
                        className="icon-button"
                        aria-label="Dismiss notification"
                        onClick={() => setToast("")}
                    >
                        <X size={15} />
                    </button>
                </div>
            )}
        </div>
    );
}
