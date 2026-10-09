/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

export type RecordData = { id: string; [key: string]: any };
export type User = {
    id: string;
    name: string;
    email: string;
    role: string;
    customer_id?: string;
    permissions?: string[];
};
export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}
export async function api<T = any>(
    path: string,
    method = "GET",
    body?: unknown,
    retried = false,
): Promise<T> {
    const originatingUrl = window.location.href;
    let navigated = false;
    let cancelConfirmation: (() => void) | undefined;
    const leavePage = () => {
        navigated = true;
        cancelConfirmation?.();
    };
    const leftPage = () => navigated || window.location.href !== originatingUrl;
    document.addEventListener("inertia:before", leavePage);
    document.addEventListener("inertia:navigate", leavePage);
    window.addEventListener("popstate", leavePage);
    window.addEventListener("pagehide", leavePage);
    try {
        const cookie = document.cookie
            .split("; ")
            .find((value) => value.startsWith("XSRF-TOKEN="));
        const headers: Record<string, string> = {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN":
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? "",
        };
        if (cookie) {
            delete headers["X-CSRF-TOKEN"];
            headers["X-XSRF-TOKEN"] = decodeURIComponent(
                cookie.slice("XSRF-TOKEN=".length),
            );
        }
        if (body && !(body instanceof FormData))
            headers["Content-Type"] = "application/json";
        const response = await fetch(
            path.startsWith("/") ? path : `/api/v1/${path}`,
            {
                method,
                headers,
                credentials: "same-origin",
                body:
                    body instanceof FormData
                        ? body
                        : body === undefined
                          ? undefined
                          : JSON.stringify(body),
            },
        );
        const value = await response.json().catch(() => ({
            message:
                response.status === 419
                    ? "Your session expired. Refresh the page and sign in again."
                    : "The server could not complete this request.",
        }));
        if (response.status === 423 && !retried) {
            if (leftPage())
                throw new ApiError(
                    "Change cancelled after leaving this page.",
                    423,
                );
            const confirmed = await new Promise<boolean>((resolve) => {
                cancelConfirmation = () => resolve(false);
                window.dispatchEvent(
                    new CustomEvent("contractorcms:reauth", {
                        detail: { resolve },
                    }),
                );
            });
            cancelConfirmation = undefined;
            if (leftPage())
                throw new ApiError(
                    "Change cancelled after leaving this page.",
                    423,
                );
            if (confirmed) return await api<T>(path, method, body, true);
        }
        if (!response.ok)
            throw new ApiError(
                value.message ?? "Request failed.",
                response.status,
                value.errors,
            );
        return value;
    } finally {
        document.removeEventListener("inertia:before", leavePage);
        document.removeEventListener("inertia:navigate", leavePage);
        window.removeEventListener("popstate", leavePage);
        window.removeEventListener("pagehide", leavePage);
    }
}

export function money(value: unknown, currency = "USD") {
    return new Intl.NumberFormat("en-US", {
        style: "currency",
        currency,
        maximumFractionDigits: 2,
    }).format(Number(value ?? 0) / 100);
}
export function date(
    value: unknown,
    options: Intl.DateTimeFormatOptions = {
        month: "short",
        day: "numeric",
        year: "numeric",
    },
) {
    if (!value) return "—";
    const parsed = new Date(String(value));
    return Number.isNaN(parsed.getTime())
        ? "—"
        : parsed.toLocaleDateString("en-US", options);
}
export function label(value: unknown) {
    return String(value ?? "")
        .replaceAll("_", " ")
        .replaceAll(".", " ")
        .replace(/^./, (c) => c.toUpperCase());
}
export function initials(value: string) {
    return value
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0])
        .join("")
        .toUpperCase();
}
export function formValues(form: HTMLFormElement) {
    return Object.fromEntries(new FormData(form).entries());
}

export function dateKey(value: unknown = new Date()): string {
    const d = value instanceof Date ? value : new Date(String(value));
    if (Number.isNaN(d.getTime())) return "";
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}
export function localDateTime(value: unknown): string {
    if (!value) return "";
    const d = new Date(String(value));
    return `${dateKey(d)}T${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}
