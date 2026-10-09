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
import Website from "../sections/Website";

vi.mock("../components/Writer", () => ({
    default: ({
        initialContent,
        onChange,
    }: {
        initialContent: any[];
        onChange: (value: any[]) => void;
    }) => (
        <textarea
            aria-label="BlockNote writing"
            defaultValue={initialContent?.[0]?.content?.[0]?.text ?? ""}
            onChange={(e) =>
                onChange([
                    {
                        type: "paragraph",
                        content: [
                            { type: "text", text: e.target.value, styles: {} },
                        ],
                    },
                ])
            }
        />
    ),
}));
const reply = (data: unknown, status = 200) =>
    Promise.resolve(new Response(JSON.stringify(data), { status }));
const profile = {
    name: "Taylor Plumbing",
    trade: "Plumber",
    phone: "+1 416 555 0199",
    email: "hello@example.com",
    address_visibility: "hidden",
    address: "",
    city: "Toronto",
    region: "ON",
    postal_code: "",
    country: "CA",
    timezone: "America/Toronto",
    service_areas: ["Toronto"],
    hours: [],
    special_hours: [],
    profile_urls: [],
};
const settings = () => ({
    id: "main",
    version: 3,
    has_unpublished_changes: false,
    draft: {
        appearance: {
            colors: {
                primary: "#245588",
                accent: "#f89a31",
                background: "#ffffff",
                surface: "#f7f9fb",
                text: "#112233",
                muted: "#64748b",
                border: "#e2e8f0",
            },
            heading_font_id: "inter",
            body_font_id: "inter",
            type_scale: "standard",
            button_radius: "soft",
            content_width: "standard",
            section_spacing: "standard",
            logo_media_id: null,
            favicon_media_id: null,
        },
        navigation: {
            primary: [],
            footer: [],
            header_button: null,
            show_client_portal: true,
        },
        profile,
        locations: [],
    },
    published: {},
});
const section = (id: string, type: string, heading: string) => ({
    id,
    type,
    heading,
    eyebrow: "",
    enabled: true,
    variant: "standard",
    body: [],
    buttons: [],
    items: [],
});
const home = () => ({
    id: "home-1",
    title: "Home",
    type: "home",
    version: 7,
    status: "published",
    locale: "en-CA",
    content: [],
    layout: [],
    home_sections: {
        schema_version: 1,
        mode: "guided",
        sections: [
            section("hero", "hero", "Work you can trust"),
            section("process", "process", "How we work"),
        ],
    },
});
const catalog = [
    {
        id: "inter",
        name: "Inter",
        family: "Inter",
        category: "sans-serif",
        weights: [400, 700],
    },
    {
        id: "lora",
        name: "Lora",
        family: "Lora",
        category: "serif",
        weights: [400, 700],
    },
];

