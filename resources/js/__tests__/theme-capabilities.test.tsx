/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { Themes } from "../sections/Publishing";

const legacy = {
    id: "legacy",
    name: "Classic",
    theme_version: "1.0.0",
    manifest: {},
};
function reply(data: unknown, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(data), { status }));
}

describe("theme compatibility and built-in theme", () => {
    it("activates the built-in theme and reports success inline without a modal", async () => {
        let active: string | null = "legacy";
        const fetch = vi
            .spyOn(globalThis, "fetch")
            .mockImplementation((url, options) => {
                if (
                    String(url).endsWith("themes/default") &&
                    options?.method === "POST"
                ) {
                    active = null;
                    return reply({
                        data: { active_id: null, previous_id: "legacy" },
                    });
                }
                return reply({
                    data: [legacy],
                    active_theme: active,
                    can_rollback: true,
                });
            });
        const onToast = vi.fn();
        render(<Themes onToast={onToast} />);
        await userEvent.click(
            await screen.findByRole("button", { name: "Use built-in theme" }),
        );
        expect(await screen.findByRole("status")).toHaveTextContent(
            "Built-in theme activated.",
        );
        expect(fetch).toHaveBeenCalledWith(
            "/api/v1/themes/default",
            expect.objectContaining({ method: "POST" }),
        );
        expect(
            screen.queryByRole("button", { name: "Use built-in theme" }),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
        expect(onToast).not.toHaveBeenCalled();
    });

    it("explains legacy and managed theme capabilities and exposes full-page settings links", async () => {
        vi.spyOn(globalThis, "fetch").mockImplementation(() =>
            reply({
                data: [
                    legacy,
                    {
                        id: "modern",
                        name: "Workshop",
                        manifest: {
                            capabilities: [
                                "guided-homepage",
                                "managed-navigation",
                            ],
                        },
                    },
                ],
                active_theme: null,
                can_rollback: false,
            }),
        );
        render(<Themes onToast={vi.fn()} />);
        expect(
            await screen.findByText("Expert homepage layouts only"),
        ).toBeInTheDocument();
        expect(
            screen.getByText("Header and footer are controlled by this theme"),
        ).toBeInTheDocument();
        expect(
            screen.getByText("Guided homepage supported"),
        ).toBeInTheDocument();
        expect(
            screen.getByText("Header and footer follow website settings"),
        ).toBeInTheDocument();
        expect(
            screen.getByRole("button", { name: "Restore previous" }),
        ).toBeDisabled();
        expect(
            screen.getByRole("link", { name: "Website appearance" }),
        ).toHaveAttribute("href", "/app/website/appearance");
        expect(
            document.querySelector('a[href="/app/themes/modern/settings"]'),
        ).toBeInTheDocument();
    });

    it("preserves the active theme on a failed compatibility check", async () => {
        vi.spyOn(globalThis, "fetch").mockImplementation((_url, options) =>
            options?.method === "POST"
                ? reply(
                      {
                          message:
                              "This theme does not support guided homepage sections.",
                      },
                      422,
                  )
                : reply({
                      data: [legacy],
                      active_theme: null,
                      can_rollback: false,
                  }),
        );
        render(<Themes onToast={vi.fn()} />);
        await userEvent.click(
            await screen.findByRole("button", { name: "Activate theme" }),
        );
        expect(await screen.findByRole("alert")).toHaveTextContent(
            "does not support guided homepage",
        );
        await waitFor(() =>
            expect(
                screen.getByRole("button", { name: "Activate theme" }),
            ).toBeEnabled(),
        );
        expect(screen.queryByRole("status")).not.toBeInTheDocument();
    });
});
