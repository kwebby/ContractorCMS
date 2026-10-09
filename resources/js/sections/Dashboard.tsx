/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import {
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    CalendarDays,
    CheckCircle2,
    ChevronRight,
    Clock3,
    ClipboardList,
    FileText,
    Plus,
    Users,
    Wrench,
} from "lucide-react";
import { useState } from "react";
import {
    date,
    dateKey,
    initials,
    label,
    money,
    type RecordData,
    type User,
} from "../api";
import {
    Badge,
    Button,
    Empty,
    ErrorNotice,
    Loading,
    useData,
} from "../components/ui";
import { RecordEditor, RecordDetails } from "./Records";
export default function Dashboard({
    user,
    business,
    onToast,
}: {
    user: User;
    business: any;
    onToast: (t: string) => void;
}) {
    const state = useData<any>("/api/v1/dashboard");
    const [newLead, setNewLead] = useState(false);
    const [job, setJob] = useState<RecordData | null>(null);
    const d = state.data ?? {};
    const metrics = d.metrics ?? {};
    const currency = metrics.currency ?? business.currency ?? "USD";
    const otherCurrencies = (field: string, fallback: string) => {
        const others = (metrics.other_currencies ?? [])
            .filter((total: any) => total[field])
            .map((total: any) => money(total[field], total.currency));
        return others.length ? `Plus ${others.join(", ")}` : fallback;
    };
    const today = dateKey();
    const jobs = (d.jobs ?? []).filter(
        (j: RecordData) => dateKey(j.scheduled_at ?? "") === today,
    );
    const tasks = [
        ...(d.tasks ?? [])
            .filter((task: RecordData) => task.status !== "done")
            .map((task: RecordData) => ({ ...task, url: "/app/tasks" })),
        ...(d.jobs ?? [])
            .filter((job: RecordData) => job.status === "blocked")
            .map((job: RecordData) => ({
                id: `blocked-${job.id}`,
                title: job.title,
                description: "Blocked job — review the next step.",
                url: "/app/jobs",
                priority: "high",
            })),
        ...((d.leads ?? []).some(
            (lead: RecordData) => (lead.stage ?? "new") === "new",
        )
            ? [
                  {
                      id: "new-inquiries",
                      title: `${(d.leads ?? []).filter((lead: RecordData) => (lead.stage ?? "new") === "new").length} inquiries awaiting a response`,
                      description:
                          "Contact your new leads and move them forward.",
                      url: "/app/leads",
                      priority: "normal",
                  },
              ]
            : []),
    ];
    const client = user.role === "client";
    return (
        <>
            <div className="page-heading dashboard-heading">
                <div>
                    <div className="date-label">
                        {new Date().toLocaleDateString("en-US", {
                            weekday: "long",
                            month: "long",
                            day: "numeric",
                            year: "numeric",
                        })}
                    </div>
                    <h1>
                        {client
                            ? "Welcome back"
                            : new Date().getHours() < 12
                              ? "Good morning"
                              : new Date().getHours() < 17
                                ? "Good afternoon"
                                : "Good evening"}
                        , {user.name.split(" ")[0]}
                        <span className="greeting-period">.</span>
                    </h1>
                    <p>
                        {client
                            ? "Your projects, conversations, and documents are right here."
                            : "Here's what's happening with your business today."}
                    </p>
                </div>
                <div className="heading-actions">
                    <a
                        className="button secondary"
                        href={client ? "/app/messages" : "/app/calendar"}
                    >
                        {client ? (
                            <>
                                <Users size={15} />
                                Contact your team
                            </>
                        ) : (
                            <>
                                <CalendarDays size={15} />
                                View schedule
                            </>
                        )}
                    </a>
                    {["owner", "office", "estimator"].includes(user.role) && (
                        <Button onClick={() => setNewLead(true)}>
                            <Plus size={16} />
                            Add a lead
                        </Button>
                    )}
                </div>
            </div>
            <ErrorNotice message={state.error} />
            {state.loading ? (
                <Loading />
            ) : (
                <>
                    <div className="metric-strip">
                        {[
                            {
                                label: client ? "Your jobs" : "Active jobs",
                                value: metrics.active_jobs ?? 0,
                                icon: Wrench,
                                note: "Work in progress",
                                href: "/app/jobs",
                                accent: "blue",
                            },
                            {
                                label: client ? "Your estimates" : "Open leads",
                                value: client
                                    ? (metrics.open_estimates ?? 0)
                                    : (metrics.open_leads ?? 0),
                                icon: Users,
                                note: client
                                    ? "Review and approve"
                                    : "Ready for the next step",
                                href: client ? "/app/estimates" : "/app/leads",
                                accent: "violet",
                            },
                            {
                                label: "Outstanding",
                                value: money(
                                    metrics.outstanding_minor,
                                    currency,
                                ),
                                icon: FileText,
                                note: otherCurrencies(
                                    "outstanding_minor",
                                    "Awaiting payment",
                                ),
                                href: "/app/invoices",
                                accent: "amber",
                            },
                            {
                                label: client ? "Paid to date" : "Collected",
                                value: money(metrics.collected_minor, currency),
                                icon: ArrowDownLeft,
                                note: otherCurrencies(
                                    "collected_minor",
                                    client
                                        ? "Your settled payments"
                                        : "Settled invoice payments",
                                ),
                                href: "/app/invoices",
                                accent: "green",
                            },
                        ].map((metric) => (
                            <a
                                className="metric"
                                key={metric.label}
                                href={metric.href}
                            >
                                <div>
                                    <span>{metric.label}</span>
                                    <metric.icon size={17} />
                                </div>
                                <strong>{metric.value}</strong>
                                <small>
                                    <span
                                        className={`metric-dot ${metric.accent}`}
                                    />
                                    {metric.note}
                                    <ArrowUpRight size={13} />
                                </small>
                            </a>
                        ))}
                    </div>
                    <div className="dashboard-grid">
                        <section className="panel today-panel">
                            <div className="panel-heading">
                                <div className="section-title">
                                    <span className="panel-icon">
                                        <CalendarDays size={18} />
                                    </span>
                                    <h2>Today's schedule</h2>
                                    <span className="count-pill">
                                        {jobs.length}
                                    </span>
                                </div>
                                <a className="text-link" href="/app/schedule">
                                    Full schedule
                                    <ArrowRight size={15} />
                                </a>
                            </div>
                            {jobs.length ? (
                                <div className="agenda-list">
                                    {jobs.map((j: RecordData) => (
                                        <button
                                            className="agenda-row"
                                            key={j.id}
                                            onClick={() =>
                                                !client
                                                    ? setJob(j)
                                                    : window.location.assign(
                                                          "/app/jobs",
                                                      )
                                            }
                                        >
                                            <div className="agenda-time">
                                                {new Date(
                                                    j.scheduled_at,
                                                ).toLocaleTimeString("en-US", {
                                                    hour: "numeric",
                                                    minute: "2-digit",
                                                })}
                                            </div>
                                            <span
                                                className={`agenda-line ${j.status === "completed" ? "complete" : ""}`}
                                            />
                                            <div>
                                                <strong>{j.title}</strong>
                                                <small>
                                                    {j.customer_name ??
                                                        j.address ??
                                                        j.service}
                                                </small>
                                            </div>
                                            <Badge>
                                                {j.status ?? "scheduled"}
                                            </Badge>
                                            <ChevronRight size={17} />
                                        </button>
                                    ))}
                                </div>
                            ) : (
                                <Empty
                                    title="A little room in the day"
                                    description="Scheduled visits for today will appear here."
                                    action={
                                        <a
                                            className="text-link"
                                            href="/app/schedule"
                                        >
                                            Open the schedule
                                            <ArrowRight size={15} />
                                        </a>
                                    }
                                />
                            )}
                            <div className="panel-foot">
                                <Clock3 size={14} />
                                <span>
                                    Times shown in your browser's local time
                                    zone
                                </span>
                            </div>
                        </section>
                        <section className="panel attention-panel">
                            <div className="panel-heading">
                                <div className="section-title">
                                    <span className="panel-icon amber">
                                        <ClipboardList size={18} />
                                    </span>
                                    <h2>Needs attention</h2>
                                </div>
                                {tasks.length > 0 && (
                                    <span className="count-pill">
                                        {tasks.length}
                                    </span>
                                )}
                            </div>
                            {tasks.length ? (
                                tasks.map((t: RecordData, index: number) => (
                                    <a
                                        className="attention-row"
                                        href={
                                            t.url ??
                                            t.action_url ??
                                            "/app/leads"
                                        }
                                        key={t.id ?? index}
                                    >
                                        <span
                                            className={`attention-dot ${t.priority === "high" ? "urgent" : ""}`}
                                        />
                                        <div>
                                            <strong>{t.title}</strong>
                                            <small>
                                                {t.description ??
                                                    t.body ??
                                                    t.subtitle}
                                            </small>
                                        </div>
                                        <ChevronRight size={15} />
                                    </a>
                                ))
                            ) : (
                                <div className="all-clear">
                                    <CheckCircle2 size={27} />
                                    <h3>No loose ends</h3>
                                    <p>
                                        Work that needs your attention will show
                                        up here.
                                    </p>
                                </div>
                            )}
                        </section>
                        <section className="panel leads-panel">
                            <div className="panel-heading">
                                <div className="section-title">
                                    <span className="panel-icon">
                                        <Users size={18} />
                                    </span>
                                    <h2>
                                        {client
                                            ? "Recent updates"
                                            : "Recent leads"}
                                    </h2>
                                </div>
                                <a
                                    className="text-link"
                                    href={
                                        client
                                            ? "/app/notifications"
                                            : "/app/leads"
                                    }
                                >
                                    View all
                                    <ArrowRight size={15} />
                                </a>
                            </div>
                            {(client ? d.notifications : d.leads)?.length ? (
                                (client ? d.notifications : d.leads)
                                    .slice(0, 5)
                                    .map((lead: RecordData) => (
                                        <a
                                            className="lead-row"
                                            key={lead.id}
                                            href={
                                                client
                                                    ? "/app/notifications"
                                                    : "/app/leads"
                                            }
                                        >
                                            <span className="avatar small">
                                                {initials(
                                                    lead.name ??
                                                        lead.title ??
                                                        "Lead",
                                                )}
                                            </span>
                                            <span>
                                                <strong>
                                                    {lead.name ?? lead.title}
                                                </strong>
                                                <small>
                                                    {lead.service ??
                                                        lead.body ??
                                                        lead.email}
                                                </small>
                                            </span>
                                            <Badge>
                                                {lead.stage ??
                                                    lead.status ??
                                                    "new"}
                                            </Badge>
                                            <ChevronRight size={15} />
                                        </a>
                                    ))
                            ) : (
                                <Empty
                                    title={
                                        client
                                            ? "No updates yet"
                                            : "Your next job starts here"
                                    }
                                    description={
                                        client
                                            ? "Updates from your team will appear here."
                                            : "Website inquiries and new leads will appear in this queue."
                                    }
                                />
                            )}
                        </section>
                        <section className="panel activity-panel">
                            <div className="panel-heading">
                                <div className="section-title">
                                    <span className="panel-icon">
                                        <Clock3 size={18} />
                                    </span>
                                    <h2>Latest activity</h2>
                                </div>
                            </div>
                            {d.activity?.length ? (
                                <div className="activity-list">
                                    {d.activity
                                        .slice(0, 5)
                                        .map((a: RecordData, index: number) => (
                                            <div
                                                className="activity-item"
                                                key={a.id ?? index}
                                            >
                                                <span className="activity-marker" />
                                                <div>
                                                    <p>
                                                        {a.title ??
                                                            a.description ??
                                                            label(
                                                                a.action ??
                                                                    a.event,
                                                            )}
                                                    </p>
                                                    <small>
                                                        {date(a.created_at, {
                                                            month: "short",
                                                            day: "numeric",
                                                            hour: "numeric",
                                                            minute: "2-digit",
                                                        })}
                                                    </small>
                                                </div>
                                            </div>
                                        ))}
                                </div>
                            ) : (
                                <Empty
                                    title="The start of a well-run day"
                                    description="Your team's recent activity will appear here."
                                />
                            )}
                        </section>
                    </div>
                </>
            )}
            {newLead && (
                <RecordEditor
                    kind="leads"
                    record={null}
                    onClose={() => setNewLead(false)}
                    onSaved={() => {
                        setNewLead(false);
                        state.reload();
                        onToast("Lead created");
                    }}
                />
            )}
            {job &&
                (!["owner", "office", "dispatcher"].includes(user.role) ? (
                    <RecordDetails
                        kind="jobs"
                        user={user}
                        record={job}
                        onClose={() => setJob(null)}
                        onSaved={() => {
                            setJob(null);
                            state.reload();
                            onToast("Job updated");
                        }}
                    />
                ) : (
                    <RecordEditor
                        kind="jobs"
                        record={job}
                        onClose={() => setJob(null)}
                        onSaved={() => {
                            setJob(null);
                            state.reload();
                            onToast("Job updated");
                        }}
                    />
                ))}
        </>
    );
}
export function Reports({ business }: { business: any }) {
    const state = useData<any>("/api/v1/dashboard");
    const d = state.data ?? {};
    const metrics = d.metrics ?? {};
    const leads = d.leads ?? [];
    const sourceCounts = leads.reduce(
        (result: Record<string, number>, lead: RecordData) => ({
            ...result,
            [lead.source ?? "Direct"]:
                Number(result[lead.source ?? "Direct"] ?? 0) + 1,
        }),
        {},
    );
    const maximum = Math.max(1, ...(Object.values(sourceCounts) as number[]));
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>Business reports</h1>
                    <p>
                        A practical view of your pipeline, active work, and
                        collected revenue.
                    </p>
                </div>
            </div>
            <ErrorNotice message={state.error} />
            {state.loading ? (
                <Loading />
            ) : (
                <>
                    <div className="metric-strip">
                        <div className="metric">
                            <span>Open leads</span>
                            <strong>{metrics.open_leads ?? 0}</strong>
                        </div>
                        <div className="metric">
                            <span>Active jobs</span>
                            <strong>{metrics.active_jobs ?? 0}</strong>
                        </div>
                        <div className="metric">
                            <span>Outstanding</span>
                            <strong>
                                {money(
                                    metrics.outstanding_minor,
                                    metrics.currency ?? business.currency,
                                )}
                            </strong>
                        </div>
                        <div className="metric">
                            <span>Collected</span>
                            <strong>
                                {money(
                                    metrics.collected_minor,
                                    metrics.currency ?? business.currency,
                                )}
                            </strong>
                        </div>
                    </div>
                    <div className="dashboard-grid">
                        <section className="panel">
                            <div className="panel-heading">
                                <h2>Recent lead sources</h2>
                                <span className="count">
                                    Based on recent leads
                                </span>
                            </div>
                            <div className="source-chart">
                                {Object.entries(sourceCounts).map(
                                    ([source, total]) => (
                                        <div
                                            className="source-row"
                                            key={source}
                                        >
                                            <span>{label(source)}</span>
                                            <div>
                                                <span
                                                    style={{
                                                        width: `${(Number(total) / maximum) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                            <strong>{Number(total)}</strong>
                                        </div>
                                    ),
                                )}
                                {!leads.length && (
                                    <Empty
                                        title="No lead data yet"
                                        description="Capture leads with a source to see where inquiries come from."
                                    />
                                )}
                            </div>
                        </section>
                        <section className="panel">
                            <div className="panel-heading">
                                <h2>Keep your reports useful</h2>
                            </div>
                            <div className="settings-form">
                                <p>
                                    Record a source for every lead, update job
                                    statuses, and apply payments to invoices.
                                    The overview uses these records directly.
                                </p>
                                <div className="quick-links">
                                    <a href="/app/leads">
                                        Review lead pipeline
                                        <ArrowRight size={15} />
                                    </a>
                                    <a href="/app/invoices">
                                        Review unpaid invoices
                                        <ArrowRight size={15} />
                                    </a>
                                </div>
                            </div>
                        </section>
                    </div>
                </>
            )}
        </>
    );
}
