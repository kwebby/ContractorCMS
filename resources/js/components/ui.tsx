/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useEffect, useRef, useState, type ReactNode } from "react";
import {
    AlertCircle,
    ArrowRight,
    Check,
    ChevronRight,
    Inbox,
    LoaderCircle,
    Search,
    X,
} from "lucide-react";
import { api, label, type RecordData } from "../api";
export function useData<T = RecordData[]>(url: string | null) {
    const [data, setData] = useState<T | null>(null);
    const [error, setError] = useState("");
    const [loading, setLoading] = useState(true);
    const [version, setVersion] = useState(0);
    useEffect(() => {
        if (!url) {
            setLoading(false);
            setData(null);
            return;
        }
        let alive = true;
        setLoading(true);
        setError("");
        api(url)
            .then((r) => {
                if (alive) setData(r.data ?? r);
            })
            .catch((e) => {
                if (alive) setError(e.message);
            })
            .finally(() => {
                if (alive) setLoading(false);
            });
        return () => {
            alive = false;
        };
    }, [url, version]);
    return {
        data,
        setData,
        error,
        loading,
        reload: () => setVersion((v) => v + 1),
    };
}
export function Button({
    children,
    variant = "primary",
    busy = false,
    ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: "primary" | "secondary" | "ghost" | "danger";
    busy?: boolean;
}) {
    return (
        <button
            {...props}
            disabled={props.disabled || busy}
            className={`button ${variant} ${props.className ?? ""}`}
        >
            {busy ? <LoaderCircle size={15} className="spin" /> : null}
            {children}
        </button>
    );
}
export function Badge({
    children,
    tone,
}: {
    children: ReactNode;
    tone?: string;
}) {
    const name = typeof children === "string" ? children : "";
    return (
        <span
            className={`badge ${tone ?? (/paid|active|published|accepted|complete|confirmed|approved|settled/.test(name) ? "green" : /overdue|failed|blocked|urgent|void/.test(name) ? "red" : /draft|new|pending|unpaid|requested/.test(name) ? "amber" : "blue")}`}
        >
            <span />
            {typeof children === "string" ? label(children) : children}
        </span>
    );
}
export function ErrorNotice({ message }: { message: string }) {
    return message ? (
        <div className="notice error" role="alert">
            <AlertCircle size={17} />
            <span>{message}</span>
        </div>
    ) : null;
}
export function Loading() {
    return (
        <div className="loading">
            <LoaderCircle className="spin" size={22} />
            <span>Loading your workspace…</span>
        </div>
    );
}
export function Empty({
    title = "Nothing here yet",
    description = "New records will appear here.",
    action,
}: {
    title?: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="empty">
            <span className="empty-icon">
                <Inbox size={25} />
            </span>
            <h3>{title}</h3>
            <p>{description}</p>
            {action}
        </div>
    );
}
export function Modal({
    title,
    description,
    children,
    onClose,
    wide = false,
}: {
    title: string;
    description?: string;
    children: ReactNode;
    onClose: () => void;
    wide?: boolean;
}) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const el = ref.current;
        el?.showModal();
        el?.querySelector<HTMLElement>(
            "input:not([type=hidden]),textarea,select",
        )?.focus();
        return () => el?.close();
    }, []);
    return (
        <dialog
            aria-label={title}
            ref={ref}
            className={`modal ${wide ? "wide" : ""}`}
            onCancel={(e) => {
                e.preventDefault();
                onClose();
            }}
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
        >
            <div className="modal-head">
                <div>
                    <h2>{title}</h2>
                    {description && <p>{description}</p>}
                </div>
                <button
                    className="icon-button"
                    aria-label="Close dialog"
                    onClick={onClose}
                >
                    <X size={20} />
                </button>
            </div>
            <div className="modal-body">{children}</div>
        </dialog>
    );
}
export function Field({
    label: caption,
    hint,
    children,
    ...props
}: React.InputHTMLAttributes<HTMLInputElement> & {
    label: string;
    hint?: string;
    children?: ReactNode;
}) {
    return (
        <label className="field">
            <span>{caption}</span>
            {children ?? <input aria-label={caption} {...props} />}
            {hint && <small>{hint}</small>}
        </label>
    );
}
export function SelectField({
    label: caption,
    children,
    ...props
}: React.SelectHTMLAttributes<HTMLSelectElement> & { label: string }) {
    return (
        <label className="field">
            <span>{caption}</span>
            <select aria-label={caption} {...props}>
                {children}
            </select>
        </label>
    );
}
export function TextField({
    label: caption,
    ...props
}: React.TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string }) {
    return (
        <label className="field">
            <span>{caption}</span>
            <textarea rows={3} aria-label={caption} {...props} />
        </label>
    );
}
export function Toolbar({
    query,
    setQuery,
    placeholder = "Search records…",
    children,
}: {
    query: string;
    setQuery: (q: string) => void;
    placeholder?: string;
    children?: ReactNode;
}) {
    return (
        <div className="toolbar">
            <label className="table-search">
                <Search size={17} />
                <input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={placeholder}
                    aria-label={placeholder}
                />
                {query && (
                    <button
                        className="icon-button"
                        aria-label="Clear search"
                        onClick={() => setQuery("")}
                    >
                        <X size={14} />
                    </button>
                )}
            </label>
            <div className="toolbar-actions">{children}</div>
        </div>
    );
}
export function Tabs({
    tabs,
    active,
    onChange,
}: {
    tabs: string[];
    active: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="tabs" role="tablist">
            {tabs.map((t) => (
                <button
                    type="button"
                    role="tab"
                    aria-selected={active === t}
                    key={t}
                    className={active === t ? "active" : ""}
                    onClick={() => onChange(t)}
                >
                    {t}
                </button>
            ))}
        </div>
    );
}
export function DataTable({
    columns,
    rows,
    onRow,
    empty,
}: {
    columns: {
        key: string;
        label: string;
        render?: (row: RecordData) => ReactNode;
    }[];
    rows: RecordData[];
    onRow?: (row: RecordData) => void;
    empty?: ReactNode;
}) {
    return rows.length ? (
        <div className="table-scroll">
            <table>
                <thead>
                    <tr>
                        {columns.map((c) => (
                            <th key={c.key}>{c.label}</th>
                        ))}
                        {onRow && (
                            <th>
                                <span className="sr-only">Open</span>
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className={onRow ? "clickable" : ""}>
                            {columns.map((c, i) => (
                                <td key={c.key}>
                                    {onRow && i === 0 ? (
                                        <button
                                            className="table-open"
                                            onClick={() => onRow(row)}
                                        >
                                            {c.render
                                                ? c.render(row)
                                                : (row[c.key] ?? "—")}
                                        </button>
                                    ) : c.render ? (
                                        c.render(row)
                                    ) : (
                                        (row[c.key] ?? "—")
                                    )}
                                </td>
                            ))}
                            {onRow && (
                                <td>
                                    <button
                                        className="icon-button"
                                        onClick={() => onRow(row)}
                                        aria-label={`Open ${row.name ?? row.title ?? "record"}`}
                                    >
                                        <ChevronRight size={16} />
                                    </button>
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    ) : (
        (empty ?? <Empty />)
    );
}
export function Saved({
    children = "Changes saved",
}: {
    children?: ReactNode;
}) {
    return (
        <div className="notice success" role="status">
            <Check size={16} />
            {children}
        </div>
    );
}
export function InlineLink({
    href,
    children,
}: {
    href: string;
    children: ReactNode;
}) {
    return (
        <a className="text-link" href={href}>
            {children}
            <ArrowRight size={15} />
        </a>
    );
}
