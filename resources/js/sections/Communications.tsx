/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useEffect, useRef, useState } from "react";
import {
    Bell,
    CheckCheck,
    ChevronRight,
    Mail,
    MessageSquare,
    Plus,
    Send,
    Users,
} from "lucide-react";
import {
    api,
    date,
    formValues,
    initials,
    label,
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
export function Messages({
    user,
    onToast,
}: {
    user: User;
    onToast: (t: string) => void;
}) {
    const conversations = useData<RecordData[]>("/api/v1/conversations");
    const team = useData<RecordData[]>("/api/v1/conversation-participants");
    const [selected, setSelected] = useState<RecordData | null>(null);
    const [messages, setMessages] = useState<RecordData[]>([]);
    const [query, setQuery] = useState("");
    const [text, setText] = useState("");
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const [create, setCreate] = useState(false);
    const bottom = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!selected) return;
        let alive = true;
        setMessages([]);
        setError("");
        let cursor = 0;
        let loading = false;
        const load = async () => {
            if (loading || !alive) return;
            loading = true;
            try {
                let more = true;
                while (alive && more) {
                    const r = await api(
                        `conversations/${selected.id}/messages?after=${cursor}`,
                    );
                    if (!alive) return;
                    setMessages((previous) => {
                        const merged = new Map(
                            previous.map((message) => [message.id, message]),
                        );
                        for (const message of r.data)
                            merged.set(message.id, message);
                        return [...merged.values()].sort(
                            (a, b) => a.sequence - b.sequence,
                        );
                    });
                    const next = Number(r.next_cursor ?? cursor);
                    more = Boolean(r.has_more) && next > cursor;
                    cursor = next;
                }
                if (alive) setError("");
            } catch (e) {
                if (alive) setError((e as Error).message);
            } finally {
                loading = false;
            }
        };
        load();
        const timer = setInterval(load, 5000);
        return () => {
            alive = false;
            clearInterval(timer);
        };
    }, [selected?.id]);
    useEffect(() => {
        bottom.current?.scrollIntoView({ block: "nearest" });
    }, [messages.length]);
    async function send(e: React.FormEvent) {
        e.preventDefault();
        if (!text.trim() || !selected) return;
        setBusy(true);
        setError("");
        try {
            const r = await api(
                `conversations/${selected.id}/messages`,
                "POST",
                { body: text, idempotency_key: crypto.randomUUID() },
            );
            setMessages((previous) =>
                previous.some((m) => m.id === r.data.id)
                    ? previous
                    : [...previous, r.data],
            );
            setText("");
            conversations.reload();
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
                    <h1>Messages</h1>
                    <p>
                        Keep your customer conversations and team decisions
                        connected.
                    </p>
                </div>
                <Button onClick={() => setCreate(true)}>
                    <Plus size={16} />
                    New conversation
                </Button>
            </div>
            <ErrorNotice message={conversations.error} />
            <div className="messaging">
                <aside className="conversation-list">
                    <Toolbar
                        query={query}
                        setQuery={setQuery}
                        placeholder="Find a conversation…"
                    />
                    {conversations.loading ? (
                        <Loading />
                    ) : (
                        (conversations.data ?? [])
                            .filter((c) =>
                                String(c.title ?? c.subject ?? "")
                                    .toLowerCase()
                                    .includes(query.toLowerCase()),
                            )
                            .map((c) => (
                                <button
                                    key={c.id}
                                    className={`conversation ${selected?.id === c.id ? "selected" : ""}`}
                                    onClick={() => setSelected(c)}
                                >
                                    <span className="avatar">
                                        {initials(
                                            c.title ?? c.subject ?? "Chat",
                                        )}
                                    </span>
                                    <span>
                                        <strong>
                                            {c.title ??
                                                c.subject ??
                                                "Conversation"}
                                        </strong>
                                        <small>
                                            {c.last_message?.body ??
                                                c.last_message ??
                                                label(c.type ?? "client")}
                                        </small>
                                    </span>
                                    <span className="conversation-date">
                                        {date(c.updated_at, {
                                            month: "short",
                                            day: "numeric",
                                        })}
                                    </span>
                                </button>
                            ))
                    )}
                    {!conversations.loading && !conversations.data?.length && (
                        <Empty
                            title="No conversations yet"
                            description="Start a conversation with your team or a customer."
                        />
                    )}
                </aside>
                <section className="chat-panel">
                    {selected ? (
                        <>
                            <div className="chat-header">
                                <span className="avatar">
                                    {initials(
                                        selected.title ??
                                            selected.subject ??
                                            "Chat",
                                    )}
                                </span>
                                <div>
                                    <h2>
                                        {selected.title ?? selected.subject}
                                    </h2>
                                    <small>
                                        {label(selected.type ?? "client")}{" "}
                                        conversation
                                    </small>
                                </div>
                                <Badge
                                    tone={
                                        selected.type === "internal"
                                            ? "amber"
                                            : "blue"
                                    }
                                >
                                    {selected.type === "internal"
                                        ? "Team only"
                                        : "Participants only"}
                                </Badge>
                            </div>
                            <div className="chat-messages" aria-live="polite">
                                {messages.map((m) => (
                                    <div
                                        className={`message ${m.sender_id === user.id || m.user_id === user.id ? "own" : ""}`}
                                        key={m.id}
                                    >
                                        <small>
                                            {m.sender_name ??
                                                m.sender?.name ??
                                                "Participant"}{" "}
                                            ·{" "}
                                            {date(m.created_at, {
                                                hour: "numeric",
                                                minute: "2-digit",
                                            })}
                                        </small>
                                        <div>{m.body ?? m.content}</div>
                                    </div>
                                ))}
                                {!messages.length && (
                                    <p className="chat-start">
                                        This is the start of your conversation.
                                    </p>
                                )}
                                <div ref={bottom} />
                            </div>
                            <ErrorNotice message={error} />
                            <form className="message-composer" onSubmit={send}>
                                <textarea
                                    aria-label="Your message"
                                    placeholder="Write a message…"
                                    value={text}
                                    onChange={(e) => setText(e.target.value)}
                                    required
                                    rows={2}
                                    onKeyDown={(e) => {
                                        if (e.key === "Enter" && !e.shiftKey) {
                                            e.preventDefault();
                                            send(e);
                                        }
                                    }}
                                />
                                <Button
                                    busy={busy}
                                    disabled={!text.trim()}
                                    aria-label="Send message"
                                >
                                    <Send size={18} />
                                </Button>
                            </form>
                        </>
                    ) : (
                        <Empty
                            title="Good work starts with a conversation"
                            description="Choose a conversation to see the full history and send a message."
                        />
                    )}
                </section>
            </div>
            {create && (
                <Modal
                    title="New conversation"
                    onClose={() => setCreate(false)}
                >
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError("");
                            const values = formValues(e.currentTarget);
                            try {
                                const r = await api("conversations", "POST", {
                                    ...values,
                                    customer_id:
                                        team.data?.find(
                                            (p) => p.id === values.participant,
                                        )?.customer_id ?? undefined,
                                    participants: values.participant
                                        ? [values.participant]
                                        : [],
                                });
                                setSelected(r.data);
                                setCreate(false);
                                conversations.reload();
                                onToast("Conversation started");
                            } catch (e) {
                                setError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <ErrorNotice message={error} />
                        <Field
                            label="Subject"
                            name="title"
                            required
                            placeholder="e.g. Kitchen renovation updates"
                        />
                        <SelectField label="Conversation type" name="type">
                            {user.role !== "client" && (
                                <option value="internal">Internal team</option>
                            )}
                            <option value="client">Client conversation</option>
                        </SelectField>
                        <SelectField label="Participant" name="participant">
                            <option value="">Select a participant</option>
                            {team.data
                                ?.filter((p) => p.id !== user.id)
                                .map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} — {label(p.role)}
                                    </option>
                                ))}
                        </SelectField>
                        <div className="form-footer">
                            <Button
                                variant="secondary"
                                type="button"
                                onClick={() => setCreate(false)}
                            >
                                Cancel
                            </Button>
                            <Button busy={busy}>Start conversation</Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}
export function Notifications({ onToast }: { onToast: (t: string) => void }) {
    const state = useData<RecordData[]>("/api/v1/notifications");
    const [query, setQuery] = useState("");
    const [filter, setFilter] = useState("all");
    const [sort, setSort] = useState("newest");
    const [error, setError] = useState("");
    async function mark(record: RecordData) {
        try {
            await api(`notifications/${record.id}`, "PATCH", {
                read: true,
                read_at: new Date().toISOString(),
            });
            state.reload();
        } catch (e) {
            setError((e as Error).message);
        }
    }
    const rows = (state.data ?? [])
        .filter(
            (n) =>
                (filter === "all" ||
                    (filter === "unread" && !n.read_at) ||
                    (filter === "action" && n.action_required)) &&
                JSON.stringify([n.title, n.body, n.category])
                    .toLowerCase()
                    .includes(query.toLowerCase()),
        )
        .sort((a, b) =>
            sort === "priority"
                ? ({ urgent: 3, high: 2, normal: 1, low: 0 }[
                      String(b.priority) as "urgent" | "high" | "normal" | "low"
                  ] ?? 0) -
                  ({ urgent: 3, high: 2, normal: 1, low: 0 }[
                      String(a.priority) as "urgent" | "high" | "normal" | "low"
                  ] ?? 0)
                : String(b.created_at).localeCompare(a.created_at),
        );
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>Notifications</h1>
                    <p>Know what changed and what needs your attention.</p>
                </div>
                <Button
                    variant="secondary"
                    onClick={async () => {
                        try {
                            await Promise.all(
                                (state.data ?? [])
                                    .filter((n) => !n.read_at)
                                    .map((n) =>
                                        api(`notifications/${n.id}`, "PATCH", {
                                            read: true,
                                            read_at: new Date().toISOString(),
                                        }),
                                    ),
                            );
                            state.reload();
                            onToast("All notifications marked as read");
                        } catch (e) {
                            setError((e as Error).message);
                        }
                    }}
                >
                    <CheckCheck size={16} />
                    Mark all read
                </Button>
            </div>
            <ErrorNotice message={error || state.error} />
            <section className="panel">
                <Toolbar
                    query={query}
                    setQuery={setQuery}
                    placeholder="Search notifications…"
                >
                    <select
                        aria-label="Filter notifications"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                    >
                        <option value="all">All notifications</option>
                        <option value="unread">Unread</option>
                        <option value="action">Action required</option>
                    </select>
                    <select
                        aria-label="Sort notifications"
                        value={sort}
                        onChange={(e) => setSort(e.target.value)}
                    >
                        <option value="newest">Newest first</option>
                        <option value="priority">Priority first</option>
                    </select>
                </Toolbar>
                {state.loading ? (
                    <Loading />
                ) : rows.length ? (
                    rows.map((n) => (
                        <div
                            className={`notification-row ${!n.read_at ? "unread" : ""}`}
                            key={n.id}
                        >
                            <span className="notification-icon">
                                <Bell size={18} />
                            </span>
                            <div>
                                <strong>
                                    {n.title ?? label(n.type ?? n.category)}
                                </strong>
                                <p>{n.body ?? n.message}</p>
                                <small>
                                    {date(n.created_at, {
                                        month: "short",
                                        day: "numeric",
                                        hour: "numeric",
                                        minute: "2-digit",
                                    })}{" "}
                                    · {label(n.category ?? "General")}
                                </small>
                            </div>
                            {n.action_url && (
                                <a
                                    className="button secondary"
                                    href={n.action_url}
                                >
                                    View
                                    <ChevronRight size={14} />
                                </a>
                            )}
                            {!n.read_at && (
                                <button
                                    className="icon-button"
                                    aria-label={`Mark ${n.title} read`}
                                    onClick={() => mark(n)}
                                >
                                    <CheckCheck size={18} />
                                </button>
                            )}
                        </div>
                    ))
                ) : (
                    <Empty
                        title="You're all caught up"
                        description="New assignments, messages, and updates will appear here."
                    />
                )}
            </section>
        </>
    );
}
export function Team({ onToast }: { onToast: (t: string) => void }) {
    const state = useData<RecordData[]>("/api/v1/team");
    const [query, setQuery] = useState("");
    const [invite, setInvite] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>Your team</h1>
                    <p>
                        The right people, with the right access to get work
                        done.
                    </p>
                </div>
                <Button onClick={() => setInvite(true)}>
                    <Plus size={16} />
                    Invite teammate
                </Button>
            </div>
            <section className="panel">
                <Toolbar
                    query={query}
                    setQuery={setQuery}
                    placeholder="Find a teammate…"
                >
                    <span className="count">
                        {state.data?.filter((t) => t.role !== "client")
                            .length ?? 0}{" "}
                        team members
                    </span>
                </Toolbar>
                <ErrorNotice message={state.error} />
                {state.loading ? (
                    <Loading />
                ) : (
                    <DataTable
                        rows={(state.data ?? []).filter(
                            (t) =>
                                t.role !== "client" &&
                                `${t.name} ${t.email}`
                                    .toLowerCase()
                                    .includes(query.toLowerCase()),
                        )}
                        columns={[
                            {
                                key: "name",
                                label: "Team member",
                                render: (t) => (
                                    <div className="cell-person">
                                        <span className="avatar">
                                            {initials(t.name)}
                                        </span>
                                        <span>
                                            <strong>{t.name}</strong>
                                            <small>{t.email}</small>
                                        </span>
                                    </div>
                                ),
                            },
                            {
                                key: "role",
                                label: "Role",
                                render: (t) => label(t.role),
                            },
                            {
                                key: "status",
                                label: "Status",
                                render: (t) => (
                                    <Badge>
                                        {t.active === false
                                            ? "inactive"
                                            : "active"}
                                    </Badge>
                                ),
                            },
                            {
                                key: "created_at",
                                label: "Joined",
                                render: (t) => date(t.created_at),
                            },
                        ]}
                    />
                )}
            </section>
            {invite && (
                <Modal
                    title="Invite a teammate"
                    description="They will receive an email to set up their account."
                    onClose={() => setInvite(false)}
                >
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError("");
                            try {
                                await api(
                                    "invitations",
                                    "POST",
                                    formValues(e.currentTarget),
                                );
                                setInvite(false);
                                state.reload();
                                onToast("Invitation queued for delivery");
                            } catch (e) {
                                setError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <ErrorNotice message={error} />
                        <Field label="Full name" name="name" required />
                        <Field
                            label="Work email"
                            name="email"
                            type="email"
                            required
                        />
                        <SelectField
                            label="Role"
                            name="role"
                            defaultValue="technician"
                        >
                            {[
                                "owner",
                                "office",
                                "dispatcher",
                                "estimator",
                                "technician",
                                "finance",
                                "payroll",
                                "marketing",
                            ].map((role) => (
                                <option value={role} key={role}>
                                    {label(role)}
                                </option>
                            ))}
                        </SelectField>
                        <div className="form-footer">
                            <Button
                                variant="secondary"
                                type="button"
                                onClick={() => setInvite(false)}
                            >
                                Cancel
                            </Button>
                            <Button busy={busy}>
                                <Mail size={15} />
                                Send invitation
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}
