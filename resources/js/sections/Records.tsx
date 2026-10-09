/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useState } from "react";
import {
    CalendarDays,
    ClipboardList,
    Plus,
    Users,
    ArrowUpRight,
} from "lucide-react";
import {
    api,
    date,
    dateKey,
    localDateTime,
    formValues,
    initials,
    label,
    money,
    type RecordData,
    type User,
} from "../api";
import {
    Badge,
    Button,
    DataTable,
    Empty,
    ErrorNotice,
    Field,
    Loading,
    Modal,
    SelectField,
    TextField,
    Toolbar,
    useData,
} from "../components/ui";
import { JobOperations, CustomerProperties } from "./JobOperations";
type Kind = "leads" | "customers" | "jobs";
const configurations = {
    leads: {
        singular: "lead",
        title: "Leads",
        description: "Turn the next inquiry into your next job.",
        states: [
            "new",
            "contacted",
            "qualified",
            "estimate_sent",
            "won",
            "lost",
        ],
        fields: [
            ["name", "Name", "text", true],
            ["email", "Email", "email"],
            ["phone", "Phone", "tel"],
            ["service", "Service requested", "text"],
            ["source", "Lead source", "text"],
            ["follow_up_at", "Follow-up date", "date"],
        ],
    },
    customers: {
        singular: "customer",
        title: "Customers",
        description:
            "Every relationship, property, and conversation in one place.",
        states: [],
        fields: [
            ["name", "Customer name", "text", true],
            ["email", "Email", "email"],
            ["phone", "Phone", "tel"],
            ["address", "Street address", "text"],
            ["city", "City", "text"],
            ["region", "State / province", "text"],
            ["postal_code", "ZIP / postal code", "text"],
        ],
    },
    jobs: {
        singular: "job",
        title: "Jobs",
        description: "Keep work moving from approved scope to a job well done.",
        states: [
            "scheduled",
            "in_progress",
            "blocked",
            "completed",
            "cancelled",
        ],
        fields: [
            ["title", "Job title", "text", true],
            ["service", "Service", "text"],
            ["address", "Service address", "text"],
            ["scheduled_at", "Visit date and time", "datetime-local"],
        ],
    },
} as const;
export default function Records({
    kind,
    onToast,
    user,
}: {
    kind: Kind;
    onToast: (t: string) => void;
    user: User;
}) {
    const canManage =
        kind === "jobs"
            ? ["owner", "office", "dispatcher"].includes(user.role)
            : ["owner", "office", "estimator"].includes(user.role);
    const config = configurations[kind];
    const { data, loading, error, reload } = useData<RecordData[]>(
        `/api/v1/${kind}`,
    );
    const [query, setQuery] = useState(
        new URLSearchParams(window.location.search).get("q") ?? "",
    );
    const [filter, setFilter] = useState("all");
    const [editing, setEditing] = useState<RecordData | null | false>(false);
    const rows = (data ?? []).filter(
        (r) =>
            (filter === "all" || (r.stage ?? r.status) === filter) &&
            [r.name, r.title, r.email, r.phone, r.service].some((v) =>
                String(v ?? "")
                    .toLowerCase()
                    .includes(query.toLowerCase()),
            ),
    );
    const cols =
        kind === "leads"
            ? [
                  {
                      key: "name",
                      label: "Lead",
                      render: (r: RecordData) => (
                          <div className="cell-person">
                              <span className="avatar small">
                                  {initials(r.name ?? "L")}
                              </span>
                              <span>
                                  <strong>{r.name}</strong>
                                  <small>
                                      {r.email ??
                                          r.phone ??
                                          "No contact details"}
                                  </small>
                              </span>
                          </div>
                      ),
                  },
                  { key: "service", label: "Service" },
                  {
                      key: "source",
                      label: "Source",
                      render: (r: RecordData) => (
                          <span className="muted">
                              {label(r.source ?? "Direct inquiry")}
                          </span>
                      ),
                  },
                  {
                      key: "stage",
                      label: "Stage",
                      render: (r: RecordData) => (
                          <Badge>{r.stage ?? "new"}</Badge>
                      ),
                  },
                  {
                      key: "follow_up_at",
                      label: "Follow-up",
                      render: (r: RecordData) => date(r.follow_up_at),
                  },
              ]
            : kind === "customers"
              ? [
                    {
                        key: "name",
                        label: "Customer",
                        render: (r: RecordData) => (
                            <div className="cell-person">
                                <span className="avatar small">
                                    {initials(r.name ?? "C")}
                                </span>
                                <strong>{r.name}</strong>
                            </div>
                        ),
                    },
                    { key: "email", label: "Email" },
                    { key: "phone", label: "Phone" },
                    {
                        key: "address",
                        label: "Address",
                        render: (r: RecordData) => (
                            <span>
                                {[r.address, r.city]
                                    .filter(Boolean)
                                    .join(", ") || "—"}
                            </span>
                        ),
                    },
                ]
              : [
                    {
                        key: "title",
                        label: "Job",
                        render: (r: RecordData) => (
                            <div>
                                <strong>{r.title}</strong>
                                <small>
                                    {r.customer_name ??
                                        r.customer?.name ??
                                        r.service}
                                </small>
                            </div>
                        ),
                    },
                    {
                        key: "scheduled_at",
                        label: "Scheduled",
                        render: (r: RecordData) =>
                            date(r.scheduled_at, {
                                month: "short",
                                day: "numeric",
                                hour: "numeric",
                                minute: "2-digit",
                            }),
                    },
                    {
                        key: "assigned_to",
                        label: "Assigned to",
                        render: (r: RecordData) =>
                            r.assigned_name ??
                            r.assignee?.name ??
                            (r.assigned_to?.length
                                ? `${r.assigned_to.length} team member${r.assigned_to.length === 1 ? "" : "s"}`
                                : "Unassigned"),
                    },
                    {
                        key: "status",
                        label: "Status",
                        render: (r: RecordData) => (
                            <Badge>{r.status ?? "requested"}</Badge>
                        ),
                    },
                    {
                        key: "priority",
                        label: "Priority",
                        render: (r: RecordData) =>
                            r.priority === "urgent" ? (
                                <Badge tone="red">Urgent</Badge>
                            ) : (
                                label(r.priority ?? "normal")
                            ),
                    },
                ];
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>{config.title}</h1>
                    <p>{config.description}</p>
                </div>
                {canManage && (
                    <Button onClick={() => setEditing(null)}>
                        <Plus size={16} />
                        New {config.singular}
                    </Button>
                )}
            </div>
            {kind === "leads" && data && (
                <div className="pipeline-summary">
                    {config.states.slice(0, 5).map((stage, index) => (
                        <button
                            key={stage}
                            onClick={() =>
                                setFilter(filter === stage ? "all" : stage)
                            }
                            className={filter === stage ? "selected" : ""}
                        >
                            <span className={`stage-dot stage-${index}`} />
                            <span>{label(stage)}</span>
                            <strong>
                                {
                                    data.filter(
                                        (r) => (r.stage ?? "new") === stage,
                                    ).length
                                }
                            </strong>
                        </button>
                    ))}
                </div>
            )}
            <section className="panel">
                <Toolbar
                    query={query}
                    setQuery={setQuery}
                    placeholder={`Search ${kind}…`}
                >
                    {config.states.length > 0 && (
                        <select
                            aria-label="Filter by status"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                        >
                            <option value="all">
                                All {kind === "leads" ? "stages" : "statuses"}
                            </option>
                            {config.states.map((s) => (
                                <option key={s} value={s}>
                                    {label(s)}
                                </option>
                            ))}
                        </select>
                    )}
                    <span className="count">
                        {rows.length} {kind}
                    </span>
                </Toolbar>
                <ErrorNotice message={error} />
                {loading ? (
                    <Loading />
                ) : (
                    <DataTable
                        columns={cols}
                        rows={rows}
                        onRow={setEditing}
                        empty={
                            <Empty
                                title={`No ${kind} ${query || filter !== "all" ? "match your search" : "yet"}`}
                                description={`Add a ${config.singular} to keep your work organized.`}
                                action={
                                    canManage ? (
                                        <Button
                                            variant="secondary"
                                            onClick={() => setEditing(null)}
                                        >
                                            Add {config.singular}
                                        </Button>
                                    ) : undefined
                                }
                            />
                        }
                    />
                )}
            </section>
            {editing !== false &&
                (!canManage && editing ? (
                    <RecordDetails
                        record={editing}
                        kind={kind}
                        user={user}
                        onClose={() => setEditing(false)}
                        onSaved={() => {
                            setEditing(false);
                            reload();
                            onToast("Job updated");
                        }}
                    />
                ) : (
                    <RecordEditor
                        kind={kind}
                        record={editing}
                        onClose={() => setEditing(false)}
                        onSaved={() => {
                            setEditing(false);
                            reload();
                            onToast(`${label(config.singular)} saved`);
                        }}
                    />
                ))}
        </>
    );
}
export function RecordEditor({
    kind,
    record,
    onClose,
    onSaved,
}: {
    kind: Kind;
    record: RecordData | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [portalStatus, setPortalStatus] = useState("");
    const config = configurations[kind];
    const customers = useData<RecordData[]>("/api/v1/customers");
    const team = useData<RecordData[]>("/api/v1/team");
    async function save(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setBusy(true);
        setError("");
        const values: any = formValues(e.currentTarget);
        if (record?.version) values.expected_version = record.version;
        if (kind === "jobs") {
            values.assigned_to = new FormData(e.currentTarget).getAll(
                "assigned_to",
            );
            values.duration_minutes = Number(values.duration_minutes ?? 60);
            if (values.scheduled_at)
                values.scheduled_at = new Date(
                    values.scheduled_at,
                ).toISOString();
        }
        try {
            await api(
                `${kind}${record ? `/${record.id}` : ""}`,
                record ? "PATCH" : "POST",
                values,
            );
            onSaved();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <Modal
            title={`${record ? "Edit" : "New"} ${config.singular}`}
            description={
                record
                    ? "Update details and keep the team informed."
                    : "Capture the essentials. You can add more details later."
            }
            onClose={onClose}
        >
            <form onSubmit={save}>
                <ErrorNotice message={error} />
                <div className="form-grid">
                    {config.fields.map((field) => (
                        <Field
                            key={field[0]}
                            name={field[0]}
                            label={field[1]}
                            type={field[2]}
                            required={field[3] === true}
                            defaultValue={
                                field[2] === "datetime-local"
                                    ? localDateTime(record?.[field[0]])
                                    : (record?.[field[0]] ?? "")
                            }
                        />
                    ))}
                    {kind === "jobs" && (
                        <SelectField
                            label="Customer"
                            name="customer_id"
                            required
                            defaultValue={record?.customer_id ?? ""}
                        >
                            <option value="">Choose a customer</option>
                            {customers.data?.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SelectField>
                    )}
                    {config.states.length > 0 && (
                        <SelectField
                            label={kind === "leads" ? "Stage" : "Status"}
                            name={kind === "leads" ? "stage" : "status"}
                            defaultValue={
                                record?.stage ??
                                record?.status ??
                                config.states[0]
                            }
                        >
                            {config.states.map((s) => (
                                <option key={s} value={s}>
                                    {label(s)}
                                </option>
                            ))}
                        </SelectField>
                    )}
                    {kind !== "customers" && (
                        <>
                            {kind === "jobs" ? (
                                <fieldset className="crew-field">
                                    <legend>Assigned crew</legend>
                                    {team.data
                                        ?.filter((t) => t.role !== "client")
                                        .map((t) => (
                                            <label
                                                className="check-field"
                                                key={t.id}
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="assigned_to"
                                                    value={t.id}
                                                    defaultChecked={
                                                        record?.assigned_to?.includes(
                                                            t.id,
                                                        ) ?? false
                                                    }
                                                />
                                                {t.name}
                                            </label>
                                        ))}
                                </fieldset>
                            ) : (
                                <SelectField
                                    label="Assigned to"
                                    name="assigned_to"
                                    defaultValue={record?.assigned_to ?? ""}
                                >
                                    <option value="">Unassigned</option>
                                    {team.data
                                        ?.filter((t) => t.role !== "client")
                                        .map((t) => (
                                            <option key={t.id} value={t.id}>
                                                {t.name}
                                            </option>
                                        ))}
                                </SelectField>
                            )}
                            {kind === "jobs" && (
                                <Field
                                    label="Visit length (minutes)"
                                    name="duration_minutes"
                                    type="number"
                                    min="15"
                                    max="1440"
                                    step="15"
                                    defaultValue={
                                        record?.duration_minutes ?? 60
                                    }
                                />
                            )}
                            <SelectField
                                label="Priority"
                                name="priority"
                                defaultValue={record?.priority ?? "normal"}
                            >
                                <option value="low">Low</option>
                                <option value="normal">Normal</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </SelectField>
                        </>
                    )}
                </div>
                <TextField
                    label={kind === "jobs" ? "Scope and access notes" : "Notes"}
                    name={kind === "jobs" ? "description" : "notes"}
                    defaultValue={record?.description ?? record?.notes ?? ""}
                />
                {kind === "customers" && record && (
                    <div className="portal-invitation">
                        <h3>Client portal access</h3>
                        <p className="help-text">
                            Send an invitation that links this customer's
                            account securely.
                        </p>
                        {portalStatus && (
                            <div className="notice success" role="status">
                                {portalStatus}
                            </div>
                        )}
                        <Button
                            type="button"
                            variant="secondary"
                            busy={busy}
                            disabled={!record.email}
                            onClick={async () => {
                                setBusy(true);
                                setError("");
                                try {
                                    const r = await api(
                                        `customers/${record.id}/portal-invitation`,
                                        "POST",
                                        { email: record.email },
                                    );
                                    setPortalStatus(
                                        r.message ??
                                            "Portal invitation queued for delivery.",
                                    );
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            Invite to client portal
                        </Button>
                        {!record.email && (
                            <small>Save an email address first.</small>
                        )}
                    </div>
                )}
                <div className="form-footer">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button busy={busy}>Save {config.singular}</Button>
                </div>
            </form>
            {kind === "jobs" && record && (
                <JobOperations job={record} role="staff" />
            )}
            {kind === "customers" && record && (
                <CustomerProperties customerId={record.id} />
            )}
        </Modal>
    );
}
export function Schedule({
    onToast,
    user,
}: {
    onToast: (t: string) => void;
    user: User;
}) {
    const { data, error, loading, reload } =
        useData<RecordData[]>("/api/v1/jobs");
    const [editing, setEditing] = useState<RecordData | null>(null);
    const [selected, setSelected] = useState(dateKey());
    const weekStart = new Date(`${selected}T12:00:00`);
    weekStart.setDate(weekStart.getDate() - weekStart.getDay() + 1);
    const days = Array.from({ length: 7 }, (_, i) => {
        const d = new Date(weekStart);
        d.setDate(d.getDate() + i);
        return d;
    });
    const jobs = (data ?? []).filter(
        (j) => dateKey(j.scheduled_at ?? "") === selected,
    );
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>Schedule</h1>
                    <p>
                        A clear day for your team. A reliable arrival for your
                        customers.
                    </p>
                </div>
                <Field
                    label="Go to date"
                    type="date"
                    value={selected}
                    onChange={(e) => setSelected(e.target.value)}
                />
            </div>
            <div className="week-strip">
                {days.map((day) => {
                    const iso = dateKey(day);
                    return (
                        <button
                            key={iso}
                            className={selected === iso ? "selected" : ""}
                            onClick={() => setSelected(iso)}
                        >
                            <span>
                                {day.toLocaleDateString("en-US", {
                                    weekday: "short",
                                })}
                            </span>
                            <strong>{day.getDate()}</strong>
                            <small>
                                {
                                    (data ?? []).filter(
                                        (j) =>
                                            dateKey(j.scheduled_at ?? "") ===
                                            iso,
                                    ).length
                                }{" "}
                                visits
                            </small>
                        </button>
                    );
                })}
            </div>
            <ErrorNotice message={error} />
            <section className="panel">
                <div className="panel-heading">
                    <h2>
                        {date(`${selected}T12:00:00`, {
                            weekday: "long",
                            month: "long",
                            day: "numeric",
                        })}
                    </h2>
                    <span className="count">
                        {jobs.length} scheduled visits
                    </span>
                </div>
                {loading ? (
                    <Loading />
                ) : jobs.length ? (
                    <div className="agenda-list">
                        {jobs
                            .sort((a, b) =>
                                String(a.scheduled_at).localeCompare(
                                    b.scheduled_at,
                                ),
                            )
                            .map((job) => (
                                <button
                                    className="agenda-row"
                                    onClick={() => setEditing(job)}
                                    key={job.id}
                                >
                                    <div className="agenda-time">
                                        {new Date(
                                            job.scheduled_at,
                                        ).toLocaleTimeString("en-US", {
                                            hour: "numeric",
                                            minute: "2-digit",
                                        })}
                                    </div>
                                    <span className="agenda-line" />
                                    <div>
                                        <strong>{job.title}</strong>
                                        <small>
                                            {job.address ??
                                                job.customer_name ??
                                                "Add a service address"}
                                        </small>
                                    </div>
                                    <Badge>{job.status}</Badge>
                                    <ArrowUpRight size={17} />
                                </button>
                            ))}
                    </div>
                ) : (
                    <Empty
                        title="Room on the schedule"
                        description="Open a job and set its visit date to add it here."
                        action={
                            <a href="/app/jobs" className="button secondary">
                                View jobs
                            </a>
                        }
                    />
                )}
            </section>
            {(data ?? []).some((j) => !j.scheduled_at) && (
                <section className="panel lower-panel">
                    <div className="panel-heading">
                        <h2>Waiting to be scheduled</h2>
                        <CalendarDays size={18} />
                    </div>
                    {(data ?? [])
                        .filter(
                            (j) => !j.scheduled_at && j.status !== "completed",
                        )
                        .map((j) => (
                            <button
                                className="list-row"
                                key={j.id}
                                onClick={() => setEditing(j)}
                            >
                                <span>
                                    <strong>{j.title}</strong>
                                    <small>{j.service}</small>
                                </span>
                                <span className="text-link">
                                    Schedule visit
                                    <ArrowUpRight size={15} />
                                </span>
                            </button>
                        ))}
                </section>
            )}
            {editing &&
                (!["owner", "office", "dispatcher"].includes(user.role) ? (
                    <RecordDetails
                        record={editing}
                        kind="jobs"
                        user={user}
                        onClose={() => setEditing(null)}
                        onSaved={() => {
                            setEditing(null);
                            reload();
                            onToast("Job updated");
                        }}
                    />
                ) : (
                    <RecordEditor
                        kind="jobs"
                        record={editing}
                        onClose={() => setEditing(null)}
                        onSaved={() => {
                            setEditing(null);
                            reload();
                            onToast("Schedule updated");
                        }}
                    />
                ))}
        </>
    );
}

export function RecordDetails({
    record,
    kind,
    user,
    onClose,
    onSaved,
}: {
    record: RecordData;
    kind: Kind;
    user: User;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    return (
        <Modal title={record.title ?? record.name} onClose={onClose}>
            <div className="detail-lines">
                {[
                    "status",
                    "stage",
                    "service",
                    "address",
                    "scheduled_at",
                    "email",
                    "phone",
                ]
                    .filter((key) => record[key])
                    .map((key) => (
                        <div key={key}>
                            <span>{label(key)}</span>
                            <strong>
                                {key === "scheduled_at"
                                    ? date(record[key])
                                    : label(record[key])}
                            </strong>
                        </div>
                    ))}
            </div>
            {record.description && (
                <p className="detail-description">{record.description}</p>
            )}
            {kind === "jobs" && user.role === "technician" && (
                <form
                    className="inset-form"
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setBusy(true);
                        setError("");
                        const values: any = formValues(e.currentTarget);
                        values.completion_requested =
                            values.completion_requested === "on";
                        values.time_minutes = Number(values.time_minutes ?? 0);
                        try {
                            await api(`jobs/${record.id}`, "PATCH", values);
                            onSaved();
                        } catch (e) {
                            setError((e as Error).message);
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    <ErrorNotice message={error} />
                    <SelectField
                        label="Job status"
                        name="status"
                        defaultValue={
                            record.status === "scheduled"
                                ? "in_progress"
                                : record.status
                        }
                    >
                        <option value="in_progress">In progress</option>
                        <option value="blocked">Blocked</option>
                        <option value="completed">Completed</option>
                    </SelectField>
                    <TextField
                        label="Field notes"
                        name="field_notes"
                        defaultValue={record.field_notes ?? ""}
                    />
                    <Field
                        label="Time spent (minutes)"
                        name="time_minutes"
                        type="number"
                        min="0"
                        max="1440"
                        defaultValue={record.time_minutes ?? 0}
                    />
                    <label className="check-field">
                        <input
                            type="checkbox"
                            name="completion_requested"
                            defaultChecked={record.completion_requested}
                        />
                        Request completion review
                    </label>
                    <Button busy={busy}>Save job update</Button>
                </form>
            )}
            {kind === "jobs" && <JobOperations job={record} role={user.role} />}
        </Modal>
    );
}
