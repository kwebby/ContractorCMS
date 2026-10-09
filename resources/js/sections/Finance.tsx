/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useState } from "react";
import {
    ArrowDownToLine,
    CreditCard,
    FileCheck2,
    Plus,
    Receipt,
    Send,
    Trash2,
} from "lucide-react";
import {
    api,
    date,
    formValues,
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
export default function Finance({
    kind,
    onToast,
    user,
    currency = "USD",
}: {
    kind: "estimates" | "invoices";
    onToast: (t: string) => void;
    user: User;
    currency?: string;
}) {
    const state = useData<RecordData[]>(`/api/v1/${kind}`);
    const providers = useData<{ stripe: boolean; paypal: boolean }>(
        "/api/v1/payment-providers",
    );
    const [query, setQuery] = useState("");
    const [filter, setFilter] = useState("all");
    const [create, setCreate] = useState(false);
    const [editing, setEditing] = useState<RecordData | null>(null);
    const [detail, setDetail] = useState<RecordData | null>(null);
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const [payment, setPayment] = useState(false);
    const rows = (state.data ?? []).filter(
        (r) =>
            (filter === "all" || r.status === filter) &&
            JSON.stringify([r.title, r.number, r.customer_name])
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    async function action(id: string, name: string, body?: unknown) {
        setBusy(true);
        setError("");
        try {
            const r = await api(`${kind}/${id}/${name}`, "POST", body);
            if (r.data?.url) {
                window.location.assign(r.data.url);
                return;
            }
            state.reload();
            const refreshed = await api(`${kind}/${id}`);
            setDetail(refreshed.data?.id ? refreshed.data : null);
            onToast(`${kind === "invoices" ? "Invoice" : "Estimate"} updated`);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    const overdue = (state.data ?? []).filter(
        (i) =>
            i.status === "issued" &&
            i.balance_minor > 0 &&
            i.due_date &&
            new Date(i.due_date) < new Date(),
    );
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>{label(kind)}</h1>
                    <p>
                        {kind === "invoices"
                            ? "Professional invoices. Clear balances. Fewer loose ends."
                            : "Put the scope, the price, and the next step in writing."}
                    </p>
                </div>
                {user.role !== "client" && (
                    <Button onClick={() => setCreate(true)}>
                        <Plus size={16} />
                        New {kind === "invoices" ? "invoice" : "estimate"}
                    </Button>
                )}
            </div>
            {kind === "invoices" && user.role !== "client" && (
                <div className="finance-summary">
                    <div>
                        <span>Outstanding balance</span>
                        <strong>
                            {money(
                                (state.data ?? []).reduce(
                                    (s, i) => s + Number(i.balance_minor ?? 0),
                                    0,
                                ),
                                currency,
                            )}
                        </strong>
                    </div>
                    <div>
                        <span>Overdue invoices</span>
                        <strong>{overdue.length}</strong>
                    </div>
                    <div>
                        <span>Collected on these invoices</span>
                        <strong className="green-text">
                            {money(
                                (state.data ?? []).reduce(
                                    (s, i) => s + Number(i.paid_minor ?? 0),
                                    0,
                                ),
                                currency,
                            )}
                        </strong>
                    </div>
                </div>
            )}
            <ErrorNotice message={error} />
            <section className="panel">
                <Toolbar
                    query={query}
                    setQuery={setQuery}
                    placeholder={`Search ${kind}…`}
                >
                    <select
                        aria-label="Filter by document status"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                    >
                        <option value="all">All statuses</option>
                        {(kind === "invoices"
                            ? ["draft", "issued", "void", "uncollectible"]
                            : ["draft", "sent", "accepted", "declined"]
                        ).map((s) => (
                            <option key={s} value={s}>
                                {label(s)}
                            </option>
                        ))}
                    </select>
                </Toolbar>
                <ErrorNotice message={state.error} />
                {state.loading ? (
                    <Loading />
                ) : (
                    <DataTable
                        rows={rows}
                        onRow={async (record) => {
                            setDetail(record);
                            try {
                                const result = await api(
                                    `${kind}/${record.id}`,
                                );
                                if (result.data?.id) setDetail(result.data);
                            } catch (e) {
                                setError((e as Error).message);
                            }
                        }}
                        columns={[
                            {
                                key: "title",
                                label:
                                    kind === "invoices"
                                        ? "Invoice"
                                        : "Estimate",
                                render: (r) => (
                                    <div>
                                        <strong>{r.number ?? r.title}</strong>
                                        <small>
                                            {r.number
                                                ? r.title
                                                : r.customer_name}
                                        </small>
                                    </div>
                                ),
                            },
                            {
                                key: "customer_name",
                                label: "Customer",
                                render: (r) =>
                                    r.customer_name ??
                                    r.customer?.name ??
                                    "Customer",
                            },
                            {
                                key: "created_at",
                                label: "Created",
                                render: (r) => date(r.created_at),
                            },
                            {
                                key: "status",
                                label: "Status",
                                render: (r) => <Badge>{r.status}</Badge>,
                            },
                            {
                                key: "total_minor",
                                label: "Amount",
                                render: (r) => (
                                    <strong className="tabular">
                                        {money(r.total_minor, r.currency)}
                                    </strong>
                                ),
                            },
                            ...(kind === "invoices"
                                ? [
                                      {
                                          key: "balance_minor",
                                          label: "Balance",
                                          render: (r: RecordData) => (
                                              <span className="tabular">
                                                  {money(
                                                      r.balance_minor,
                                                      r.currency,
                                                  )}
                                              </span>
                                          ),
                                      },
                                  ]
                                : []),
                        ]}
                        empty={
                            <Empty
                                title={`Your first ${kind === "invoices" ? "invoice" : "estimate"} starts here`}
                                description={
                                    user.role === "client"
                                        ? "Documents shared by your contractor will appear here."
                                        : "Create a clear document with an itemized scope and pricing."
                                }
                            />
                        }
                    />
                )}
            </section>
            {(create || editing) && (
                <DocumentEditor
                    kind={kind}
                    record={editing}
                    currency={currency}
                    onClose={() => {
                        setCreate(false);
                        setEditing(null);
                    }}
                    onSaved={() => {
                        setCreate(false);
                        setEditing(null);
                        state.reload();
                        onToast(
                            `${kind === "invoices" ? "Invoice" : "Estimate"} draft created`,
                        );
                    }}
                />
            )}
            {detail && (
                <Modal
                    wide
                    title={detail.number ?? detail.title}
                    description={`${label(detail.status)} ${kind === "invoices" ? "invoice" : "estimate"} · ${date(detail.created_at)}`}
                    onClose={() => {
                        setDetail(null);
                        setError("");
                        setPayment(false);
                    }}
                >
                    <ErrorNotice message={error} />
                    <div className="document-preview">
                        <div className="document-top">
                            <span className="document-icon">
                                <Receipt size={25} />
                            </span>
                            <div>
                                <h2>{detail.title}</h2>
                                <p>
                                    {detail.customer_name ??
                                        detail.customer?.name}
                                </p>
                            </div>
                            <Badge>{detail.status}</Badge>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Qty</th>
                                    <th>Rate</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(detail.items ?? []).map(
                                    (item: any, index: number) => (
                                        <tr key={index}>
                                            <td>{item.description}</td>
                                            <td>{item.quantity}</td>
                                            <td>
                                                {money(
                                                    item.unit_price_minor,
                                                    detail.currency,
                                                )}
                                            </td>
                                            <td>
                                                {money(
                                                    item.total_minor ??
                                                        Number(item.quantity) *
                                                            item.unit_price_minor,
                                                    detail.currency,
                                                )}
                                            </td>
                                        </tr>
                                    ),
                                )}
                            </tbody>
                        </table>
                        <div className="document-total">
                            <span>Total</span>
                            <strong>
                                {money(detail.total_minor, detail.currency)}
                            </strong>
                        </div>
                        {kind === "invoices" && (
                            <>
                                <div className="document-total">
                                    <span>Paid</span>
                                    <span>
                                        {money(
                                            detail.paid_minor,
                                            detail.currency,
                                        )}
                                    </span>
                                </div>
                                <div className="document-total">
                                    <span>Balance due</span>
                                    <strong>
                                        {money(
                                            detail.balance_minor,
                                            detail.currency,
                                        )}
                                    </strong>
                                </div>
                            </>
                        )}
                        {detail.notes && (
                            <p className="muted">{detail.notes}</p>
                        )}
                    </div>
                    {kind === "invoices" && detail.payments?.length > 0 && (
                        <div className="payment-history">
                            <h3>Payments and receipts</h3>
                            {detail.payments.map((p: RecordData) => (
                                <div className="list-row" key={p.id}>
                                    <span>
                                        <strong>
                                            {money(
                                                p.amount_minor,
                                                detail.currency,
                                            )}{" "}
                                            · {label(p.method)}
                                        </strong>
                                        <small>
                                            {date(p.created_at)} · {p.reference}
                                        </small>
                                    </span>
                                    <a
                                        className="button secondary"
                                        href={`/api/v1/payments/${p.id}/receipt`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ArrowDownToLine size={14} />
                                        Receipt
                                    </a>
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="document-actions">
                        {detail.status === "draft" &&
                            user.role !== "client" && (
                                <Button
                                    variant="secondary"
                                    onClick={() => {
                                        setEditing(detail);
                                        setDetail(null);
                                    }}
                                >
                                    Edit draft
                                </Button>
                            )}
                        {detail.status === "draft" &&
                            user.role !== "client" && (
                                <Button
                                    busy={busy}
                                    onClick={() =>
                                        action(
                                            detail.id,
                                            kind === "invoices"
                                                ? "issue"
                                                : "send",
                                        )
                                    }
                                >
                                    <Send size={15} />
                                    {kind === "invoices"
                                        ? "Issue invoice"
                                        : "Send estimate"}
                                </Button>
                            )}
                        {kind === "estimates" &&
                            detail.status === "sent" &&
                            user.role === "client" && (
                                <Button
                                    busy={busy}
                                    onClick={() =>
                                        action(detail.id, "accept", {
                                            version: detail.version,
                                        })
                                    }
                                >
                                    <FileCheck2 size={16} />
                                    Accept this estimate
                                </Button>
                            )}
                        {kind === "invoices" &&
                            detail.status === "issued" &&
                            detail.balance_minor > 0 && (
                                <>
                                    {user.role !== "client" && (
                                        <Button
                                            variant="secondary"
                                            onClick={() =>
                                                setPayment((v) => !v)
                                            }
                                        >
                                            <CreditCard size={15} />
                                            Record payment
                                        </Button>
                                    )}
                                    {providers.data?.stripe && (
                                        <Button
                                            busy={busy}
                                            onClick={() =>
                                                action(detail.id, "checkout", {
                                                    provider: "stripe",
                                                })
                                            }
                                        >
                                            <CreditCard size={15} />
                                            Pay with card
                                        </Button>
                                    )}
                                    {providers.data?.paypal && (
                                        <Button
                                            busy={busy}
                                            variant="secondary"
                                            onClick={() =>
                                                action(detail.id, "checkout", {
                                                    provider: "paypal",
                                                })
                                            }
                                        >
                                            PayPal
                                        </Button>
                                    )}
                                </>
                            )}
                        {kind === "invoices" && detail.status !== "draft" && (
                            <a
                                className="button secondary"
                                href={`/api/v1/invoices/${detail.id}/pdf`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <ArrowDownToLine size={15} />
                                Download PDF
                            </a>
                        )}
                    </div>
                    {payment && (
                        <PaymentForm
                            document={detail}
                            onSave={async (body) => {
                                await action(detail.id, "payments", body);
                                setPayment(false);
                            }}
                            busy={busy}
                        />
                    )}
                </Modal>
            )}
        </>
    );
}
function PaymentForm({
    document,
    onSave,
    busy,
}: {
    document: RecordData;
    onSave: (body: any) => Promise<void>;
    busy: boolean;
}) {
    return (
        <form
            className="inset-form"
            onSubmit={(e) => {
                e.preventDefault();
                const v = formValues(e.currentTarget);
                onSave({
                    ...v,
                    amount_minor: Math.round(Number(v.amount) * 100),
                    idempotency_key: crypto.randomUUID(),
                });
            }}
        >
            <h3>Record an offline payment</h3>
            <div className="form-grid">
                <Field
                    label="Amount"
                    name="amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    defaultValue={document.balance_minor / 100}
                    required
                />
                <SelectField label="Payment method" name="method">
                    <option value="cash">Cash</option>
                    <option value="cheque">Cheque</option>
                    <option value="bank_transfer">Bank transfer</option>
                </SelectField>
                <Field label="Payment reference" name="reference" required />
            </div>
            <Button busy={busy}>Record payment</Button>
        </form>
    );
}
function DocumentEditor({
    record,
    kind,
    currency,
    onClose,
    onSaved,
}: {
    kind: "invoices" | "estimates";
    record?: RecordData | null;
    currency: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const customers = useData<RecordData[]>("/api/v1/customers");
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [items, setItems] = useState<
        { description: string; quantity: string; price: string; tax: string }[]
    >(
        record?.items?.map((i: any) => ({
            description: i.description,
            quantity: String(i.quantity),
            price: String(i.unit_price_minor / 100),
            tax: String(i.tax_basis_points / 100),
        })) ?? [{ description: "", quantity: "1", price: "", tax: "0" }],
    );
    async function save(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setBusy(true);
        setError("");
        try {
            await api(
                record ? `${kind}/${record.id}` : kind,
                record ? "PUT" : "POST",
                {
                    ...(record ? { version: record.version } : {}),
                    ...formValues(e.currentTarget),
                    items: items.map((i) => ({
                        description: i.description,
                        quantity: i.quantity,
                        unit_price_minor: Math.round(Number(i.price) * 100),
                        tax_basis_points: Math.round(Number(i.tax) * 100),
                    })),
                },
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
            wide
            title={`${record ? "Edit" : "New"} ${kind === "invoices" ? "invoice" : "estimate"}`}
            description="Save a draft, review the details, then send it to your customer."
            onClose={onClose}
        >
            <form onSubmit={save}>
                <ErrorNotice message={error} />
                <div className="form-grid">
                    <Field
                        label="Title"
                        name="title"
                        required
                        placeholder="e.g. Water heater replacement"
                        defaultValue={record?.title ?? ""}
                    />
                    <SelectField
                        label="Customer"
                        name="customer_id"
                        required
                        defaultValue={record?.customer_id ?? ""}
                    >
                        <option value="">Choose customer</option>
                        {customers.data?.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </SelectField>
                    <SelectField
                        label="Currency"
                        name="currency"
                        defaultValue={record?.currency ?? currency}
                    >
                        <option value="USD">USD — US dollar</option>
                        <option value="CAD">CAD — Canadian dollar</option>
                    </SelectField>
                    {kind === "invoices" && (
                        <Field
                            label="Due date"
                            name="due_date"
                            defaultValue={record?.due_date?.slice(0, 10) ?? ""}
                            type="date"
                            required
                        />
                    )}
                </div>
                <div className="line-items">
                    <div className="line-item headings">
                        <span>Description</span>
                        <span>Qty</span>
                        <span>Unit price</span>
                        <span>Tax %</span>
                        <span />
                    </div>
                    {items.map((item, index) => (
                        <div className="line-item" key={index}>
                            <input
                                aria-label={`Item ${index + 1} description`}
                                placeholder="Labor, materials, or service"
                                required
                                value={item.description}
                                onChange={(e) =>
                                    setItems(
                                        items.map((i, n) =>
                                            n === index
                                                ? {
                                                      ...i,
                                                      description:
                                                          e.target.value,
                                                  }
                                                : i,
                                        ),
                                    )
                                }
                            />
                            <input
                                aria-label={`Item ${index + 1} quantity`}
                                type="number"
                                min="0.01"
                                step="0.01"
                                required
                                value={item.quantity}
                                onChange={(e) =>
                                    setItems(
                                        items.map((i, n) =>
                                            n === index
                                                ? {
                                                      ...i,
                                                      quantity: e.target.value,
                                                  }
                                                : i,
                                        ),
                                    )
                                }
                            />
                            <input
                                aria-label={`Item ${index + 1} price`}
                                type="number"
                                min="0"
                                step="0.01"
                                required
                                value={item.price}
                                onChange={(e) =>
                                    setItems(
                                        items.map((i, n) =>
                                            n === index
                                                ? {
                                                      ...i,
                                                      price: e.target.value,
                                                  }
                                                : i,
                                        ),
                                    )
                                }
                            />
                            <input
                                aria-label={`Item ${index + 1} tax percent`}
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={item.tax}
                                onChange={(e) =>
                                    setItems(
                                        items.map((i, n) =>
                                            n === index
                                                ? { ...i, tax: e.target.value }
                                                : i,
                                        ),
                                    )
                                }
                            />
                            <button
                                aria-label={`Remove item ${index + 1}`}
                                className="icon-button"
                                type="button"
                                disabled={items.length === 1}
                                onClick={() =>
                                    setItems(
                                        items.filter((_, n) => n !== index),
                                    )
                                }
                            >
                                <Trash2 size={16} />
                            </button>
                        </div>
                    ))}
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() =>
                        setItems([
                            ...items,
                            {
                                description: "",
                                quantity: "1",
                                price: "",
                                tax: "0",
                            },
                        ])
                    }
                >
                    <Plus size={15} />
                    Add line item
                </Button>
                <TextField label="Notes and scope exclusions" name="notes" />
                <div className="form-footer">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button busy={busy}>Save draft</Button>
                </div>
            </form>
        </Modal>
    );
}
export function Payroll({
    onToast,
    user,
}: {
    onToast: (t: string) => void;
    user: User;
}) {
    const { data, error, loading, reload } = useData<RecordData[]>(
        "/api/v1/payroll/runs",
    );
    const team = useData<RecordData[]>("/api/v1/team");
    const [create, setCreate] = useState(false);
    const [detail, setDetail] = useState<RecordData | null>(null);
    const [query, setQuery] = useState("");
    const [busy, setBusy] = useState(false);
    const [localError, setLocalError] = useState("");
    const manages = ["owner", "admin", "hr", "payroll"].includes(user.role);
    async function advance(run: RecordData, action: string) {
        setBusy(true);
        setLocalError("");
        try {
            const r = await api(`payroll/runs/${run.id}/${action}`, "POST", {});
            setDetail(r.data);
            reload();
            onToast(
                `Pay run ${action === "publish" ? "published" : action === "approve" ? "approved" : "sent for review"}`,
            );
        } catch (e) {
            setLocalError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>{manages ? "Payroll" : "My payslips"}</h1>
                    <p>
                        {manages
                            ? "Review compensation and publish private employee payslips."
                            : "Your published pay statements, all in one place."}
                    </p>
                </div>
                {manages && (
                    <Button onClick={() => setCreate(true)}>
                        <Plus size={16} />
                        New pay run
                    </Button>
                )}
            </div>
            <ErrorNotice message={error} />
            <section className="panel">
                <Toolbar
                    query={query}
                    setQuery={setQuery}
                    placeholder="Search pay runs…"
                />
                {loading ? (
                    <Loading />
                ) : (
                    <DataTable
                        rows={(data ?? []).filter((r) =>
                            JSON.stringify([r.employee_name, r.period_start])
                                .toLowerCase()
                                .includes(query.toLowerCase()),
                        )}
                        onRow={setDetail}
                        columns={[
                            {
                                key: "employee_name",
                                label: "Employee",
                                render: (r) => (
                                    <strong>
                                        {r.employee_name ??
                                            team.data?.find(
                                                (t) =>
                                                    t.id === r.employee_user_id,
                                            )?.name ??
                                            "Employee"}
                                    </strong>
                                ),
                            },
                            {
                                key: "period_start",
                                label: "Pay period",
                                render: (r) =>
                                    `${date(r.period_start)} – ${date(r.period_end)}`,
                            },
                            {
                                key: "payment_date",
                                label: "Pay date",
                                render: (r) => date(r.payment_date),
                            },
                            {
                                key: "status",
                                label: "Status",
                                render: (r) => <Badge>{r.status}</Badge>,
                            },
                            {
                                key: "net_minor",
                                label: "Net pay",
                                render: (r) => money(r.net_minor, r.currency),
                            },
                        ]}
                        empty={
                            <Empty
                                title="No pay statements yet"
                                description={
                                    manages
                                        ? "Create a pay run using approved earnings and supplied deductions."
                                        : "Published statements will appear here."
                                }
                            />
                        }
                    />
                )}
            </section>
            {create && (
                <Modal title="Create pay run" onClose={() => setCreate(false)}>
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setLocalError("");
                            const v = formValues(e.currentTarget);
                            try {
                                await api("payroll/runs", "POST", {
                                    ...v,
                                    earnings: [
                                        {
                                            description: "Regular earnings",
                                            amount_minor: Math.round(
                                                Number(v.gross) * 100,
                                            ),
                                        },
                                    ],
                                    deductions:
                                        Number(v.deductions) > 0
                                            ? [
                                                  {
                                                      description:
                                                          "Supplied deductions",
                                                      amount_minor: Math.round(
                                                          Number(v.deductions) *
                                                              100,
                                                      ),
                                                  },
                                              ]
                                            : [],
                                    reimbursements: [],
                                });
                                setCreate(false);
                                reload();
                                onToast("Pay run draft created");
                            } catch (e) {
                                setLocalError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <ErrorNotice message={localError} />
                        <div className="form-grid">
                            <SelectField
                                label="Employee"
                                name="employee_user_id"
                                required
                            >
                                <option value="">Select employee</option>
                                {team.data
                                    ?.filter((t) => t.role !== "client")
                                    .map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name}
                                        </option>
                                    ))}
                            </SelectField>
                            <SelectField label="Currency" name="currency">
                                <option>USD</option>
                                <option>CAD</option>
                            </SelectField>
                            <Field
                                label="Period start"
                                name="period_start"
                                type="date"
                                required
                            />
                            <Field
                                label="Period end"
                                name="period_end"
                                type="date"
                                required
                            />
                            <Field
                                label="Payment date"
                                name="payment_date"
                                type="date"
                                required
                            />
                            <Field
                                label="Gross earnings"
                                name="gross"
                                type="number"
                                min="0"
                                step="0.01"
                                required
                            />
                            <Field
                                label="Supplied deductions"
                                name="deductions"
                                type="number"
                                min="0"
                                step="0.01"
                                defaultValue="0"
                                hint="Enter deductions from your payroll provider."
                            />
                        </div>
                        <div className="form-footer">
                            <Button
                                variant="secondary"
                                type="button"
                                onClick={() => setCreate(false)}
                            >
                                Cancel
                            </Button>
                            <Button busy={busy}>Create draft</Button>
                        </div>
                    </form>
                </Modal>
            )}
            {detail && (
                <Modal
                    title="Pay statement"
                    onClose={() => {
                        setDetail(null);
                        setLocalError("");
                    }}
                >
                    <ErrorNotice message={localError} />
                    <div className="detail-lines">
                        <div>
                            <span>Period</span>
                            <strong>
                                {date(detail.period_start)} –{" "}
                                {date(detail.period_end)}
                            </strong>
                        </div>
                        <div>
                            <span>Gross earnings</span>
                            <strong>
                                {money(detail.gross_minor, detail.currency)}
                            </strong>
                        </div>
                        <div>
                            <span>Deductions</span>
                            <strong>
                                {money(
                                    detail.deductions_minor,
                                    detail.currency,
                                )}
                            </strong>
                        </div>
                        <div>
                            <span>Net pay</span>
                            <strong>
                                {money(detail.net_minor, detail.currency)}
                            </strong>
                        </div>
                        <div>
                            <span>Status</span>
                            <Badge>{detail.status}</Badge>
                        </div>
                    </div>
                    <div className="form-footer">
                        {manages && detail.status === "draft" && (
                            <Button
                                busy={busy}
                                onClick={() => advance(detail, "review")}
                            >
                                Send for review
                            </Button>
                        )}
                        {manages && detail.status === "review" && (
                            <Button
                                busy={busy}
                                onClick={() => advance(detail, "approve")}
                            >
                                Approve pay run
                            </Button>
                        )}
                        {manages && detail.status === "approved" && (
                            <Button
                                busy={busy}
                                onClick={() => advance(detail, "publish")}
                            >
                                Publish payslip
                            </Button>
                        )}
                        {detail.status === "published" && (
                            <a
                                className="button secondary"
                                href={`/api/v1/payroll/runs/${detail.id}/pdf`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <ArrowDownToLine size={16} />
                                Download payslip
                            </a>
                        )}
                    </div>
                </Modal>
            )}
        </>
    );
}
