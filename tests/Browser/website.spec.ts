/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { expect, test, type BrowserContext, type Page } from "@playwright/test";
import { existsSync, readFileSync, writeFileSync, unlinkSync } from "node:fs";

const localCredentials = existsSync("storage/local-credentials.txt")
    ? readFileSync("storage/local-credentials.txt", "utf8")
    : "";
const password =
    process.env.PLAYWRIGHT_PASSWORD ??
    localCredentials.match(/^Password: (.+)$/m)?.[1] ??
    "";
const owner = process.env.PLAYWRIGHT_EMAIL ?? "owner@example.test";
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:8976";

type RecordValue = Record<string, any>;
const recoveryPath = "storage/app/private/browser-website-recovery.json";
function rememberRecovery(value: RecordValue) {
    writeFileSync(recoveryPath, JSON.stringify(value), { mode: 0o600 });
}
function clearRecovery() {
    if (existsSync(recoveryPath)) unlinkSync(recoveryPath);
}

async function api(
    page: Page,
    path: string,
    method = "GET",
    body?: unknown,
): Promise<RecordValue> {
    const response = await page.evaluate(
        async ({ path, method, body }) => {
            const cookie = document.cookie
                .split("; ")
                .find((value) => value.startsWith("XSRF-TOKEN="));
            const token = cookie
                ? decodeURIComponent(cookie.slice("XSRF-TOKEN=".length))
                : (document.querySelector<HTMLMetaElement>(
                      'meta[name="csrf-token"]',
                  )?.content ?? "");
            const result = await fetch(`/api/v1/${path}`, {
                method,
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    [cookie ? "X-XSRF-TOKEN" : "X-CSRF-TOKEN"]: token,
                },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
            return {
                ok: result.ok,
                status: result.status,
                value: await result.json(),
            };
        },
        { path, method, body },
    );
    if (!response.ok)
        throw new Error(
            `${method} ${path} returned ${response.status}: ${response.value.message ?? "Request failed"}`,
        );
    return response.value;
}

async function restoreHome(
    page: Page,
    original: RecordValue | undefined,
    currentId?: string,
) {
    if (!currentId) return;
    let current = (await api(page, `pages/${currentId}`)).data;
    if (!original) {
        await api(page, `pages/${currentId}`, "DELETE", {
            expected_version: current.version,
        });
        return;
    }
    const draft = {
        ...original,
        home_sections: original.home_sections ?? null,
    };
    if (original.published && original.status === "published") {
        const saved = (
            await api(page, `pages/${currentId}`, "PUT", {
                ...original.published,
                home_sections: original.published.home_sections ?? null,
                expected_version: current.version,
            })
        ).data;
        current = (
            await api(page, `pages/${currentId}/publish`, "POST", {
                expected_version: saved.version,
            })
        ).data;
        if (original.has_unpublished_changes)
            await api(page, `pages/${currentId}`, "PUT", {
                ...draft,
                expected_version: current.version,
            });
    } else {
        if (current.status === "published")
            current = (
                await api(page, `pages/${currentId}/unpublish`, "POST", {
                    expected_version: current.version,
                })
            ).data;
        await api(page, `pages/${currentId}`, "PUT", {
            ...draft,
            expected_version: current.version,
        });
    }
}

async function restoreSettings(page: Page, original: RecordValue) {
    let current = (await api(page, "website")).data;
    if (original.published) {
        current = (
            await api(page, "website", "PUT", {
                expected_version: current.version,
                draft: original.published,
            })
        ).data;
        current = (
            await api(page, "website/publish", "POST", {
                expected_version: current.version,
            })
        ).data;
    }
    if (original.has_unpublished_changes || !original.published)
        await api(page, "website", "PUT", {
            expected_version: current.version,
            draft: original.draft,
        });
}

async function preview(page: Page, action: () => Promise<void>): Promise<Page> {
    const popup = page.waitForEvent("popup");
    await action();
    const opened = await popup;
    await opened.waitForLoadState("domcontentloaded");
    return opened;
}

async function expectNoOverflow(page: Page) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await expect(page.getByRole("dialog")).toHaveCount(0);
}