describe("website editors", () => {
    it("edits locally hosted typography and publishes the exact saved settings version without dialogs", async () => {
        const writes: { path: string; body: any }[] = [];
        let saved = settings();
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            const path = String(url);
            if (path.endsWith("/fonts")) return reply({ data: catalog });
            if (path.endsWith("/pages")) return reply({ data: [] });
            if (options?.method === "PUT") {
                const body = JSON.parse(String(options.body));
                writes.push({ path, body });
                saved = { ...saved, version: 4, draft: body.draft };
            }
            if (options?.method === "POST") {
                writes.push({ path, body: JSON.parse(String(options.body)) });
                saved = { ...saved, version: 5 };
            }
            return reply({ data: saved });
        });
        render(<Website page="appearance" />);
        await screen.findByLabelText("Heading font");
        await within(screen.getByLabelText("Heading font")).findByRole(
            "option",
            { name: "Lora" },
        );
        fireEvent.change(screen.getByLabelText("Heading font"), {
            target: { value: "lora" },
        });
        fireEvent.change(screen.getByLabelText("Primary color"), {
            target: { value: "#123456" },
        });
        await userEvent.click(
            screen.getByRole("button", { name: "Publish website settings" }),
        );
        await screen.findByText("Website settings published.");
        expect(
            writes.map((item) => [item.path, item.body.expected_version]),
        ).toEqual([
            ["/api/v1/website", 3],
            ["/api/v1/website/publish", 4],
        ]);
        expect(writes[0].body.draft.appearance).toMatchObject({
            heading_font_id: "lora",
            colors: { primary: "#123456" },
        });
        expect(writes[0].body.draft.profile).toEqual(profile);
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("preserves unsaved profile values and backend field errors after a rejected save", async () => {
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) =>
            options?.method === "PUT"
                ? reply(
                      {
                          message: "Review your public phone.",
                          errors: {
                              "draft.profile.phone": [
                                  "Enter a usable public phone.",
                              ],
                          },
                      },
                      422,
                  )
                : reply({
                      data: String(url).endsWith("/pages") ? [] : settings(),
                  }),
        );
        render(<Website page="business-profile" />);
        const name = await screen.findByLabelText("Public business name");
        fireEvent.change(name, { target: { value: "Taylor Service Co." } });
        fireEvent.change(screen.getByLabelText("Public phone"), {
            target: { value: "invalid" },
        });
        await userEvent.click(
            screen.getByRole("button", { name: "Save draft" }),
        );
        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Review your public phone.",
        );
        expect(screen.getByLabelText("Fields to review")).toHaveTextContent(
            "Enter a usable public phone.",
        );
        expect(name).toHaveValue("Taylor Service Co.");
        expect(screen.getByLabelText("Public phone")).toHaveValue("invalid");
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("keeps forms disabled during an outstanding save and sends version conflicts to explicit recovery", async () => {
        let resolve!: (response: Response) => void;
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) =>
            options?.method === "PUT"
                ? new Promise<Response>((done) => {
                      resolve = done;
                  })
                : reply({
                      data: String(url).endsWith("/pages") ? [] : settings(),
                  }),
        );
        render(<Website page="business-profile" />);
        const name = await screen.findByLabelText("Public business name");
        fireEvent.change(name, { target: { value: "Unsaved company" } });
        await userEvent.click(
            screen.getByRole("button", { name: "Save draft" }),
        );
        expect(name).toBeDisabled();
        resolve(
            new Response(JSON.stringify({ message: "A newer draft exists." }), {
                status: 409,
            }),
        );
        expect(await screen.findByRole("alert")).toHaveTextContent(
            "A newer draft exists.",
        );
        expect(name).toBeEnabled();
        expect(name).toHaveValue("Unsaved company");
        expect(
            screen.getByRole("button", { name: "Discard my edits and reload" }),
        ).toBeInTheDocument();
    });

    it("reorders homepage sections accessibly and saves typed FAQ content with the complete draft", async () => {
        let written: any;
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            if (options?.method === "PUT") {
                written = JSON.parse(String(options.body));
                return reply({
                    data: { ...written, id: "home-1", version: 8 },
                });
            }
            if (String(url).endsWith("/presets")) return reply({ data: {} });
            return reply({ data: [home()] });
        });
        render(<Website page="homepage" />);
        await screen.findByLabelText("Main headline");
        await userEvent.click(
            screen.getByRole("button", { name: "Move How we work up" }),
        );
        fireEvent.change(screen.getByLabelText("Section type"), {
            target: { value: "faq" },
        });
        await userEvent.click(
            screen.getByRole("button", { name: "Add section" }),
        );
        fireEvent.change(screen.getByLabelText("Section heading"), {
            target: { value: "Before your service visit" },
        });
        await userEvent.click(
            screen.getByRole("button", { name: "Add question" }),
        );
        fireEvent.change(screen.getByLabelText("Question"), {
            target: { value: "What should I prepare?" },
        });
        fireEvent.change(screen.getByLabelText("Answer"), {
            target: {
                value: "Share access details and the symptoms you noticed.",
            },
        });
        await userEvent.click(
            screen.getByRole("button", { name: "Save draft" }),
        );
        await screen.findByText(
            "Homepage draft saved. Preview it before publishing.",
        );
        expect(written.expected_version).toBe(7);
        expect(
            written.home_sections.sections.map((item: any) => item.type),
        ).toEqual(["process", "hero", "faq"]);
        expect(written.home_sections.sections[2].items).toEqual([
            {
                question: "What should I prepare?",
                answer: "Share access details and the symptoms you noticed.",
            },
        ]);
        expect(
            screen.getByRole("link", { name: "Preview saved draft" }),
        ).toHaveAttribute("href", "/api/v1/pages/home-1/preview");
    });

    it("preserves expert layouts until the editor deliberately selects guided mode", async () => {
        const record = {
            ...home(),
            home_sections: { schema_version: 1, mode: "expert", sections: [] },
            layout: { pages: [{ id: "legacy" }] },
        };
        const fetch = vi.spyOn(globalThis, "fetch").mockImplementation((url) =>
            reply({
                data: String(url).endsWith("/presets") ? {} : [record],
            }),
        );
        render(<Website page="homepage" />);
        expect(
            await screen.findByRole("heading", {
                name: "Expert layout is active",
            }),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText("Section type")).not.toBeInTheDocument();
        expect(
            fetch.mock.calls.every(
                ([, init]) => (init?.method ?? "GET") === "GET",
            ),
        ).toBe(true);
        fireEvent.change(screen.getByLabelText("Editing mode"), {
            target: { value: "guided" },
        });
        expect(screen.getByLabelText("Section type")).toBeInTheDocument();
        expect(
            fetch.mock.calls.every(
                ([, init]) => (init?.method ?? "GET") === "GET",
            ),
        ).toBe(true);
    });

    it("records citation evidence as a manual observation and retains its comparison result", async () => {
        let body: any;
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            if (options?.method === "POST") {
                body = JSON.parse(String(options.body));
                return reply({
                    data: {
                        ...body,
                        id: "citation-1",
                        version: 1,
                        provenance: "manual",
                        comparison: {
                            status: "difference",
                            differing_fields: ["phone"],
                        },
                    },
                });
            }
            return reply({
                data: String(url).endsWith("/citations") ? [] : settings(),
            });
        });
        render(<Website page="citations" />);
        await screen.findByRole("heading", {
            name: "Listing observations",
            level: 2,
        });
        await userEvent.click(
            screen.getByRole("button", { name: "Add observation" }),
        );
        fireEvent.change(screen.getByLabelText("Directory / provider"), {
            target: { value: "Business directory" },
        });
        fireEvent.change(screen.getByLabelText("Listing URL"), {
            target: { value: "https://example.com/listing" },
        });
        fireEvent.change(screen.getByLabelText("Observed phone"), {
            target: { value: "+1 416 555 0100" },
        });
        await userEvent.click(
            screen.getByRole("button", { name: "Save observation" }),
        );
        await screen.findByText(
            "Observation saved with its manual provenance.",
        );
        expect(body).toMatchObject({
            location_id: "primary",
            provider: "Business directory",
            observed_phone: "+1 416 555 0100",
            checked_at: null,
        });
        expect(screen.getByText("Differences: Phone")).toBeInTheDocument();
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });
    it("explicit conflict recovery fetches the latest version instead of reusing stale cached settings", async () => {
        let reads = 0;
        const versions: number[] = [];
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            if (String(url).endsWith("/pages")) return reply({ data: [] });
            if (options?.method === "PUT") {
                const body = JSON.parse(String(options.body));
                versions.push(body.expected_version);
                return versions.length === 1
                    ? reply({ message: "A newer draft exists." }, 409)
                    : reply({
                          data: {
                              ...settings(),
                              version: 10,
                              draft: body.draft,
                          },
                      });
            }
            reads++;
            const value = settings();
            return reply({
                data:
                    reads === 1
                        ? value
                        : {
                              ...value,
                              version: 9,
                              draft: {
                                  ...value.draft,
                                  profile: {
                                      ...profile,
                                      name: "Newest company name",
                                  },
                              },
                          },
            });
        });
        render(<Website page="business-profile" />);
        fireEvent.change(await screen.findByLabelText("Public business name"), {
            target: { value: "My unsaved draft" },
        });
        await userEvent.click(
            screen.getByRole("button", { name: "Save draft" }),
        );
        await screen.findByRole("alert");
        expect(screen.getByLabelText("Public business name")).toHaveValue(
            "My unsaved draft",
        );
        await userEvent.click(
            screen.getByRole("button", { name: "Discard my edits and reload" }),
        );
        await waitFor(() =>
            expect(screen.getByLabelText("Public business name")).toHaveValue(
                "Newest company name",
            ),
        );
        await userEvent.click(
            screen.getByRole("button", { name: "Save draft" }),
        );
        await waitFor(() => expect(versions).toEqual([3, 9]));
    });

    it("restores homepage revisions into a draft without triggering publication", async () => {
        const posts: { path: string; body: any }[] = [];
        const restored = home();
        restored.version = 8;
        restored.home_sections.sections[0].heading = "Restored headline";
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            const path = String(url);
            if (path.endsWith("/presets")) return reply({ data: {} });
            if (path.endsWith("/revisions"))
                return reply({
                    data: [
                        {
                            id: "revision-1",
                            page_version: 3,
                            created_at: "2026-10-09T12:00:00Z",
                        },
                    ],
                });
            if (options?.method === "POST") {
                posts.push({ path, body: JSON.parse(String(options.body)) });
                return reply({ data: restored });
            }
            return reply({ data: [home()] });
        });
        render(<Website page="homepage" />);
        await screen.findByLabelText("Main headline");
        await userEvent.click(
            screen.getByRole("button", { name: "Load revisions" }),
        );
        await userEvent.click(
            await screen.findByRole("button", { name: "Restore to draft" }),
        );
        await screen.findByText(
            "Revision restored to draft. Publish when ready.",
        );
        expect(screen.getByLabelText("Main headline")).toHaveValue(
            "Restored headline",
        );
        expect(posts).toEqual([
            {
                path: "/api/v1/pages/home-1/revisions/revision-1/restore",
                body: { expected_version: 7 },
            },
        ]);
    });

    it("keeps citation evidence time unchanged when assigning a correction to a staff member", async () => {
        const observation = {
            id: "citation-1",
            version: 3,
            location_id: "primary",
            provider: "Directory",
            listing_url: "https://example.com/listing",
            observed_name: "Taylor Plumbing",
            observed_address: null,
            observed_phone: "1234567890",
            observed_website: null,
            checked_at: "2026-10-08T10:15:23+00:00",
            correction_status: "none",
            correction_owner: null,
            notes: null,
            comparison: { status: "difference", differing_fields: ["phone"] },
        };
        let written: any;
        vi.spyOn(globalThis, "fetch").mockImplementation((url, options) => {
            if (options?.method === "PUT") {
                written = JSON.parse(String(options.body));
                return reply({
                    data: { ...observation, ...written, version: 4 },
                });
            }
            if (String(url).endsWith("/team"))
                return reply({
                    data: [
                        {
                            id: "staff-1",
                            name: "Office manager",
                            active: true,
                            role: "office",
                        },
                    ],
                });
            return reply({
                data: String(url).endsWith("/citations")
                    ? [observation]
                    : settings(),
            });
        });
        render(<Website page="citations" />);
        await userEvent.click(
            await screen.findByRole("button", { name: "Edit observation" }),
        );
        fireEvent.change(screen.getByLabelText("Correction status"), {
            target: { value: "assigned" },
        });
        fireEvent.change(screen.getByLabelText("Correction owner"), {
            target: { value: "staff-1" },
        });
        await userEvent.click(
            screen.getByRole("button", { name: "Save observation" }),
        );
        await screen.findByText(
            "Observation saved with its manual provenance.",
        );
        expect(written).toMatchObject({
            expected_version: 3,
            correction_status: "assigned",
            correction_owner: "staff-1",
            checked_at: observation.checked_at,
        });
    });
    it("opens the published homepage ahead of archived and unpublished homepage records", async () => {
        const archived = {
            ...home(),
            id: "archived-home",
            title: "Archived home",
            status: "archived",
        };
        const draft = {
            ...home(),
            id: "draft-home",
            title: "Draft home",
            status: "draft",
        };
        const published = {
            ...home(),
            id: "published-home",
            title: "Current homepage",
        };
        vi.spyOn(globalThis, "fetch").mockImplementation((url) =>
            reply({
                data: String(url).endsWith("/presets")
                    ? {}
                    : [archived, draft, published],
            }),
        );
        render(<Website page="homepage" />);
        expect(await screen.findByLabelText("Page title")).toHaveValue(
            "Current homepage",
        );
        expect(
            screen.getByRole("link", { name: "Preview saved draft" }),
        ).toHaveAttribute("href", "/api/v1/pages/published-home/preview");
    });

    it("links diagnostics to dedicated settings pages without exposing raw paths", async () => {
        vi.spyOn(globalThis, "fetch").mockImplementation(() =>
            reply({
                data: {
                    profile: {},
                    locations: [],
                    counts: {},
                    coverage: [],
                    diagnostics: [
                        {
                            code: "missing_phone",
                            severity: "warning",
                            message: "Add a public phone number.",
                            path: "/app/website/local-seo/business-profile",
                        },
                    ],
                },
            }),
        );
        render(<Website page="local-seo" />);
        const link = await screen.findByRole("link", {
            name: "Review settings",
        });
        expect(link).toHaveAttribute(
            "href",
            "/app/website/local-seo/business-profile",
        );
        expect(
            screen.queryByText("/app/website/local-seo/business-profile"),
        ).not.toBeInTheDocument();
    });
});
