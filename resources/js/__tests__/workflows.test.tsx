/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect, vi } from "vitest";
import Records from "../sections/Records";
import Finance from "../sections/Finance";
import type { User } from "../api";
const owner: User = {
    id: "owner-1",
    name: "Casey Owner",
    email: "owner@example.test",
    role: "owner",
};
function reply(data: any, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(data), { status }));
}
describe("business workflows", () => {
    it("creates a customer from the real form and refreshes the list", async () => {
        const requests: any[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            requests.push({ url, options });
            if (options?.method === "POST")
                return reply({
                    data: { id: "customer-new", name: "Avery Taylor" },
                });
            return reply({ data: [] });
        });
        const onToast = vi.fn();
        render(<Records kind="customers" user={owner} onToast={onToast} />);
        await userEvent.click(
            screen.getByRole("button", { name: "New customer" }),
        );
        await userEvent.type(
            screen.getByLabelText("Customer name"),
            "Avery Taylor",
        );
        await userEvent.type(
            screen.getByLabelText("Email"),
            "avery@example.test",
        );
        await userEvent.click(
            screen.getByRole("button", { name: "Save customer" }),
        );
        await waitFor(() =>
            expect(onToast).toHaveBeenCalledWith("Customer saved"),
        );
        const write = requests.find((r) => r.options?.method === "POST");
        expect(write.url).toBe("/api/v1/customers");
        expect(JSON.parse(write.options.body)).toMatchObject({
            name: "Avery Taylor",
            email: "avery@example.test",
        });
    });
    it("shows server validation failures without closing the form", async () => {
        vi.spyOn(globalThis, "fetch").mockImplementation((_url, options) =>
            options?.method === "POST"
                ? reply({ message: "Choose a valid customer." }, 422)
                : reply({ data: [] }),
        );
        render(<Records kind="customers" user={owner} onToast={vi.fn()} />);
        await userEvent.click(
            screen.getByRole("button", { name: "New customer" }),
        );
        await userEvent.type(screen.getByLabelText("Customer name"), "Avery");
        await userEvent.click(
            screen.getByRole("button", { name: "Save customer" }),
        );
        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Choose a valid customer.",
        );
        expect(screen.getByRole("dialog")).toBeInTheDocument();
    });
    it("offers clients read-only job details without staff creation controls", async () => {
        vi.spyOn(globalThis, "fetch").mockImplementation(() =>
            reply({
                data: [
                    {
                        id: "job-1",
                        title: "Water heater installation",
                        status: "scheduled",
                        address: "22 Park Street",
                    },
                ],
            }),
        );
        render(
            <Records
                kind="jobs"
                user={{ ...owner, role: "client" }}
                onToast={vi.fn()}
            />,
        );
        await userEvent.click(
            await screen.findByRole("button", {
                name: "Water heater installation",
            }),
        );
        expect(
            screen.queryByRole("button", { name: "New job" }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Save job" }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole("dialog")).toHaveTextContent("22 Park Street");
    });
    it("does not offer unconfigured payment providers", async () => {
        vi.spyOn(globalThis, "fetch").mockImplementation((url) =>
            String(url).endsWith("payment-providers")
                ? reply({ data: { stripe: false, paypal: false } })
                : reply({
                      data: [
                          {
                              id: "invoice-1",
                              title: "Kitchen repair",
                              number: "INV-001",
                              status: "issued",
                              total_minor: 10000,
                              balance_minor: 10000,
                              paid_minor: 0,
                              currency: "USD",
                              items: [],
                          },
                      ],
                  }),
        );
        render(
            <Finance
                kind="invoices"
                user={{ ...owner, role: "client" }}
                onToast={vi.fn()}
            />,
        );
        await userEvent.click(
            await screen.findByRole("button", { name: "Open Kitchen repair" }),
        );
        expect(
            screen.queryByRole("button", { name: "Pay with card" }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "PayPal" }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Record payment" }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole("link", { name: "Download PDF" }),
        ).toHaveAttribute("href", "/api/v1/invoices/invoice-1/pdf");
    });
});