test.describe("dedicated website editing", () => {
    test.describe.configure({ mode: "serial" });
    test.skip(
        !password,
        "Set PLAYWRIGHT_PASSWORD or install the local demonstration before browser tests.",
    );
    let context: BrowserContext;
    let page: Page;
    const pageErrors: string[] = [];

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext({ baseURL });
        context.setDefaultTimeout(10000);
        page = await context.newPage();
        page.on("pageerror", (error) => pageErrors.push(error.message));
        await page.goto("/login");
        await page.getByLabel("Email address", { exact: true }).fill(owner);
        await page.getByLabel("Password", { exact: true }).fill(password);
        await page
            .getByRole("button", { name: "Sign in", exact: true })
            .click();
        await expect(page).toHaveURL(/\/app$/);
        if (existsSync(recoveryPath)) {
            const recovery = JSON.parse(readFileSync(recoveryPath, "utf8"));
            if (recovery.kind === "homepage")
                await restoreHome(page, recovery.original, recovery.id);
            if (recovery.kind === "settings")
                await restoreSettings(page, recovery.original);
            await api(
                page,
                recovery.theme
                    ? `themes/${recovery.theme}/activate`
                    : "themes/default",
                "POST",
            );
            clearRecovery();
        }
    });
    test.afterAll(async () => {
        await context?.close();
    });

    test("homepage sections save privately, preview, publish, reorder, and restore", async () => {
        test.setTimeout(120000);
        await page.goto("/app/website/homepage");
        const records = (await api(page, "pages")).data as RecordValue[];
        const original =
            records.find(
                (item) => item.type === "home" && item.status === "published",
            ) ??
            records.find(
                (item) => item.type === "home" && item.status !== "archived",
            );
        const theme = (await api(page, "themes")).active_theme;
        let homeId: string | undefined = original?.id;
        rememberRecovery({ kind: "homepage", original, id: homeId, theme });
        const marker = `Synthetic homepage check ${Date.now()}`;
        const guest = await context.browser()!.newContext({ baseURL });
        guest.setDefaultTimeout(10000);
        const publicPage = await guest.newPage();
        try {
            await api(page, "themes/default", "POST");
            await page.reload();
            await page
                .getByLabel("Editing mode", { exact: true })
                .selectOption("guided");
            await page
                .getByLabel("Trade preset", { exact: true })
                .selectOption("service");
            await page
                .getByRole("button", {
                    name: "Replace sections with preset",
                    exact: true,
                })
                .click();
            await page
                .getByLabel("Main headline", { exact: true })
                .fill(marker);
            const writer = page
                .locator('.website-writing [contenteditable="true"]')
                .first();
            await expect(writer).toBeVisible();
            await writer.fill(
                "Synthetic browser validation of the contractor homepage editor.",
            );
            await page.getByText("Section image", { exact: true }).click();
            await page
                .getByLabel("Image upload", { exact: true })
                .setInputFiles({
                    name: "synthetic-homepage-fixture.png",
                    mimeType: "image/png",
                    buffer: Buffer.from(
                        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=",
                        "base64",
                    ),
                });
            await expect(
                page.getByLabel("Image description", { exact: true }),
            ).toBeVisible();
            await page
                .getByLabel("Image description", { exact: true })
                .fill("Synthetic image for homepage publication testing");
            await page
                .getByLabel("Horizontal focal point", { exact: true })
                .press("End");
            await expect(
                page.getByLabel("Horizontal focal point", { exact: true }),
            ).toHaveValue("100");
            const imageSource = await page
                .locator(".website-image-preview img")
                .getAttribute("src");
            expect(imageSource).toMatch(/^\/files\//);
            expect((await guest.request.get(imageSource!)).status()).toBe(403);
            await page
                .getByRole("button", { name: "Save draft", exact: true })
                .click();
            await expect(
                page.getByText(
                    "Homepage draft saved. Preview it before publishing.",
                    { exact: true },
                ),
            ).toBeVisible();
            homeId = (await api(page, "pages")).data.find(
                (item: RecordValue) => item.type === "home",
            )?.id;
            rememberRecovery({ kind: "homepage", original, id: homeId, theme });
            await publicPage.goto("/");
            await expect(
                publicPage.getByRole("heading", { name: marker, exact: true }),
            ).toHaveCount(0);
            const draftPreview = await preview(page, () =>
                page
                    .getByRole("link", {
                        name: "Preview saved draft",
                        exact: true,
                    })
                    .click(),
            );
            await expect(
                draftPreview.getByRole("heading", {
                    name: marker,
                    exact: true,
                }),
            ).toBeVisible();
            await expect(
                draftPreview.locator('meta[name="robots"]'),
            ).toHaveAttribute("content", /noindex/);
            await draftPreview.close();
            await page
                .getByRole("button", { name: "Publish homepage", exact: true })
                .click();
            await expect(
                page.getByText("Homepage published.", { exact: true }),
            ).toBeVisible();
            await publicPage.reload();
            await expect(
                publicPage.getByRole("heading", { name: marker, exact: true }),
            ).toBeVisible();
            await expect(publicPage.locator("h1")).toHaveCount(1);
            await expect(
                publicPage.getByRole("img", {
                    name: "Synthetic image for homepage publication testing",
                    exact: true,
                }),
            ).toBeVisible();
            expect((await guest.request.get(imageSource!)).status()).toBe(200);
            await expect(
                publicPage.getByText(
                    "Synthetic browser validation of the contractor homepage editor.",
                    { exact: true },
                ),
            ).toBeVisible();
            await page
                .getByLabel("Section type", { exact: true })
                .selectOption("faq");
            await page
                .getByRole("button", { name: "Add section", exact: true })
                .click();
            await page
                .getByLabel("Section heading", { exact: true })
                .fill("Synthetic FAQ");
            await page
                .getByRole("button", { name: "Add question", exact: true })
                .click();
            await page
                .getByLabel("Question", { exact: true })
                .fill("How can I prepare for the visit?");
            await page
                .getByLabel("Answer", { exact: true })
                .fill(
                    "Share access details and your preferred appointment time.",
                );
            await page
                .getByRole("button", {
                    name: "Move Synthetic FAQ up",
                    exact: true,
                })
                .click();
            await page
                .getByRole("button", { name: "Save draft", exact: true })
                .click();
            await expect(
                page.getByText(
                    "Homepage draft saved. Preview it before publishing.",
                    { exact: true },
                ),
            ).toBeVisible();
            await page.reload();
            await expect(
                page.getByRole("button", {
                    name: /Synthetic FAQ.*Frequently asked questions/,
                }),
            ).toBeVisible();
            await publicPage.reload();
            await expect(
                publicPage.getByRole("heading", {
                    name: "Synthetic FAQ",
                    exact: true,
                }),
            ).toHaveCount(0);
            await page
                .getByRole("button", { name: "Publish homepage", exact: true })
                .click();
            await expect(
                page.getByText("Homepage published.", { exact: true }),
            ).toBeVisible();
            await publicPage.reload();
            await expect(
                publicPage.getByRole("heading", {
                    name: "Synthetic FAQ",
                    exact: true,
                }),
            ).toBeVisible();
            await publicPage
                .locator("summary")
                .filter({ hasText: "How can I prepare for the visit?" })
                .click();
            await expect(
                publicPage.getByText(
                    "Share access details and your preferred appointment time.",
                    { exact: true },
                ),
            ).toBeVisible();
            await page
                .getByRole("button", { name: "Load revisions", exact: true })
                .click();
            await expect(
                page
                    .getByRole("button", {
                        name: "Restore to draft",
                        exact: true,
                    })
                    .first(),
            ).toBeVisible();
            await page
                .getByRole("button", { name: "Restore to draft", exact: true })
                .first()
                .click();
            await expect(
                page.getByText(
                    "Revision restored to draft. Publish when ready.",
                    { exact: true },
                ),
            ).toBeVisible();
            await publicPage.reload();
            await expect(
                publicPage.getByRole("heading", { name: marker, exact: true }),
            ).toBeVisible();
            await expect(page.getByRole("dialog")).toHaveCount(0);
        } finally {
            await restoreHome(page, original, homeId);
            if (theme) await api(page, `themes/${theme}/activate`, "POST");
            else await api(page, "themes/default", "POST");
            clearRecovery();
            await page.goto("/app/website/homepage");
            await expect(
                page.getByLabel("Page title", { exact: true }),
            ).toBeVisible();
            if (await page.locator(".website-writing").count()) {
                await expect(
                    page
                        .locator('.website-writing [contenteditable="true"]')
                        .first(),
                ).toBeVisible();
            }
            await page.screenshot({
                path: "output/playwright/homepage-editor.png",
                fullPage: true,
            });
            await publicPage.goto("/");
            await publicPage.screenshot({
                path: "output/playwright/homepage-public.png",
                fullPage: true,
            });
            await guest.close();
        }
        expect(pageErrors).toEqual([]);
    });

    test("appearance, navigation, public identity and listing notes complete a draft-to-public workflow", async () => {
        test.setTimeout(120000);
        await page.goto("/app/website/appearance");
        const original = (await api(page, "website")).data;
        test.skip(
            !original.published,
            "This browser test requires an intentionally published website-settings baseline so cleanup can restore its public snapshot exactly.",
        );
        const theme = (await api(page, "themes")).active_theme;
        const guest = await context.browser()!.newContext({ baseURL });
        guest.setDefaultTimeout(10000);
        const publicPage = await guest.newPage();
        const marker = `Synthetic website check ${Date.now()}`;
        rememberRecovery({ kind: "settings", original, theme });
        try {
            await api(page, "themes/default", "POST");
            await page.reload();
            await page
                .getByLabel("Heading font", { exact: true })
                .selectOption("lora");
            await page
                .getByLabel("Primary color", { exact: true })
                .fill("#246A56");
            await page
                .getByRole("button", { name: "Save draft", exact: true })
                .click();
            await expect(
                page.getByText(
                    "Website draft saved. Public settings remain unchanged until publication.",
                    { exact: true },
                ),
            ).toBeVisible();
            await page.reload();
            await expect(
                page.getByLabel("Heading font", { exact: true }),
            ).toHaveValue("lora");
            await expect(
                page.getByLabel("Primary color", { exact: true }),
            ).toHaveValue("#246A56");
            const draft = await preview(page, () =>
                page
                    .getByRole("link", {
                        name: "Preview saved draft",
                        exact: true,
                    })
                    .click(),
            );
            await expect(draft.locator('meta[name="robots"]')).toHaveAttribute(
                "content",
                /noindex/,
            );
            await draft.close();
            await page.goto("/app/website/navigation");
            const navigation = original.draft.navigation;
            const primaryCount = navigation.primary.length;
            const footerCount = navigation.footer.length;
            const targetGroup = primaryCount < 8 ? "header" : "footer";
            test.skip(
                primaryCount >= 8 && footerCount >= 20,
                "The existing navigation is full; this non-destructive test requires one spare link slot.",
            );
            await page
                .getByRole("button", {
                    name: `Add ${targetGroup} link`,
                    exact: true,
                })
                .click();
            const lastLink = page
                .locator(".website-group")
                .filter({
                    has: page.getByRole("heading", {
                        name:
                            targetGroup === "header"
                                ? "Header links"
                                : "Footer links",
                        exact: true,
                    }),
                })
                .locator(".website-repeater")
                .last();
            await lastLink
                .getByLabel("Link label", { exact: true })
                .fill("Synthetic contact");
            await lastLink
                .getByLabel("Destination type", { exact: true })
                .selectOption("url");
            await lastLink
                .getByLabel("Destination URL", { exact: true })
                .fill(`${baseURL}/#contact`);
            await page
                .getByRole("button", { name: "Save draft", exact: true })
                .click();
            await expect(
                page.getByText(
                    "Website draft saved. Public settings remain unchanged until publication.",
                    { exact: true },
                ),
            ).toBeVisible();
            await page.goto("/app/website/local-seo/business-profile");
            await page
                .getByLabel("Public business name", { exact: true })
                .fill(marker);
            await page
                .getByLabel("Public phone", { exact: true })
                .fill("+1 416 555 0199");
            await page
                .getByLabel("Address visibility", { exact: true })
                .selectOption("hidden");
            await page
                .getByLabel("Street address", { exact: true })
                .fill("947 Synthetic Private Street");
            await page
                .getByRole("button", { name: "Save draft", exact: true })
                .click();
            await expect(
                page.getByText(
                    "Website draft saved. Public settings remain unchanged until publication.",
                    { exact: true },
                ),
            ).toBeVisible();
            await publicPage.goto("/");
            await expect(
                publicPage.getByText(marker, { exact: true }),
            ).toHaveCount(0);
            await page
                .getByRole("button", {
                    name: "Publish website settings",
                    exact: true,
                })
                .click();
            await expect(
                page.getByText("Website settings published.", { exact: true }),
            ).toBeVisible();
            await publicPage.reload();
            await expect(
                publicPage.getByText(marker, { exact: true }).first(),
            ).toBeVisible();
            await expect(
                publicPage.getByRole("link", {
                    name: "Synthetic contact",
                    exact: true,
                }),
            ).toBeVisible();
            expect(await publicPage.content()).not.toContain(
                "947 Synthetic Private Street",
            );
            const selectedStyle = await publicPage
                .locator("body")
                .evaluate((element) => ({
                    primary: getComputedStyle(element)
                        .getPropertyValue("--website-primary")
                        .trim(),
                    family: getComputedStyle(element.querySelector("h1")!)
                        .fontFamily,
                }));
            expect(selectedStyle.family).toContain("Lora");
            expect(selectedStyle.primary.toLowerCase()).toBe("#246a56");
            await page.goto("/app/website/local-seo/citations");
            await page
                .getByRole("button", { name: "Add observation", exact: true })
                .click();
            await page
                .getByLabel("Directory / provider", { exact: true })
                .fill(marker);
            await page
                .getByLabel("Listing URL", { exact: true })
                .fill("https://example.com/synthetic-browser-validation");
            await page
                .getByLabel("Observed business name", { exact: true })
                .fill(marker);
            await page
                .getByLabel("Observed phone", { exact: true })
                .fill("+1 416 555 0100");
            await page
                .getByLabel("Evidence & correction notes", { exact: true })
                .fill(
                    "Synthetic browser test only. No actual external listing was checked; observation intentionally remains unverified.",
                );
            await page
                .getByRole("button", { name: "Save observation", exact: true })
                .click();
            await expect(
                page.getByText(
                    "Observation saved with its manual provenance.",
                    { exact: true },
                ),
            ).toBeVisible();
            await page.reload();
            const row = page
                .locator(".website-citation-row")
                .filter({ hasText: marker });
            await expect(row).toBeVisible();
            await expect(row).toContainText("Unchecked");
            await expect(page.getByRole("dialog")).toHaveCount(0);
            await page.goto("/app/website/local-seo");
            await expect(
                page.getByRole("heading", {
                    name: "Website diagnostics",
                    exact: true,
                }),
            ).toBeVisible();
            await page.goto("/app/website/local-seo/page-plan");
            await expect(
                page.getByRole("heading", {
                    name: "Content coverage",
                    exact: true,
                }),
            ).toBeVisible();
        } finally {
            await restoreSettings(page, original);
            if (theme) await api(page, `themes/${theme}/activate`, "POST");
            else await api(page, "themes/default", "POST");
            clearRecovery();
            await guest.close();
        }
        expect(pageErrors).toEqual([]);
    });

    test("website settings are direct mobile pages without horizontal overflow or dialogs", async () => {
        await page.setViewportSize({ width: 390, height: 844 });
        for (const [path, heading] of [
            ["homepage", "Homepage"],
            ["appearance", "Colors & typography"],
            ["navigation", "Navigation"],
            ["local-seo", "Local SEO"],
            ["local-seo/business-profile", "Public business profile"],
            ["local-seo/locations", "Business locations"],
            ["local-seo/locations/new", "Location details"],
            ["local-seo/page-plan", "Page coverage"],
            ["local-seo/citations", "Listing observations"],
        ]) {
            await page.goto(`/app/website/${path}`);
            await expect(
                page.getByRole("heading", {
                    name: heading,
                    level: 1,
                    exact: true,
                }),
            ).toBeVisible();
            await expect(page.locator(".loading")).toHaveCount(0);
            await expectNoOverflow(page);
        }
        await page.screenshot({
            path: "output/playwright/website-settings-mobile.png",
            fullPage: true,
        });
        await page.setViewportSize({ width: 1440, height: 1000 });
        expect(pageErrors).toEqual([]);
    });
});
