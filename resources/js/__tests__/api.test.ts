/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { describe, it, expect, vi } from "vitest";
import { api, ApiError } from "../api";
describe("authenticated API requests", () => {
    it("sends session credentials and CSRF token with JSON writes", async () => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="csrf-for-test">';
        const fetch = vi.spyOn(globalThis, "fetch").mockResolvedValue(
            new Response(JSON.stringify({ data: { id: "lead-1" } }), {
                status: 201,
            }),
        );
        await expect(api("leads", "POST", { name: "Jane" })).resolves.toEqual({
            data: { id: "lead-1" },
        });
        expect(fetch).toHaveBeenCalledWith(
            "/api/v1/leads",
            expect.objectContaining({
                method: "POST",
                credentials: "same-origin",
                headers: expect.objectContaining({
                    "X-CSRF-TOKEN": "csrf-for-test",
                    "Content-Type": "application/json",
                }),
                body: '{"name":"Jane"}',
            }),
        );
    });
    it("preserves validation errors for the interface", async () => {
        vi.spyOn(globalThis, "fetch").mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: "Email is invalid.",
                    errors: { email: ["Enter a valid email."] },
                }),
                { status: 422 },
            ),
        );
        await expect(api("leads", "POST", {})).rejects.toMatchObject({
            status: 422,
            message: "Email is invalid.",
            errors: { email: ["Enter a valid email."] },
        });
    });
    it("retries a privileged action once after explicit password confirmation", async () => {
        const fetch = vi
            .spyOn(globalThis, "fetch")
            .mockResolvedValueOnce(
                new Response(
                    JSON.stringify({ message: "Confirm your password." }),
                    { status: 423 },
                ),
            )
            .mockResolvedValueOnce(
                new Response(JSON.stringify({ data: { saved: true } }), {
                    status: 200,
                }),
            );
        const handler = (event: Event) =>
            (event as CustomEvent).detail.resolve(true);
        window.addEventListener("contractorcms:reauth", handler, { once: true });
        await expect(
            api("settings/mail", "PUT", { host: "smtp.test" }),
        ).resolves.toEqual({ data: { saved: true } });
        expect(fetch).toHaveBeenCalledTimes(2);
    });
    it("does not retry a privileged action after cancellation", async () => {
        const fetch = vi
            .spyOn(globalThis, "fetch")
            .mockResolvedValue(
                new Response(
                    JSON.stringify({ message: "Confirm your password." }),
                    { status: 423 },
                ),
            );
        window.addEventListener(
            "contractorcms:reauth",
            ((event: CustomEvent) =>
                event.detail.resolve(false)) as EventListener,
            { once: true },
        );
        await expect(api("settings/mail", "PUT", {})).rejects.toBeInstanceOf(
            ApiError,
        );
        expect(fetch).toHaveBeenCalledTimes(1);
    });
    it("does not open confirmation when a late 423 arrives after navigation", async () => {
        let finish!: (response: Response) => void;
        const fetch = vi.spyOn(globalThis, "fetch").mockImplementation(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        );
        const reauth = vi.fn();
        window.addEventListener("contractorcms:reauth", reauth);
        try {
            const request = api("settings/mail", "PUT", { host: "smtp.test" });
            const rejected = expect(request).rejects.toMatchObject({
                status: 423,
                message: "Change cancelled after leaving this page.",
            });
            document.dispatchEvent(new CustomEvent("inertia:navigate"));
            finish(
                new Response(
                    JSON.stringify({ message: "Confirm your password." }),
                    { status: 423 },
                ),
            );
            await rejected;
            expect(reauth).not.toHaveBeenCalled();
            expect(fetch).toHaveBeenCalledTimes(1);
        } finally {
            window.removeEventListener("contractorcms:reauth", reauth);
        }
    });

    it("does not retry if navigation happens after confirmation resolves", async () => {
        const fetch = vi
            .spyOn(globalThis, "fetch")
            .mockResolvedValue(
                new Response(
                    JSON.stringify({ message: "Confirm your password." }),
                    { status: 423 },
                ),
            );
        const handler = (event: Event) => {
            (event as CustomEvent).detail.resolve(true);
            window.dispatchEvent(new PopStateEvent("popstate"));
        };
        window.addEventListener("contractorcms:reauth", handler, { once: true });
        await expect(api("settings/payments", "PUT", {})).rejects.toMatchObject(
            {
                status: 423,
                message: "Change cancelled after leaving this page.",
            },
        );
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});
