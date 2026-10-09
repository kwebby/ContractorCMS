/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useState } from "react";
import { CalendarDays, Check, Home, Plus, Send } from "lucide-react";
import {
    api,
    date,
    formValues,
    label,
    localDateTime,
    money,
    type RecordData,
} from "../api";
import {
    Badge,
    Button,
    Empty,
    ErrorNotice,
    Field,
    Loading,
    Modal,
    SelectField,
    Tabs,
    TextField,
    useData,
} from "../components/ui";
export function JobOperations({
    job,
    role,
}: {
    job: RecordData;
    role: string;
}) {
    const [tab, setTab] = useState("Appointments");
    const visits = useData<RecordData[]>(
        `/api/v1/visits?job_id=${encodeURIComponent(job.id)}`,
    );
    const changes = useData<RecordData[]>(
        `/api/v1/change-orders?job_id=${encodeURIComponent(job.id)}`,
    );
    const team = useData<RecordData[]>("/api/v1/team");
    const [create, setCreate] = useState<"visit" | "change" | null>(null);
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState<RecordData | null>(null);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const manages = ["staff", "owner", "office", "dispatcher"].includes(role);
    const client = role === "client";
    const scopedVisits = (visits.data ?? []).filter((v) => v.job_id === job.id);
    const scopedChanges = (changes.data ?? []).filter(
        (c) => c.job_id === job.id,
    );
    async function act(resource: string, record: RecordData, action: string) {
        setBusy(true);
        setError("");
        try {
            await api(`${resource}/${record.id}/${action}`, "POST", {
                version: record.version,
            });
            visits.reload();
            changes.reload();
            setSuccess(
                resource === "visits"
                    ? `Appointment ${action === "confirm" ? "confirmed" : action === "complete" ? "completed" : "cancelled"}.`
                    : action === "confirm"
                      ? "Appointment confirmed."
                      : action === "accept"
                        ? "Change order accepted."
                        : action === "send"
                          ? "Change order sent for approval."
                          : `Change order ${action === "reject" ? "rejected" : "cancelled"}.`,
            );
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <section className="job-operations">
            <Tabs
                tabs={["Appointments", "Change orders"]}
                active={tab}
                onChange={setTab}
            />
            <ErrorNotice message={error} />
            {success && (
                <div className="notice success" role="status">
                    <Check size={16} />
                    {success}
                </div>
            )}
            {tab === "Appointments" ? (
                <>
                    <div className="subsection-heading">
                        <h3>Visits for this job</h3>
                        {(client || manages) && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setCreate("visit")}
                            >
                                <Plus size={14} />
                                {client
                                    ? "Request a visit"
                                    : "Schedule a visit"}
                            </Button>
                        )}
                    </div>
                    <ErrorNotice message={visits.error} />
                    {visits.loading ? (
                        <Loading />
                    ) : scopedVisits.length ? (
                        scopedVisits.map((v) => (
                            <div className="operation-row" key={v.id}>
                                <CalendarDays size={20} />
                                <div>
                                    <strong>
                                        {date(v.start_at, {
                                            month: "short",
                                            day: "numeric",
                                            hour: "numeric",
                                            minute: "2-digit",
                                        })}
                                    </strong>
                                    <small>
                                        {v.purpose ?? "Service visit"} ·{" "}
                                        {v.duration_minutes} minutes
                                    </small>
                                    <Badge>{v.status}</Badge>
                                </div>
                                {manages && v.status === "confirmed" && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        busy={busy}
                                        onClick={() =>
                                            act("visits", v, "complete")
                                        }
                                    >
                                        Complete visit
                                    </Button>
                                )}
                                {((manages &&
                                    ["requested", "confirmed"].includes(
                                        v.status,
                                    )) ||
                                    (client && v.status === "requested")) && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        busy={busy}
                                        onClick={() =>
                                            act("visits", v, "cancel")
                                        }
                                    >
                                        Cancel visit
                                    </Button>
                                )}
                                {manages && v.status === "requested" && (
                                    <Button
                                        type="button"
                                        busy={busy}
                                        onClick={() => setConfirming(v)}
                                    >
                                        Confirm visit
                                    </Button>
                                )}
                            </div>
                        ))
                    ) : (
                        <Empty
                            title="No appointments yet"
                            description={
                                client
                                    ? "Request a time. Your team will confirm the appointment."
                                    : "Add a visit to coordinate the customer and your crew."
                            }
                        />
                    )}
                </>
            ) : (
                <>
                    <div className="subsection-heading">
                        <h3>Scope and price changes</h3>
                        {manages && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setCreate("change")}
                            >
                                <Plus size={14} />
                                New change order
                            </Button>
                        )}
                    </div>
                    <ErrorNotice message={changes.error} />
                    {changes.loading ? (
                        <Loading />
                    ) : scopedChanges.length ? (
                        scopedChanges.map((c) => (
                            <div className="change-order" key={c.id}>
                                <div className="change-order-heading">
                                    <h3>{c.title}</h3>
                                    <strong>
                                        {money(c.amount_minor, c.currency)}
                                    </strong>
                                </div>
                                <p>{c.description}</p>
                                <div className="change-order-actions">
                                    <Badge>{c.status}</Badge>
                                    {manages && c.status === "draft" && (
                                        <Button
                                            type="button"
                                            busy={busy}
                                            onClick={() =>
                                                act("change-orders", c, "send")
                                            }
                                        >
                                            <Send size={13} />
                                            Send for approval
                                        </Button>
                                    )}
                                    {client && c.status === "sent" && (
                                        <>
                                            <Button
                                                type="button"
                                                busy={busy}
                                                onClick={() =>
                                                    act(
                                                        "change-orders",
                                                        c,
                                                        "accept",
                                                    )
                                                }
                                            >
                                                Accept this change
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                busy={busy}
                                                onClick={() =>
                                                    act(
                                                        "change-orders",
                                                        c,
                                                        "reject",
                                                    )
                                                }
                                            >
                                                Decline
                                            </Button>
                                        </>
                                    )}
                                    {manages &&
                                        ["draft", "sent"].includes(
                                            c.status,
                                        ) && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                busy={busy}
                                                onClick={() =>
                                                    act(
                                                        "change-orders",
                                                        c,
                                                        "cancel",
                                                    )
                                                }
                                            >
                                                Cancel change
                                            </Button>
                                        )}
                                </div>
                            </div>
                        ))
                    ) : (
                        <Empty
                            title="The agreed scope is clear"
                            description="Changes to the scope and price appear here for customer approval."
                        />
                    )}
                </>
            )}
            {confirming && (
                <Modal
                    title="Confirm appointment"
                    onClose={() => setConfirming(null)}
                >
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError("");
                            const values = formValues(e.currentTarget);
                            const assigned = new FormData(
                                e.currentTarget,
                            ).getAll("assigned_to");
                            try {
                                await api(
                                    `visits/${confirming.id}/confirm`,
                                    "POST",
                                    {
                                        version: confirming.version,
                                        start_at: new Date(
                                            String(values.start_at),
                                        ).toISOString(),
                                        duration_minutes: Number(
                                            values.duration_minutes,
                                        ),
                                        assigned_to: assigned,
                                    },
                                );
                                setConfirming(null);
                                visits.reload();
                                setSuccess("Appointment confirmed.");
                            } catch (e) {
                                setError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <ErrorNotice message={error} />
                        <div className="form-grid">
                            <Field
                                label="Confirmed date and time"
                                name="start_at"
                                type="datetime-local"
                                defaultValue={localDateTime(
                                    confirming.start_at,
                                )}
                                required
                            />
                            <Field
                                label="Duration in minutes"
                                name="duration_minutes"
                                type="number"
                                defaultValue={confirming.duration_minutes}
                                min="15"
                                max="1440"
                                required
                            />
                        </div>
                        <fieldset className="crew-field">
                            <legend>Assigned crew</legend>
                            {team.data?.map((t) => (
                                <label className="check-field" key={t.id}>
                                    <input
                                        type="checkbox"
                                        name="assigned_to"
                                        value={t.id}
                                        defaultChecked={
                                            confirming.assigned_to?.includes(
                                                t.id,
                                            ) ?? false
                                        }
                                    />
                                    {t.name}
                                </label>
                            ))}
                        </fieldset>
                        <p className="help-text">
                            Availability is checked before the appointment is
                            confirmed.
                        </p>
                        <div className="form-footer">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setConfirming(null)}
                            >
                                Cancel
                            </Button>
                            <Button busy={busy}>Confirm appointment</Button>
                        </div>
                    </form>
                </Modal>
            )}
            {create && (
                <Modal
                    title={
                        create === "visit"
                            ? client
                                ? "Request an appointment"
                                : "Schedule a visit"
                            : "New change order"
                    }
                    onClose={() => setCreate(null)}
                >
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError("");
                            const values: any = formValues(e.currentTarget);
                            const payload =
                                create === "visit"
                                    ? {
                                          job_id: job.id,
                                          start_at: new Date(
                                              values.start_at,
                                          ).toISOString(),
                                          duration_minutes: Number(
                                              values.duration_minutes,
                                          ),
                                          assigned_to: new FormData(
                                              e.currentTarget,
                                          ).getAll("assigned_to"),
                                          purpose: values.purpose,
                                      }
                                    : {
                                          job_id: job.id,
                                          title: values.title,
                                          description: values.description,
                                          amount_minor: Math.round(
                                              Number(values.amount) * 100,
                                          ),
                                          currency: values.currency,
                                      };
                            try {
                                await api(
                                    create === "visit"
                                        ? "visits"
                                        : "change-orders",
                                    "POST",
                                    payload,
                                );
                                visits.reload();
                                changes.reload();
                                setSuccess(
                                    create === "visit"
                                        ? "Visit saved. Check its confirmation status below."
                                        : "Change order draft saved.",
                                );
                                setCreate(null);
                            } catch (e) {
                                setError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <ErrorNotice message={error} />
                        {create === "visit" ? (
                            <>
                                <div className="form-grid">
                                    <Field
                                        label="Preferred date and time"
                                        type="datetime-local"
                                        name="start_at"
                                        required
                                    />
                                    <Field
                                        label="Duration in minutes"
                                        type="number"
                                        name="duration_minutes"
                                        defaultValue="60"
                                        min="15"
                                        max="1440"
                                        step="15"
                                        required
                                    />
                                </div>
                                <Field
                                    label="Purpose of visit"
                                    name="purpose"
                                    required
                                    defaultValue={job.title}
                                />
                                {manages && (
                                    <fieldset className="crew-field">
                                        <legend>Assigned crew</legend>
                                        {team.data?.map((t) => (
                                            <label
                                                className="check-field"
                                                key={t.id}
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="assigned_to"
                                                    value={t.id}
                                                    defaultChecked={
                                                        job.assigned_to?.includes(
                                                            t.id,
                                                        ) ?? false
                                                    }
                                                />
                                                {t.name}
                                            </label>
                                        ))}
                                    </fieldset>
                                )}
                            </>
                        ) : (
                            <>
                                <Field
                                    label="Change title"
                                    name="title"
                                    required
                                />
                                <TextField
                                    label="Added or changed scope"
                                    name="description"
                                    required
                                />
                                <div className="form-grid">
                                    <Field
                                        label="Price adjustment"
                                        name="amount"
                                        type="number"
                                        step="0.01"
                                        required
                                        hint="Enter the price difference for this change."
                                    />
                                    <SelectField
                                        label="Currency"
                                        name="currency"
                                        defaultValue={job.currency ?? "USD"}
                                    >
                                        <option>USD</option>
                                        <option>CAD</option>
                                    </SelectField>
                                </div>
                            </>
                        )}
                        <div className="form-footer">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setCreate(null)}
                            >
                                Cancel
                            </Button>
                            <Button busy={busy}>
                                {create === "visit"
                                    ? client
                                        ? "Send appointment request"
                                        : "Save visit"
                                    : "Save change draft"}
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}
        </section>
    );
}
export function CustomerProperties({ customerId }: { customerId: string }) {
    const properties = useData<RecordData[]>(
        `/api/v1/properties?customer_id=${encodeURIComponent(customerId)}`,
    );
    const [editing, setEditing] = useState<RecordData | null | false>(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    return (
        <section className="customer-properties">
            <div className="subsection-heading">
                <h3>Service properties</h3>
                <Button
                    type="button"
                    variant="secondary"
                    onClick={() => setEditing(null)}
                >
                    <Plus size={14} />
                    Add property
                </Button>
            </div>
            <ErrorNotice message={properties.error} />
            {properties.loading ? (
                <Loading />
            ) : (
                (properties.data ?? [])
                    .filter((p) => p.customer_id === customerId)
                    .map((property) => (
                        <button
                            type="button"
                            className="operation-row property-row"
                            key={property.id}
                            onClick={() => setEditing(property)}
                        >
                            <Home size={19} />
                            <span>
                                <strong>{property.name}</strong>
                                <small>
                                    {[property.address, property.city]
                                        .filter(Boolean)
                                        .join(", ")}
                                </small>
                            </span>
                        </button>
                    ))
            )}
            {!properties.loading &&
                !(properties.data ?? []).some(
                    (p) => p.customer_id === customerId,
                ) && (
                    <p className="help-text">
                        Save service addresses and access notes for this
                        customer.
                    </p>
                )}
            {editing !== false && (
                <Modal
                    title={editing ? "Edit property" : "Add service property"}
                    onClose={() => setEditing(false)}
                >
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError("");
                            try {
                                await api(
                                    `properties${editing ? `/${editing.id}` : ""}`,
                                    editing ? "PUT" : "POST",
                                    {
                                        ...formValues(e.currentTarget),
                                        customer_id: customerId,
                                        ...(editing
                                            ? { version: editing.version }
                                            : {}),
                                    },
                                );
                                setEditing(false);
                                properties.reload();
                            } catch (e) {
                                setError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <ErrorNotice message={error} />
                        <Field
                            label="Property name"
                            name="name"
                            defaultValue={editing?.name ?? ""}
                            placeholder="e.g. Main home"
                            required
                        />
                        <Field
                            label="Street address"
                            name="address"
                            defaultValue={editing?.address ?? ""}
                            required
                        />
                        <div className="form-grid">
                            <Field
                                label="City"
                                name="city"
                                defaultValue={editing?.city ?? ""}
                                required
                            />
                            <Field
                                label="State / province"
                                name="region"
                                defaultValue={editing?.region ?? ""}
                            />
                            <Field
                                label="ZIP / postal code"
                                name="postal_code"
                                defaultValue={editing?.postal_code ?? ""}
                            />
                            <SelectField
                                label="Country"
                                name="country"
                                defaultValue={editing?.country ?? "US"}
                            >
                                <option value="US">United States</option>
                                <option value="CA">Canada</option>
                            </SelectField>
                        </div>
                        <TextField
                            label="Access notes"
                            name="access_notes"
                            defaultValue={editing?.access_notes ?? ""}
                        />
                        <div className="form-footer">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setEditing(false)}
                            >
                                Cancel
                            </Button>
                            <Button busy={busy}>Save property</Button>
                        </div>
                    </form>
                </Modal>
            )}
        </section>
    );
}
