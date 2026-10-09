/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { Calendar, Tasks } from "../sections/Planning";
import { dateKey, type User } from "../api";

const owner: User = {
    id: "owner-1",
    name: "Casey Owner",
    email: "casey@example.test",
    role: "owner",
};
const current = new Date();
const day = dateKey(new Date(current.getFullYear(), current.getMonth(), 15));
const dateCaption = new Date(`${day}T12:00:00`).toLocaleDateString("en-US", {
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric",
});
function reply(data: unknown, status = 200) {
    return Promise.resolve(new Response(JSON.stringify(data), { status }));
}

describe("task board", () => {
    it("creates a task with an assignee, checklist, and CRM link without a dialog", async () => {
        const writes: { path: string; body: any }[] = [];
        const taskRows: any[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            const path = String(url);
            if (options?.method === "POST") {
                const body = JSON.parse(String(options.body));
                writes.push({ path, body });
                const task = { id: "task-new", version: 1, ...body };
                taskRows.push(task);
                return reply({ data: task }, 201);
            }
            return reply({
                data:
                    path === "/api/v1/team"
                        ? [
                              {
                                  id: "tech-1",
                                  name: "Morgan Technician",
                                  role: "technician",
                                  active: true,
                              },
                          ]
                        : path === "/api/v1/customers"
                          ? [{ id: "customer-1", name: "Avery Customer" }]
                          : path === "/api/v1/tasks"
                            ? taskRows
                            : [],
            });
        });
        const onToast = vi.fn();
        render(<Tasks user={owner} onToast={onToast} />);
        await userEvent.click(screen.getByRole("button", { name: "New task" }));
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
        await userEvent.type(
            screen.getByLabelText("Task title"),
            "Order replacement parts",
        );
        await userEvent.type(
            screen.getByLabelText("Task notes"),
            "Confirm the model with the customer.",
        );
        await userEvent.click(
            await screen.findByLabelText("Morgan Technician"),
        );
        await userEvent.selectOptions(
            screen.getByLabelText("Related CRM record"),
            "customer:customer-1",
        );
        await userEvent.click(screen.getByRole("button", { name: "Add item" }));
        await userEvent.type(
            screen.getByLabelText("Checklist item 1"),
            "Confirm part number",
        );
        await userEvent.click(
            screen.getByRole("button", { name: "Save task" }),
        );
        await waitFor(() => expect(onToast).toHaveBeenCalledWith("Task saved"));
        expect(writes).toEqual([
            {
                path: "/api/v1/tasks",
                body: expect.objectContaining({
                    title: "Order replacement parts",
                    assigned_to: ["tech-1"],
                    customer_id: "customer-1",
                    checklist: [{ label: "Confirm part number", done: false }],
                    status: "open",
                    due_at: null,
                }),
            },
        ]);
        expect(
            await screen.findByRole("article", {
                name: "Order replacement parts",
            }),
        ).toBeInTheDocument();
    });

    it("persists keyboard status changes with the current version and updates completion", async () => {
        const task = {
            id: "task-1",
            title: "Call the supplier",
            status: "open",
            version: 3,
            assigned_to: [],
        };
        const writes: any[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((_url, options) => {
            if (options?.method === "PATCH") {
                const body = JSON.parse(String(options.body));
                writes.push(body);
                return reply({ data: { ...task, ...body, version: 4 } });
            }
            return reply({ data: [task] });
        });
        render(<Tasks user={owner} onToast={vi.fn()} />);
        await userEvent.selectOptions(
            await screen.findByLabelText("Status for Call the supplier"),
            "done",
        );
        await waitFor(() =>
            expect(
                screen.getByRole("progressbar", { name: "Board completion" }),
            ).toHaveAttribute("value", "1"),
        );
        expect(writes).toEqual([{ status: "done", expected_version: 3 }]);
        expect(
            within(
                screen.getByRole("region", { name: "Done tasks" }),
            ).getByRole("article", { name: "Call the supplier" }),
        ).toBeInTheDocument();
    });

    it("persists drag-and-drop and refreshes stale records after a conflict", async () => {
        let version = 2;
        let status = "open";
        const writes: any[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((_url, options) => {
            if (options?.method === "PATCH") {
                writes.push(JSON.parse(String(options.body)));
                status = "blocked";
                version = 3;
                return reply(
                    {
                        message:
                            "This task was changed by another team member.",
                    },
                    409,
                );
            }
            return reply({
                data: [
                    {
                        id: "task-1",
                        title: "Confirm access",
                        status,
                        version,
                        assigned_to: [],
                    },
                ],
            });
        });
        render(<Tasks user={owner} onToast={vi.fn()} />);
        const task = await screen.findByRole("article", {
            name: "Confirm access",
        });
        fireEvent.dragStart(task, {
            dataTransfer: { setData: vi.fn(), effectAllowed: "" },
        });
        fireEvent.drop(
            screen.getByRole("region", { name: "In progress tasks" }),
        );
        expect(await screen.findByRole("alert")).toHaveTextContent(
            "changed by another team member",
        );
        await waitFor(() =>
            expect(
                within(
                    screen.getByRole("region", { name: "Blocked tasks" }),
                ).getByRole("article", { name: "Confirm access" }),
            ).toBeInTheDocument(),
        );
        expect(writes).toEqual([
            { status: "in_progress", expected_version: 2 },
        ]);
    });

    it("lets technicians update assigned progress without assignment or creation controls", async () => {
        const requests: string[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((url) => {
            requests.push(String(url));
            return reply({
                data: [
                    {
                        id: "task-1",
                        title: "Inspect drain",
                        status: "open",
                        version: 1,
                        assigned_to: ["tech-1"],
                        checklist: [{ label: "Photograph trap", done: false }],
                    },
                ],
            });
        });
        render(
            <Tasks
                user={{ ...owner, id: "tech-1", role: "technician" }}
                onToast={vi.fn()}
            />,
        );
        await userEvent.click(
            await screen.findByRole("button", { name: "Inspect drain" }),
        );
        expect(screen.getByLabelText("Task title")).toHaveAttribute("readonly");
        expect(screen.getByLabelText("Task notes")).toBeInTheDocument();
        expect(
            screen.queryByText("Assign team members"),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Add item" }),
        ).not.toBeInTheDocument();
        expect(requests).toEqual(["/api/v1/tasks"]);
    });
});

describe("CRM calendar", () => {
    it("opens the saved note date and month when its date changes during creation", async () => {
        const future = new Date(
            current.getFullYear(),
            current.getMonth() + 2,
            8,
        );
        const futureDay = dateKey(future);
        const stored: any[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            if (options?.method === "POST") {
                const saved = {
                    id: "future-note",
                    version: 1,
                    ...JSON.parse(String(options.body)),
                };
                stored.push(saved);
                return reply({ data: saved }, 201);
            }
            if (String(url).startsWith("/api/v1/crm-notes")) {
                const params = new URL(String(url), "http://localhost")
                    .searchParams;
                return reply({
                    data: stored.filter(
                        (note) =>
                            note.note_date >= (params.get("from") ?? "") &&
                            note.note_date <= (params.get("to") ?? ""),
                    ),
                });
            }
            return reply({ data: [] });
        });
        render(<Calendar user={owner} onToast={vi.fn()} />);
        await userEvent.selectOptions(
            screen.getByLabelText("Calendar event type"),
            "job",
        );
        await userEvent.click(
            screen.getByRole("button", { name: "Add CRM note" }),
        );
        await userEvent.type(
            screen.getByLabelText("Note title"),
            "Discuss winter maintenance",
        );
        await userEvent.type(
            screen.getByLabelText("CRM note"),
            "Call the customer about the maintenance plan.",
        );
        fireEvent.change(screen.getByLabelText("Note date"), {
            target: { value: futureDay },
        });
        await userEvent.click(
            screen.getByRole("button", { name: "Save note" }),
        );
        expect(
            await screen.findByRole("heading", {
                name: "Discuss winter maintenance",
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole("heading", {
                name: future.toLocaleDateString("en-US", {
                    month: "long",
                    year: "numeric",
                }),
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole("heading", {
                name: future.toLocaleDateString("en-US", {
                    weekday: "long",
                    month: "long",
                    day: "numeric",
                    year: "numeric",
                }),
            }),
        ).toBeInTheDocument();
        expect(screen.getByLabelText("Calendar event type")).toHaveValue("all");
    });
    it("sorts the daily agenda by local time, labels all-day notes, and names linked jobs", async () => {
        vi.spyOn(globalThis, "fetch").mockImplementation((url) => {
            const path = String(url);
            const rows =
                path === "/api/v1/jobs"
                    ? [
                          {
                              id: "job-1",
                              title: "Morning roof repair",
                              scheduled_at: `${day}T09:00:00`,
                              status: "scheduled",
                          },
                      ]
                    : path === "/api/v1/tasks"
                      ? [
                            {
                                id: "task-1",
                                title: "Order parts at lunch",
                                due_at: `${day}T12:15:00`,
                                status: "open",
                            },
                        ]
                      : path === "/api/v1/leads"
                        ? [
                              {
                                  id: "lead-1",
                                  name: "Avery afternoon call",
                                  follow_up_at: `${day}T14:00:00`,
                                  stage: "qualified",
                              },
                          ]
                        : path === "/api/v1/visits"
                          ? [
                                {
                                    id: "visit-1",
                                    job_title: "Late service visit",
                                    start_at: `${day}T16:00:00`,
                                    status: "confirmed",
                                },
                            ]
                          : path.startsWith("/api/v1/crm-notes")
                            ? [
                                  {
                                      id: "note-1",
                                      title: "Access instructions",
                                      body: "Use the side gate.",
                                      note_date: day,
                                      job_id: "job-1",
                                  },
                              ]
                            : [];
            return reply({ data: rows });
        });
        render(<Calendar user={owner} onToast={vi.fn()} />);
        await userEvent.click(
            await screen.findByRole("button", {
                name: `${dateCaption}, 5 events`,
            }),
        );
        const items = screen.getAllByRole("article");
        expect(
            items.map((item) => within(item).getByRole("heading").textContent),
        ).toEqual([
            "Access instructions",
            "Morning roof repair",
            "Order parts at lunch",
            "Avery afternoon call",
            "Late service visit",
        ]);
        expect(within(items[0]).getByText("All day")).toBeInTheDocument();
        expect(
            within(items[0]).getByText("Job: Morning roof repair"),
        ).toBeInTheDocument();
        expect(within(items[2]).getByText("12:15 PM")).toBeInTheDocument();
        expect(
            screen.getByText(
                `Times shown in ${Intl.DateTimeFormat().resolvedOptions().timeZone}. CRM notes are all day.`,
            ),
        ).toBeInTheDocument();
    });
    it("keeps date-only notes on the chosen date and saves edits with their version", async () => {
        const writes: any[] = [];
        const note = {
            id: "note-1",
            version: 5,
            title: "Site conversation",
            body: "Customer prefers a morning visit.",
            note_date: day,
        };
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            if (options?.method === "PATCH") {
                const body = JSON.parse(String(options.body));
                writes.push(body);
                return reply({ data: { ...note, ...body, version: 6 } });
            }
            return reply({
                data: String(url).startsWith("/api/v1/crm-notes") ? [note] : [],
            });
        });
        const onToast = vi.fn();
        render(<Calendar user={owner} onToast={onToast} />);
        await userEvent.click(
            await screen.findByRole("button", {
                name: `${dateCaption}, 1 event`,
            }),
        );
        expect(
            screen.getByRole("heading", { name: "Site conversation" }),
        ).toBeInTheDocument();
        await userEvent.click(
            screen.getByRole("button", { name: "Edit note Site conversation" }),
        );
        expect(screen.getByLabelText("Note date")).toHaveValue(day);
        await userEvent.clear(screen.getByLabelText("CRM note"));
        await userEvent.type(
            screen.getByLabelText("CRM note"),
            "Confirmed the gate code with the customer.",
        );
        await userEvent.click(
            screen.getByRole("button", { name: "Save note" }),
        );
        await waitFor(() =>
            expect(onToast).toHaveBeenCalledWith("CRM note saved"),
        );
        expect(writes[0]).toMatchObject({
            note_date: day,
            expected_version: 5,
            body: "Confirmed the gate code with the customer.",
        });
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("creates a CRM note for the selected day and preserves validation errors inline", async () => {
        const writes: any[] = [];
        let attempt = 0;
        vi.spyOn(globalThis, "fetch").mockImplementation((_url, options) => {
            if (options?.method === "POST") {
                writes.push(JSON.parse(String(options.body)));
                attempt += 1;
                return attempt === 1
                    ? reply({ message: "Choose a valid customer." }, 422)
                    : reply(
                          {
                              data: {
                                  id: "note-1",
                                  ...writes[writes.length - 1],
                              },
                          },
                          201,
                      );
            }
            return reply({ data: [] });
        });
        const onToast = vi.fn();
        render(<Calendar user={owner} onToast={onToast} />);
        await userEvent.click(
            await screen.findByRole("button", {
                name: `${dateCaption}, 0 events`,
            }),
        );
        await userEvent.click(
            screen.getByRole("button", { name: "Note for this day" }),
        );
        await userEvent.type(screen.getByLabelText("Note title"), "Call Avery");
        await userEvent.type(
            screen.getByLabelText("CRM note"),
            "Discuss the estimate options.",
        );
        await userEvent.click(
            screen.getByRole("button", { name: "Save note" }),
        );
        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Choose a valid customer.",
        );
        expect(screen.getByLabelText("Note title")).toHaveValue("Call Avery");
        await userEvent.click(
            screen.getByRole("button", { name: "Save note" }),
        );
        await waitFor(() =>
            expect(onToast).toHaveBeenCalledWith("CRM note saved"),
        );
        expect(writes[1]).toMatchObject({
            note_date: day,
            title: "Call Avery",
            body: "Discuss the estimate options.",
        });
        expect(writes[1]).not.toHaveProperty("expected_version");
    });

    it("shows client appointment status without fetching private tasks or CRM data", async () => {
        const requests: string[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((url) => {
            requests.push(String(url));
            return reply({
                data:
                    String(url) === "/api/v1/visits"
                        ? [
                              {
                                  id: "visit-1",
                                  job_title: "Kitchen plumbing",
                                  start_at: `${day}T10:30:00`,
                                  status: "requested",
                                  purpose: "Initial assessment",
                              },
                          ]
                        : [],
            });
        });
        render(
            <Calendar user={{ ...owner, role: "client" }} onToast={vi.fn()} />,
        );
        await userEvent.click(
            await screen.findByRole("button", {
                name: `${dateCaption}, 1 event`,
            }),
        );
        expect(screen.getByText(/Requested ·/)).toBeInTheDocument();
        expect(screen.getByText(/Initial assessment/)).toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Add CRM note" }),
        ).not.toBeInTheDocument();
        expect(requests.sort()).toEqual(["/api/v1/jobs", "/api/v1/visits"]);
    });

    it("changes month and clears the selected-day agenda when navigating", async () => {
        const urls: string[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((url) => {
            urls.push(String(url));
            return reply({ data: [] });
        });
        render(<Calendar user={owner} onToast={vi.fn()} />);
        const next = new Date(current.getFullYear(), current.getMonth() + 1, 1);
        await userEvent.click(
            screen.getByRole("button", { name: "Next month" }),
        );
        expect(
            screen.getByRole("heading", {
                name: next.toLocaleDateString("en-US", {
                    month: "long",
                    year: "numeric",
                }),
            }),
        ).toBeInTheDocument();
        await waitFor(() =>
            expect(
                urls.filter((url) => url.includes("crm-notes?from=")).length,
            ).toBeGreaterThan(1),
        );
        await userEvent.click(screen.getByRole("button", { name: "Today" }));
        expect(
            screen.getByRole("heading", {
                name: current.toLocaleDateString("en-US", {
                    month: "long",
                    year: "numeric",
                }),
            }),
        ).toBeInTheDocument();
    });
});
