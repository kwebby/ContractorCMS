/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useEffect, useRef, useState, type FormEvent } from "react";
import {
    ArrowLeft,
    CalendarDays,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    GripVertical,
    Plus,
    RefreshCw,
    Trash2,
} from "lucide-react";
import {
    api,
    ApiError,
    date,
    dateKey,
    formValues,
    label,
    localDateTime,
    type RecordData,
    type User,
} from "../api";
import {
    Badge,
    Button,
    Empty,
    ErrorNotice,
    Field,
    Loading,
    SelectField,
    TextField,
    Toolbar,
    useData,
} from "../components/ui";
import "../../css/planning.css";

type PlanningProps = { user: User; onToast: (message: string) => void };
type ChecklistItem = { label: string; done: boolean };
const columns = [
    { value: "open", title: "To do", description: "Ready for the next step" },
    {
        value: "in_progress",
        title: "In progress",
        description: "Work underway",
    },
    { value: "blocked", title: "Blocked", description: "Needs attention" },
    { value: "done", title: "Done", description: "Work completed" },
];
function permitted(user: User, permissions: string[], roles: string[]) {
    return (
        roles.includes(user.role) ||
        (user.permissions ?? []).some(
            (permission) =>
                permission === "*" || permissions.includes(permission),
        )
    );
}
function managesPlanning(user: User) {
    return (
        user.role !== "client" &&
        user.role !== "technician" &&
        permitted(
            user,
            ["crm.manage", "operations.manage"],
            ["owner", "office", "dispatcher", "estimator"],
        )
    );
}
function readsCrm(user: User) {
    return (
        user.role !== "client" &&
        user.role !== "technician" &&
        permitted(
            user,
            ["crm.manage", "crm.view", "reports.view"],
            [
                "owner",
                "office",
                "dispatcher",
                "estimator",
                "finance",
                "marketing",
            ],
        )
    );
}
function readsOperations(user: User) {
    return permitted(
        user,
        ["operations.manage", "operations.view", "reports.view"],
        [
            "owner",
            "office",
            "dispatcher",
            "estimator",
            "finance",
            "technician",
            "client",
        ],
    );
}
function canUpdateTask(user: User, task: RecordData) {
    return (
        managesPlanning(user) ||
        (user.role === "technician" &&
            (task.assigned_to ?? []).includes(user.id))
    );
}
function taskChecklist(task: RecordData): ChecklistItem[] {
    return Array.isArray(task.checklist) ? task.checklist : [];
}
function dayKey(value: unknown) {
    if (!value) return "";
    const text = String(value);
    return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : dateKey(text);
}
function dayDate(day: string) {
    return new Date(`${day}T12:00:00`);
}
function dayLabel(day: string) {
    return dayDate(day).toLocaleDateString("en-US", {
        weekday: "long",
        month: "long",
        day: "numeric",
        year: "numeric",
    });
}
function relationValue(record: RecordData | null) {
    for (const kind of ["job", "customer", "lead"]) {
        if (record?.[`${kind}_id`]) return `${kind}:${record[`${kind}_id`]}`;
    }
    return "";
}
function relationPayload(value: FormDataEntryValue | undefined) {
    const [kind, id] = String(value ?? "").split(":");
    return {
        job_id: kind === "job" ? id : null,
        customer_id: kind === "customer" ? id : null,
        lead_id: kind === "lead" ? id : null,
    };
}
function RelatedRecord({
    record,
    jobs,
    customers,
    leads,
}: {
    record: RecordData | null;
    jobs: RecordData[];
    customers: RecordData[];
    leads: RecordData[];
}) {
    const selected = relationValue(record);
    const available = [
        ...jobs.map((r) => `job:${r.id}`),
        ...customers.map((r) => `customer:${r.id}`),
        ...leads.map((r) => `lead:${r.id}`),
    ];
    return (
        <SelectField
            name="relation"
            label="Related CRM record"
            defaultValue={selected}
        >
            <option value="">No linked record</option>
            {selected && !available.includes(selected) && (
                <option value={selected}>Current linked record</option>
            )}
            {[
                ["Jobs", "job", jobs],
                ["Customers", "customer", customers],
                ["Leads", "lead", leads],
            ].map(([caption, kind, records]) => (
                <optgroup label={String(caption)} key={String(kind)}>
                    {(records as RecordData[]).map((r) => (
                        <option key={r.id} value={`${kind}:${r.id}`}>
                            {r.title ?? r.name}
                        </option>
                    ))}
                </optgroup>
            ))}
        </SelectField>
    );
}

