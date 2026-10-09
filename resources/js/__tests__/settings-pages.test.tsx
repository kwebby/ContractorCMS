/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import Settings from "../sections/Settings";
import Publishing, { Themes } from "../sections/Publishing";

function reply(data: unknown, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(data), { status }));
}

describe("dedicated settings pages", () => {
    it("links every settings category to its own refreshable page", () => {
        const fetch = vi.spyOn(globalThis, "fetch");
        render(<Settings />);
        expect(
            screen.getByRole("heading", { name: "Settings" }),
        ).toBeInTheDocument();
        for (const slug of [
            "business",
            "mail",
            "payments",
            "invoice-design",
            "ai",
            "security",
            "health",
            "email-templates",
        ]) {
            expect(
                document.querySelector(`a[href="/app/settings/${slug}"]`),
            ).toBeInTheDocument();
        }
        expect(fetch).not.toHaveBeenCalled();
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("loads a business page directly and keeps saving feedback inline", async () => {
        const writes: RequestInit[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((_url, options) => {
            if (options?.method === "PUT") writes.push(options);
            return reply({
                data: { name: "Taylor Plumbing", timezone: "America/Toronto" },
            });
        });
        const onToast = vi.fn();
        render(<Settings settingsPage="business" onToast={onToast} />);
        const name = await screen.findByLabelText("Business name");
        await userEvent.clear(name);
        await userEvent.type(name, "Taylor Service Co.");
        await userEvent.click(
            screen.getByRole("button", { name: "Save settings" }),
        );
        expect(await screen.findByRole("status")).toHaveTextContent(
            "Changes saved",
        );
        expect(JSON.parse(String(writes[0].body))).toMatchObject({
            name: "Taylor Service Co.",
            timezone: "America/Toronto",
        });
        expect(onToast).not.toHaveBeenCalled();
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
        expect(
            screen.getByRole("link", { name: "All settings" }),
        ).toHaveAttribute("href", "/app/settings");
    });

    it("keeps submitted settings read-only until the pending save finishes", async () => {
        let finish!: (response: Response) => void;
        vi.spyOn(globalThis, "fetch").mockImplementation((_url, options) =>
            options?.method === "PUT"
                ? new Promise<Response>((resolve) => {
                      finish = resolve;
                  })
                : reply({
                      data: {
                          name: "Taylor Plumbing",
                          timezone: "America/Toronto",
                      },
                  }),
        );
        render(<Settings settingsPage="business" />);
        const name = await screen.findByLabelText("Business name");
        await userEvent.click(
            screen.getByRole("button", { name: "Save settings" }),
        );
        expect(name).toBeDisabled();
        finish(new Response(JSON.stringify({ data: {} }), { status: 200 }));
        expect(await screen.findByRole("status")).toHaveTextContent(
            "Changes saved",
        );
        expect(name).toBeEnabled();
    });

    it("does not offer an empty save form when settings cannot load", async () => {
        vi.spyOn(globalThis, "fetch").mockImplementation(() =>
            reply({ message: "Settings are temporarily unavailable." }, 503),
        );
        render(<Settings settingsPage="mail" />);
        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Settings are temporarily unavailable.",
        );
        expect(
            screen.queryByRole("button", { name: "Save settings" }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole("button", { name: "Try again" }),
        ).toBeInTheDocument();
    });

    it("handles an unknown category without loading another category", () => {
        const fetch = vi.spyOn(globalThis, "fetch");
        render(<Settings settingsPage="missing" />);
        expect(screen.getByRole("alert")).toHaveTextContent(
            "This settings page does not exist.",
        );
        expect(fetch).not.toHaveBeenCalled();
    });

    it("opens website SEO as a dedicated page and saves without a popup", async () => {
        vi.spyOn(globalThis, "fetch").mockImplementation((url) =>
            reply({
                data: String(url).endsWith("diagnostics")
                    ? []
                    : { description: "Local service experts" },
            }),
        );
        const onToast = vi.fn();
        render(<Publishing publishingPage="seo" onToast={onToast} />);
        await screen.findByLabelText("Default description");
        await userEvent.click(
            screen.getByRole("button", { name: "Save SEO defaults" }),
        );
        expect(await screen.findByRole("status")).toHaveTextContent(
            "SEO defaults saved.",
        );
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
        expect(onToast).not.toHaveBeenCalled();
    });

    it("saves theme settings repeatedly using the latest version and remains on the page", async () => {
        const versions: number[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            if (String(url).endsWith("/themes"))
                return reply({ data: [{ id: "theme-1", name: "Workshop" }] });
            if (options?.method === "PUT")
                versions.push(
                    JSON.parse(String(options.body)).expected_version,
                );
            return reply({
                data: {
                    schema: {
                        properties: {
                            accent: { type: "string", title: "Accent label" },
                        },
                    },
                    values: { accent: "Blue" },
                    version: 3 + versions.length,
                },
            });
        });
        render(
            <Themes themePage="settings" themeId="theme-1" onToast={vi.fn()} />,
        );
        const field = await screen.findByLabelText("Accent label");
        await userEvent.clear(field);
        await userEvent.type(field, "Orange");
        await userEvent.click(
            screen.getByRole("button", { name: "Save theme settings" }),
        );
        expect(await screen.findByRole("status")).toHaveTextContent(
            "Theme settings saved.",
        );
        await userEvent.click(
            screen.getByRole("button", { name: "Save theme settings" }),
        );
        await waitFor(() => expect(versions).toEqual([3, 4]));
        expect(
            screen.getByRole("heading", { name: "Configure Workshop" }),
        ).toBeInTheDocument();
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("uploads only after submitting the dedicated theme page", async () => {
        const fetch = vi
            .spyOn(globalThis, "fetch")
            .mockImplementation(() => reply({ data: { id: "theme-2" } }));
        render(<Themes themePage="upload" onToast={vi.fn()} />);
        await userEvent.upload(
            screen.getByLabelText("Theme ZIP"),
            new File(["zip contents"], "workshop.zip", {
                type: "application/zip",
            }),
        );
        expect(fetch).not.toHaveBeenCalled();
        expect(
            (screen.getByLabelText("Theme ZIP") as HTMLInputElement).files
                ?.length,
        ).toBe(1);
        fireEvent.submit(screen.getByLabelText("Theme ZIP").closest("form")!);
        expect(await screen.findByRole("status")).toHaveTextContent(
            "Theme uploaded and validated.",
        );
        expect(fetch).toHaveBeenCalledWith(
            "/api/v1/themes",
            expect.objectContaining({
                method: "POST",
                body: expect.any(FormData),
            }),
        );
        expect(
            screen.getByRole("link", { name: "Open theme library" }),
        ).toHaveAttribute("href", "/app/themes");
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });
});