export function Tasks({ user, onToast }: PlanningProps) {
    const tasks = useData<RecordData[]>(
        user.role === "client" ? null : "/api/v1/tasks",
    );
    const [query, setQuery] = useState(
        new URLSearchParams(window.location.search).get("q") ?? "",
    );
    const [assignment, setAssignment] = useState("all");
    const [editing, setEditing] = useState<RecordData | null | false>(false);
    const [error, setError] = useState("");
    const [busyIds, setBusyIds] = useState<string[]>([]);
    const pending = useRef(new Set<string>());
    const [dragging, setDragging] = useState<string | null>(null);
    const manages = managesPlanning(user);
    const rows = (tasks.data ?? []).filter(
        (task) =>
            (assignment !== "mine" ||
                (task.assigned_to ?? []).includes(user.id)) &&
            `${task.title} ${task.notes ?? ""}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const done = rows.filter((task) => task.status === "done").length;
    const percent = rows.length ? Math.round((done / rows.length) * 100) : 0;

    async function update(task: RecordData, values: Record<string, unknown>) {
        if (pending.current.has(task.id) || !canUpdateTask(user, task)) return;
        pending.current.add(task.id);
        setBusyIds([...pending.current]);
        setError("");
        try {
            const response = await api(`/api/v1/tasks/${task.id}`, "PATCH", {
                ...values,
                expected_version: task.version,
            });
            tasks.setData((current) =>
                (current ?? []).map((row) =>
                    row.id === task.id ? response.data : row,
                ),
            );
            onToast("Task updated");
        } catch (e) {
            setError((e as Error).message);
            if (e instanceof ApiError && e.status === 409) tasks.reload();
        } finally {
            pending.current.delete(task.id);
            setBusyIds([...pending.current]);
        }
    }
    if (user.role === "client")
        return (
            <Empty
                title="Team workspace"
                description="Your upcoming service work is available in Calendar and Jobs."
            />
        );
    if (editing !== false)
        return (
            <TaskEditor
                key={editing ? `${editing.id}-${editing.version}` : "new"}
                user={user}
                task={editing}
                onCancel={() => setEditing(false)}
                onReload={async () => {
                    const response = await api(
                        `/api/v1/tasks/${editing && editing.id}`,
                    );
                    setEditing(response.data);
                    tasks.reload();
                }}
                onSaved={() => {
                    setEditing(false);
                    tasks.reload();
                    onToast("Task saved");
                }}
            />
        );
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>Task board</h1>
                    <p>
                        A clear next step for every follow-up, job, and team
                        member.
                    </p>
                </div>
                <div className="heading-actions">
                    <a className="button secondary" href="/app/calendar">
                        <CalendarDays size={16} />
                        Calendar
                    </a>
                    {manages && (
                        <Button onClick={() => setEditing(null)}>
                            <Plus size={16} />
                            New task
                        </Button>
                    )}
                </div>
            </div>
            <section
                className="panel planning-summary"
                aria-label="Task completion"
            >
                <div>
                    <ClipboardList size={20} />
                    <div>
                        <h2>Board progress</h2>
                        <p>
                            {done} of {rows.length} tasks done
                            {query || assignment !== "all"
                                ? " in this view"
                                : ""}
                        </p>
                    </div>
                </div>
                <div className="planning-progress">
                    <progress
                        aria-label="Board completion"
                        value={done}
                        max={Math.max(rows.length, 1)}
                    />
                    <strong>{percent}%</strong>
                </div>
            </section>
            <section className="panel planning-toolbar">
                <Toolbar
                    query={query}
                    setQuery={setQuery}
                    placeholder="Search tasks…"
                >
                    <select
                        aria-label="Task assignment"
                        value={assignment}
                        onChange={(e) => setAssignment(e.target.value)}
                    >
                        <option value="all">All visible tasks</option>
                        <option value="mine">Assigned to me</option>
                    </select>
                    <Button
                        variant="ghost"
                        onClick={tasks.reload}
                        disabled={tasks.loading}
                    >
                        <RefreshCw size={14} />
                        Refresh
                    </Button>
                </Toolbar>
            </section>
            <ErrorNotice message={tasks.error || error} />
            {tasks.loading ? (
                <Loading />
            ) : (
                <div className="kanban-board" aria-label="Task progress board">
                    {columns.map((column) => {
                        const items = rows.filter(
                            (task) => (task.status ?? "open") === column.value,
                        );
                        return (
                            <section
                                key={column.value}
                                className={`kanban-column ${column.value}`}
                                aria-label={`${column.title} tasks`}
                                onDragOver={(event) => {
                                    if (dragging) event.preventDefault();
                                }}
                                onDrop={(event) => {
                                    event.preventDefault();
                                    const task = rows.find(
                                        (row) => row.id === dragging,
                                    );
                                    if (task && task.status !== column.value)
                                        void update(task, {
                                            status: column.value,
                                        });
                                    setDragging(null);
                                }}
                            >
                                <div className="kanban-column-heading">
                                    <h2>
                                        <span className="kanban-dot" />
                                        {column.title}
                                        <span className="count">
                                            {items.length}
                                        </span>
                                    </h2>
                                    <p>{column.description}</p>
                                </div>
                                <div className="kanban-cards">
                                    {items.map((task) => {
                                        const checklist = taskChecklist(task);
                                        const completed = checklist.filter(
                                            (item) => item.done,
                                        ).length;
                                        const editable = canUpdateTask(
                                            user,
                                            task,
                                        );
                                        const busy = busyIds.includes(task.id);
                                        const overdue =
                                            task.due_at &&
                                            task.status !== "done" &&
                                            new Date(task.due_at) < new Date();
                                        return (
                                            <article
                                                key={task.id}
                                                className={`task-card ${busy ? "saving" : ""}`}
                                                draggable={editable && !busy}
                                                onDragStart={(event) => {
                                                    event.dataTransfer.setData(
                                                        "text/plain",
                                                        task.id,
                                                    );
                                                    event.dataTransfer.effectAllowed =
                                                        "move";
                                                    setDragging(task.id);
                                                }}
                                                onDragEnd={() =>
                                                    setDragging(null)
                                                }
                                                aria-label={task.title}
                                                aria-busy={busy}
                                            >
                                                <div className="task-card-top">
                                                    <Badge
                                                        tone={
                                                            /high|urgent/.test(
                                                                task.priority ??
                                                                    "",
                                                            )
                                                                ? "red"
                                                                : "blue"
                                                        }
                                                    >
                                                        {task.priority ??
                                                            "normal"}
                                                    </Badge>
                                                    {editable && (
                                                        <GripVertical
                                                            size={15}
                                                            className="muted"
                                                            aria-hidden="true"
                                                        />
                                                    )}
                                                </div>
                                                <h3>
                                                    {editable ? (
                                                        <button
                                                            className="task-title"
                                                            onClick={() =>
                                                                setEditing(task)
                                                            }
                                                            disabled={busy}
                                                        >
                                                            {task.title}
                                                        </button>
                                                    ) : (
                                                        task.title
                                                    )}
                                                </h3>
                                                {task.notes && (
                                                    <p className="task-excerpt">
                                                        {task.notes}
                                                    </p>
                                                )}
                                                {checklist.length > 0 && (
                                                    <div className="task-checklist">
                                                        <div className="planning-progress">
                                                            <progress
                                                                aria-label={`${task.title} checklist completion`}
                                                                value={
                                                                    completed
                                                                }
                                                                max={
                                                                    checklist.length
                                                                }
                                                            />
                                                            <small>
                                                                {completed}/
                                                                {
                                                                    checklist.length
                                                                }
                                                            </small>
                                                        </div>
                                                        {checklist
                                                            .slice(0, 3)
                                                            .map(
                                                                (
                                                                    item,
                                                                    index,
                                                                ) => (
                                                                    <label
                                                                        key={
                                                                            index
                                                                        }
                                                                        className="task-check"
                                                                    >
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={
                                                                                item.done
                                                                            }
                                                                            disabled={
                                                                                !editable ||
                                                                                busy
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) => {
                                                                                const checked =
                                                                                    event
                                                                                        .target
                                                                                        .checked;
                                                                                void update(
                                                                                    task,
                                                                                    {
                                                                                        checklist:
                                                                                            checklist.map(
                                                                                                (
                                                                                                    entry,
                                                                                                    i,
                                                                                                ) =>
                                                                                                    i ===
                                                                                                    index
                                                                                                        ? {
                                                                                                              ...entry,
                                                                                                              done: checked,
                                                                                                          }
                                                                                                        : entry,
                                                                                            ),
                                                                                    },
                                                                                );
                                                                            }}
                                                                        />
                                                                        <span>
                                                                            {
                                                                                item.label
                                                                            }
                                                                        </span>
                                                                    </label>
                                                                ),
                                                            )}
                                                        {checklist.length >
                                                            3 && (
                                                            <small>
                                                                +
                                                                {checklist.length -
                                                                    3}{" "}
                                                                more checklist
                                                                items
                                                            </small>
                                                        )}
                                                    </div>
                                                )}
                                                <div className="task-card-meta">
                                                    {task.due_at ? (
                                                        <span
                                                            className={
                                                                overdue
                                                                    ? "task-overdue"
                                                                    : ""
                                                            }
                                                        >
                                                            <CalendarDays
                                                                size={13}
                                                            />
                                                            {overdue
                                                                ? "Overdue · "
                                                                : ""}
                                                            {date(task.due_at, {
                                                                month: "short",
                                                                day: "numeric",
                                                            })}
                                                        </span>
                                                    ) : (
                                                        <span>No due date</span>
                                                    )}
                                                    <span>
                                                        {(
                                                            task.assigned_to ??
                                                            []
                                                        ).includes(user.id)
                                                            ? "Assigned to you"
                                                            : task.assigned_to
                                                                    ?.length
                                                              ? `${task.assigned_to.length} assigned`
                                                              : "Unassigned"}
                                                    </span>
                                                </div>
                                                {editable && (
                                                    <select
                                                        className="task-status"
                                                        aria-label={`Status for ${task.title}`}
                                                        value={
                                                            task.status ??
                                                            "open"
                                                        }
                                                        disabled={busy}
                                                        onChange={(event) =>
                                                            void update(task, {
                                                                status: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                    >
                                                        {columns.map(
                                                            (status) => (
                                                                <option
                                                                    key={
                                                                        status.value
                                                                    }
                                                                    value={
                                                                        status.value
                                                                    }
                                                                >
                                                                    {
                                                                        status.title
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                )}
                                            </article>
                                        );
                                    })}
                                    {items.length === 0 && (
                                        <div className="kanban-empty">
                                            {column.value === "open" &&
                                            manages ? (
                                                <button
                                                    onClick={() =>
                                                        setEditing(null)
                                                    }
                                                >
                                                    <Plus size={16} />
                                                    Add a task
                                                </button>
                                            ) : (
                                                <span>No tasks here</span>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </section>
                        );
                    })}
                </div>
            )}
        </>
    );
}

function TaskEditor({
    user,
    task,
    onCancel,
    onSaved,
    onReload,
}: Omit<PlanningProps, "onToast"> & {
    task: RecordData | null;
    onCancel: () => void;
    onSaved: () => void;
    onReload: () => Promise<void>;
}) {
    const manages = managesPlanning(user);
    const team = useData<RecordData[]>(manages ? "/api/v1/team" : null);
    const jobs = useData<RecordData[]>(
        manages && readsOperations(user) ? "/api/v1/jobs" : null,
    );
    const customers = useData<RecordData[]>(
        manages && readsCrm(user) ? "/api/v1/customers" : null,
    );
    const leads = useData<RecordData[]>(
        manages && readsCrm(user) ? "/api/v1/leads" : null,
    );
    const [checklist, setChecklist] = useState<ChecklistItem[]>(
        task ? taskChecklist(task) : [],
    );
    const [error, setError] = useState("");
    const [conflict, setConflict] = useState(false);
    const [busy, setBusy] = useState(false);
    const title = useRef<HTMLInputElement>(null);
    useEffect(() => {
        title.current?.focus();
    }, []);
    async function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = event.currentTarget;
        const fields = formValues(form);
        const values: Record<string, unknown> = {
            notes: fields.notes,
            status: fields.status,
            checklist,
        };
        if (manages)
            Object.assign(values, {
                title: fields.title,
                priority: fields.priority,
                due_at: fields.due_at
                    ? new Date(String(fields.due_at)).toISOString()
                    : null,
                assigned_to: new FormData(form).getAll("assigned_to"),
                ...relationPayload(fields.relation),
            });
        if (task) values.expected_version = task.version;
        setBusy(true);
        setError("");
        setConflict(false);
        try {
            await api(
                task ? `/api/v1/tasks/${task.id}` : "/api/v1/tasks",
                task ? "PATCH" : "POST",
                values,
            );
            onSaved();
        } catch (e) {
            setError((e as Error).message);
            setConflict(e instanceof ApiError && e.status === 409);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <div className="page-heading">
                <div>
                    <button
                        className="planning-back"
                        onClick={onCancel}
                        disabled={busy}
                    >
                        <ArrowLeft size={15} />
                        Back to task board
                    </button>
                    <h1>{task ? "Update task" : "New task"}</h1>
                    <p>
                        {manages
                            ? "Give the team a clear outcome and a practical checklist."
                            : "Update your progress and leave notes for the office."}
                    </p>
                </div>
            </div>
            <section className="panel planning-editor">
                <form onSubmit={save}>
                    <ErrorNotice message={error} />
                    {conflict && (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={async () => {
                                setBusy(true);
                                try {
                                    await onReload();
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                            busy={busy}
                        >
                            Reload saved version (discards these edits)
                        </Button>
                    )}
                    <fieldset disabled={busy}>
                        <Field label="Task title">
                            <input
                                ref={title}
                                name="title"
                                aria-label="Task title"
                                required
                                maxLength={180}
                                defaultValue={task?.title ?? ""}
                                readOnly={!manages}
                            />
                        </Field>
                        <div className="form-grid">
                            <SelectField
                                name="status"
                                label="Progress"
                                defaultValue={task?.status ?? "open"}
                            >
                                {columns.map((column) => (
                                    <option
                                        key={column.value}
                                        value={column.value}
                                    >
                                        {column.title}
                                    </option>
                                ))}
                            </SelectField>
                            {manages && (
                                <SelectField
                                    name="priority"
                                    label="Priority"
                                    defaultValue={task?.priority ?? "normal"}
                                >
                                    {["low", "normal", "high", "urgent"].map(
                                        (priority) => (
                                            <option
                                                key={priority}
                                                value={priority}
                                            >
                                                {label(priority)}
                                            </option>
                                        ),
                                    )}
                                </SelectField>
                            )}
                        </div>
                        <TextField
                            name="notes"
                            label="Task notes"
                            maxLength={10000}
                            defaultValue={task?.notes ?? ""}
                            placeholder="Context, blockers, and the next step…"
                        />
                        {manages && (
                            <>
                                <div className="form-grid">
                                    <Field
                                        name="due_at"
                                        label="Due date and time"
                                        type="datetime-local"
                                        defaultValue={localDateTime(
                                            task?.due_at,
                                        )}
                                    />
                                    <RelatedRecord
                                        record={task}
                                        jobs={jobs.data ?? []}
                                        customers={customers.data ?? []}
                                        leads={leads.data ?? []}
                                    />
                                </div>
                                <ErrorNotice
                                    message={
                                        team.error ||
                                        jobs.error ||
                                        customers.error ||
                                        leads.error
                                    }
                                />
                                <fieldset className="planning-assignees">
                                    <legend>Assign team members</legend>
                                    {team.loading ? (
                                        <p className="muted">Loading team…</p>
                                    ) : (
                                        (team.data ?? [])
                                            .filter(
                                                (member) =>
                                                    member.role !== "client" &&
                                                    member.active !== false,
                                            )
                                            .map((member) => (
                                                <label
                                                    className="task-check"
                                                    key={member.id}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        name="assigned_to"
                                                        value={member.id}
                                                        defaultChecked={(
                                                            task?.assigned_to ??
                                                            []
                                                        ).includes(member.id)}
                                                    />
                                                    <span>{member.name}</span>
                                                </label>
                                            ))
                                    )}
                                    {!team.loading && !team.data?.length && (
                                        <p className="muted">
                                            No team members available.
                                        </p>
                                    )}
                                </fieldset>
                            </>
                        )}
                        <div className="planning-checklist-heading">
                            <h2>Checklist</h2>
                            {manages && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={checklist.length >= 50}
                                    onClick={() =>
                                        setChecklist([
                                            ...checklist,
                                            { label: "", done: false },
                                        ])
                                    }
                                >
                                    <Plus size={14} />
                                    Add item
                                </Button>
                            )}
                        </div>
                        {checklist.map((item, index) => (
                            <div className="planning-checklist-row" key={index}>
                                <input
                                    type="checkbox"
                                    aria-label={`Complete checklist item ${index + 1}`}
                                    checked={item.done}
                                    onChange={(event) =>
                                        setChecklist(
                                            checklist.map((row, i) =>
                                                i === index
                                                    ? {
                                                          ...row,
                                                          done: event.target
                                                              .checked,
                                                      }
                                                    : row,
                                            ),
                                        )
                                    }
                                />
                                <input
                                    aria-label={`Checklist item ${index + 1}`}
                                    required
                                    maxLength={200}
                                    value={item.label}
                                    readOnly={!manages}
                                    onChange={(event) =>
                                        setChecklist(
                                            checklist.map((row, i) =>
                                                i === index
                                                    ? {
                                                          ...row,
                                                          label: event.target
                                                              .value,
                                                      }
                                                    : row,
                                            ),
                                        )
                                    }
                                />
                                {manages && (
                                    <button
                                        className="icon-button"
                                        type="button"
                                        aria-label={`Remove checklist item ${index + 1}`}
                                        onClick={() =>
                                            setChecklist(
                                                checklist.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 size={15} />
                                    </button>
                                )}
                            </div>
                        ))}
                        {checklist.length === 0 && (
                            <p className="muted">No checklist items yet.</p>
                        )}
                        <div className="form-footer">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={onCancel}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                busy={busy}
                                disabled={
                                    manages &&
                                    (team.loading ||
                                        !!team.error ||
                                        jobs.loading ||
                                        customers.loading ||
                                        leads.loading)
                                }
                            >
                                <Check size={16} />
                                Save task
                            </Button>
                        </div>
                    </fieldset>
                </form>
            </section>
        </>
    );
}

type CalendarEvent = {
    id: string;
    type: "job" | "visit" | "follow_up" | "task" | "note";
    day: string;
    title: string;
    record: RecordData;
};
function eventTimestamp(event: CalendarEvent): string | null {
    const field = {
        job: "scheduled_at",
        visit: "start_at",
        task: "due_at",
        follow_up: "follow_up_at",
        note: "",
    }[event.type];
    const value = field ? event.record[field] : null;
    return value && !/^\d{4}-\d{2}-\d{2}$/.test(String(value))
        ? String(value)
        : null;
}
function eventTime(event: CalendarEvent) {
    const timestamp = eventTimestamp(event);
    return timestamp
        ? new Date(timestamp).toLocaleTimeString("en-US", {
              hour: "numeric",
              minute: "2-digit",
          })
        : "All day";
}
function eventOrder(event: CalendarEvent) {
    const timestamp = eventTimestamp(event);
    return timestamp ? new Date(timestamp).getTime() : Number.NEGATIVE_INFINITY;
}
const eventLabels = {
    job: "Jobs",
    visit: "Appointments",
    follow_up: "Follow-ups",
    task: "Tasks",
    note: "CRM notes",
};
export function Calendar({ user, onToast }: PlanningProps) {
    const [month, setMonth] = useState(
        () => new Date(new Date().getFullYear(), new Date().getMonth(), 1),
    );
    const [selected, setSelected] = useState(dateKey());
    const [filter, setFilter] = useState("all");
    const [editing, setEditing] = useState<RecordData | null | false>(false);
    const canCrm = readsCrm(user);
    const canNotes =
        user.role !== "technician" &&
        user.role !== "client" &&
        (canCrm || readsOperations(user));
    const manages = managesPlanning(user);
    const jobs = useData<RecordData[]>(
        readsOperations(user) ? "/api/v1/jobs" : null,
    );
    const canVisits = permitted(
        user,
        ["operations.manage", "operations.view"],
        ["owner", "office", "dispatcher", "estimator", "technician", "client"],
    );
    const visits = useData<RecordData[]>(canVisits ? "/api/v1/visits" : null);
    const leads = useData<RecordData[]>(canCrm ? "/api/v1/leads" : null);
    const tasks = useData<RecordData[]>(
        user.role !== "client" && (canCrm || readsOperations(user))
            ? "/api/v1/tasks"
            : null,
    );
    const first = new Date(
        month.getFullYear(),
        month.getMonth(),
        1 - month.getDay(),
    );
    const days = Array.from(
        { length: 42 },
        (_, index) =>
            new Date(
                first.getFullYear(),
                first.getMonth(),
                first.getDate() + index,
            ),
    );
    const notes = useData<RecordData[]>(
        canNotes
            ? `/api/v1/crm-notes?from=${dateKey(days[0])}&to=${dateKey(days[41])}`
            : null,
    );
    const events: CalendarEvent[] = [
        ...(visits.data ?? [])
            .filter((r) => r.start_at && r.status !== "cancelled")
            .map((r) => ({
                id: `visit-${r.id}`,
                type: "visit" as const,
                day: dayKey(r.start_at),
                title: r.job_title ?? r.purpose ?? "Service appointment",
                record: r,
            })),
        ...(jobs.data ?? [])
            .filter((r) => r.scheduled_at && r.status !== "cancelled")
            .map((r) => ({
                id: `job-${r.id}`,
                type: "job" as const,
                day: dayKey(r.scheduled_at),
                title: r.title,
                record: r,
            })),
        ...(leads.data ?? [])
            .filter((r) => r.follow_up_at && !["won", "lost"].includes(r.stage))
            .map((r) => ({
                id: `lead-${r.id}`,
                type: "follow_up" as const,
                day: dayKey(r.follow_up_at),
                title: r.name,
                record: r,
            })),
        ...(tasks.data ?? [])
            .filter((r) => r.due_at)
            .map((r) => ({
                id: `task-${r.id}`,
                type: "task" as const,
                day: dayKey(r.due_at),
                title: r.title,
                record: r,
            })),
        ...(notes.data ?? []).map((r) => ({
            id: `note-${r.id}`,
            type: "note" as const,
            day: r.note_date,
            title: r.title,
            record: r,
        })),
    ].filter((event) => filter === "all" || event.type === filter);
    const agenda = events
        .filter((event) => event.day === selected)
        .sort(
            (a, b) =>
                eventOrder(a) - eventOrder(b) || a.title.localeCompare(b.title),
        );
    const loading =
        jobs.loading ||
        visits.loading ||
        leads.loading ||
        tasks.loading ||
        notes.loading;
    const errors = [
        ["Jobs", jobs.error],
        ["Appointments", visits.error],
        ["Follow-ups", leads.error],
        ["Tasks", tasks.error],
        ["CRM notes", notes.error],
    ]
        .filter(([, error]) => error)
        .map(([caption, error]) => `${caption}: ${error}`)
        .join(" ");
    const monthLabel = month.toLocaleDateString("en-US", {
        month: "long",
        year: "numeric",
    });
    function navigate(offset: number) {
        const next = new Date(
            month.getFullYear(),
            month.getMonth() + offset,
            1,
        );
        setMonth(next);
        setSelected(dateKey(next));
        setEditing(false);
    }
    function today() {
        const now = new Date();
        setMonth(new Date(now.getFullYear(), now.getMonth(), 1));
        setSelected(dateKey(now));
        setEditing(false);
    }
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>Calendar</h1>
                    <p>
                        {user.role === "client"
                            ? "Your service appointments and scheduled work, at a glance."
                            : "Bring scheduled work, follow-ups, and CRM notes into one view."}
                    </p>
                    <small>
                        Times shown in{" "}
                        {Intl.DateTimeFormat().resolvedOptions().timeZone}. CRM
                        notes are all day.
                    </small>
                </div>
                <div className="heading-actions">
                    {["owner", "office", "dispatcher"].includes(user.role) && (
                        <a className="button secondary" href="/app/schedule">
                            Schedule visits
                        </a>
                    )}
                    {manages && (
                        <Button onClick={() => setEditing(null)}>
                            <Plus size={16} />
                            Add CRM note
                        </Button>
                    )}
                </div>
            </div>
            <ErrorNotice message={errors} />
            <section className="panel planning-calendar-panel">
                <div className="planning-calendar-toolbar">
                    <div>
                        <div className="calendar-month-controls">
                            <button
                                className="icon-button"
                                aria-label="Previous month"
                                onClick={() => navigate(-1)}
                            >
                                <ChevronLeft size={19} />
                            </button>
                            <h2 aria-live="polite">{monthLabel}</h2>
                            <button
                                className="icon-button"
                                aria-label="Next month"
                                onClick={() => navigate(1)}
                            >
                                <ChevronRight size={19} />
                            </button>
                        </div>
                        <Button variant="secondary" onClick={today}>
                            Today
                        </Button>
                    </div>
                    <div className="planning-calendar-options">
                        <select
                            aria-label="Calendar event type"
                            value={filter}
                            onChange={(event) => setFilter(event.target.value)}
                        >
                            <option value="all">All events</option>
                            {Object.entries(eventLabels)
                                .filter(([type]) =>
                                    type === "job"
                                        ? readsOperations(user)
                                        : type === "visit"
                                          ? canVisits
                                          : type === "note"
                                            ? canNotes
                                            : type === "follow_up"
                                              ? canCrm
                                              : user.role !== "client",
                                )
                                .map(([type, caption]) => (
                                    <option key={type} value={type}>
                                        {caption}
                                    </option>
                                ))}
                        </select>
                        <Button
                            variant="ghost"
                            disabled={loading}
                            onClick={() => {
                                jobs.reload();
                                visits.reload();
                                leads.reload();
                                tasks.reload();
                                notes.reload();
                            }}
                            aria-label="Refresh calendar"
                        >
                            <RefreshCw size={15} />
                        </Button>
                    </div>
                </div>
                {loading && (
                    <p className="calendar-loading" role="status">
                        Updating calendar…
                    </p>
                )}
                <div className="calendar-weekdays" aria-hidden="true">
                    {["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].map(
                        (day) => (
                            <span key={day}>{day}</span>
                        ),
                    )}
                </div>
                <div className="calendar-grid" aria-label={monthLabel}>
                    {days.map((day) => {
                        const key = dateKey(day);
                        const dayEvents = events.filter(
                            (event) => event.day === key,
                        );
                        return (
                            <button
                                key={key}
                                className={`calendar-day ${day.getMonth() !== month.getMonth() ? "outside" : ""} ${key === selected ? "selected" : ""} ${key === dateKey() ? "today" : ""}`}
                                aria-pressed={key === selected}
                                aria-label={`${dayLabel(key)}, ${dayEvents.length} ${dayEvents.length === 1 ? "event" : "events"}`}
                                onClick={() => {
                                    setSelected(key);
                                    setEditing(false);
                                }}
                            >
                                <span className="calendar-day-number">
                                    {day.getDate()}
                                </span>
                                <span className="calendar-day-events">
                                    {dayEvents.slice(0, 2).map((event) => (
                                        <span
                                            className={`calendar-event ${event.type}`}
                                            key={event.id}
                                        >
                                            <span />
                                            {event.title}
                                        </span>
                                    ))}
                                    {dayEvents.length > 2 && (
                                        <span className="calendar-more">
                                            +{dayEvents.length - 2} more
                                        </span>
                                    )}
                                </span>
                                <span
                                    className="calendar-mobile-dots"
                                    aria-hidden="true"
                                >
                                    {[
                                        ...new Set(
                                            dayEvents.map(
                                                (event) => event.type,
                                            ),
                                        ),
                                    ].map((type) => (
                                        <span key={type} className={type} />
                                    ))}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </section>
            <section className="panel calendar-agenda">
                <div className="panel-heading">
                    <div>
                        <h2>{dayLabel(selected)}</h2>
                        <p>
                            {agenda.length}{" "}
                            {agenda.length === 1 ? "item" : "items"} ·{" "}
                            {user.role === "client"
                                ? "Service schedule"
                                : "Daily agenda"}
                        </p>
                    </div>
                    {manages && editing === false && (
                        <Button
                            variant="secondary"
                            onClick={() => setEditing(null)}
                        >
                            <Plus size={14} />
                            Note for this day
                        </Button>
                    )}
                </div>
                {editing !== false ? (
                    <NoteEditor
                        key={
                            editing
                                ? `${editing.id}-${editing.version}`
                                : `new-${selected}`
                        }
                        note={editing}
                        day={selected}
                        jobs={jobs.data ?? []}
                        leads={leads.data ?? []}
                        canCrm={canCrm}
                        onCancel={() => setEditing(false)}
                        onSaved={(saved) => {
                            const savedDay = dayDate(saved.note_date);
                            setSelected(saved.note_date);
                            setMonth(
                                new Date(
                                    savedDay.getFullYear(),
                                    savedDay.getMonth(),
                                    1,
                                ),
                            );
                            setFilter("all");
                            setEditing(false);
                            notes.reload();
                            onToast("CRM note saved");
                        }}
                        onReload={async () => {
                            const response = await api(
                                `/api/v1/crm-notes/${editing && editing.id}`,
                            );
                            setEditing(response.data);
                            notes.reload();
                        }}
                    />
                ) : loading && !agenda.length ? (
                    <Loading />
                ) : agenda.length ? (
                    <div className="agenda-items">
                        {agenda.map((event) => (
                            <article
                                key={event.id}
                                className={`agenda-item ${event.type}`}
                            >
                                <div className="agenda-type">
                                    <span className="agenda-dot" />
                                    <span>{eventLabels[event.type]}</span>
                                </div>
                                <div className="agenda-detail">
                                    <h3>{event.title}</h3>
                                    <small>{eventTime(event)}</small>
                                    {event.type === "note" ? (
                                        <>
                                            <p className="agenda-note">
                                                {event.record.body}
                                            </p>
                                            {(event.record.customer_id ||
                                                event.record.job_id ||
                                                event.record.lead_id) && (
                                                <small>
                                                    {event.record.job_id
                                                        ? `Job: ${jobs.data?.find((job) => job.id === event.record.job_id)?.title ?? "Linked job"}`
                                                        : event.record.lead_id
                                                          ? `Lead: ${leads.data?.find((lead) => lead.id === event.record.lead_id)?.name ?? "Linked lead"}`
                                                          : `Customer: ${event.record.customer_name ?? "Linked customer"}`}
                                                </small>
                                            )}
                                        </>
                                    ) : (
                                        <p>
                                            {event.type === "visit"
                                                ? `${label(event.record.status)}${event.record.purpose ? ` · ${event.record.purpose}` : ""}`
                                                : event.type === "job"
                                                  ? `${label(event.record.status ?? "scheduled")}${event.record.address ? ` · ${event.record.address}` : ""}`
                                                  : event.type === "task"
                                                    ? `${label(event.record.status ?? "open")} · ${label(event.record.priority ?? "normal")} priority`
                                                    : (event.record.service ??
                                                      "Contact this lead")}
                                        </p>
                                    )}
                                </div>
                                {event.type === "note" ? (
                                    manages && (
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                setEditing(event.record)
                                            }
                                            aria-label={`Edit note ${event.title}`}
                                        >
                                            Edit note
                                        </Button>
                                    )
                                ) : (
                                    <a
                                        className="button ghost"
                                        href={`/app/${event.type === "job" || event.type === "visit" ? "jobs" : event.type === "follow_up" ? "leads" : "tasks"}?q=${encodeURIComponent(event.title)}`}
                                        aria-label={`Open ${event.title}`}
                                    >
                                        Open
                                        <ChevronRight size={14} />
                                    </a>
                                )}
                            </article>
                        ))}
                    </div>
                ) : (
                    <Empty
                        title="A little room in the schedule"
                        description={
                            user.role === "client"
                                ? "No service visits are scheduled for this day."
                                : "No events for this day. Add a CRM note or create a task to plan the next step."
                        }
                    />
                )}
            </section>
        </>
    );
}

function NoteEditor({
    note,
    day,
    jobs,
    leads,
    canCrm,
    onCancel,
    onSaved,
    onReload,
}: {
    note: RecordData | null;
    day: string;
    jobs: RecordData[];
    leads: RecordData[];
    canCrm: boolean;
    onCancel: () => void;
    onSaved: (saved: RecordData) => void;
    onReload: () => Promise<void>;
}) {
    const customers = useData<RecordData[]>(
        canCrm ? "/api/v1/customers" : null,
    );
    const [error, setError] = useState("");
    const [conflict, setConflict] = useState(false);
    const [busy, setBusy] = useState(false);
    const heading = useRef<HTMLInputElement>(null);
    useEffect(() => {
        heading.current?.focus();
    }, []);
    async function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const fields = formValues(event.currentTarget);
        setBusy(true);
        setError("");
        setConflict(false);
        try {
            const response = await api<{ data: RecordData }>(
                note ? `/api/v1/crm-notes/${note.id}` : "/api/v1/crm-notes",
                note ? "PATCH" : "POST",
                {
                    title: fields.title,
                    body: fields.body,
                    note_date: fields.note_date,
                    ...relationPayload(fields.relation),
                    ...(note ? { expected_version: note.version } : {}),
                },
            );
            onSaved(response.data);
        } catch (e) {
            setError((e as Error).message);
            setConflict(e instanceof ApiError && e.status === 409);
        } finally {
            setBusy(false);
        }
    }
    return (
        <form className="calendar-note-form" onSubmit={save}>
            <h3>{note ? "Update CRM note" : "New CRM note"}</h3>
            <p className="muted">
                Internal notes are visible to authorized office and CRM staff.
            </p>
            <ErrorNotice message={error || customers.error} />
            {conflict && (
                <Button
                    type="button"
                    variant="secondary"
                    busy={busy}
                    onClick={async () => {
                        setBusy(true);
                        try {
                            await onReload();
                        } catch (e) {
                            setError((e as Error).message);
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    Reload saved version (discards these edits)
                </Button>
            )}
            <fieldset disabled={busy}>
                <div className="form-grid">
                    <Field label="Note title">
                        <input
                            ref={heading}
                            aria-label="Note title"
                            name="title"
                            maxLength={180}
                            required
                            defaultValue={note?.title ?? ""}
                        />
                    </Field>
                    <Field
                        label="Note date"
                        min="2000-01-01"
                        max="2100-12-31"
                        name="note_date"
                        type="date"
                        required
                        defaultValue={note?.note_date ?? day}
                    />
                </div>
                <TextField
                    label="CRM note"
                    name="body"
                    maxLength={10000}
                    required
                    rows={5}
                    defaultValue={note?.body ?? ""}
                    placeholder="Record a conversation, site detail, or follow-up context…"
                />
                <RelatedRecord
                    record={note}
                    jobs={jobs}
                    customers={customers.data ?? []}
                    leads={leads}
                />
                <div className="form-footer">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={onCancel}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        busy={busy}
                        disabled={customers.loading}
                    >
                        <Check size={15} />
                        Save note
                    </Button>
                </div>
            </fieldset>
        </form>
    );
}
