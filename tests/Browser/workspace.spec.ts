/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { expect, test } from "@playwright/test";
import { readFileSync, existsSync } from "node:fs";
const credentialsPath = "storage/local-credentials.txt";
const local = existsSync(credentialsPath)
    ? readFileSync(credentialsPath, "utf8")
    : "";
const password =
    process.env.PLAYWRIGHT_PASSWORD ??
    local.match(/^Password: (.+)$/m)?.[1] ??
    "";
const owner = process.env.PLAYWRIGHT_EMAIL ?? "owner@example.test";
test.skip(
    !password,
    "Set PLAYWRIGHT_PASSWORD or install the local demonstration before browser tests.",
);
async function signIn(page: any, email = owner) {
    await page.goto("/login");
    await page.getByLabel("Email address", { exact: true }).fill(email);
    await page.getByLabel("Password", { exact: true }).fill(password);
    await page.getByRole("button", { name: "Sign in", exact: true }).click();
    await expect(page).toHaveURL(/\/app$/);
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
}
test("owner can inspect operations, invoice details, editors, and responsive navigation", async ({
    page,
}) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signIn(page);
    await expect(
        page.getByRole("heading", { name: "Today's schedule" }),
    ).toBeVisible();
    await page.screenshot({
        path: "output/playwright/dashboard-desktop.png",
        fullPage: true,
    });
    await page.getByRole("link", { name: "Invoices", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Invoices", exact: true }),
    ).toBeVisible();
    const invoice = page.getByRole("button", { name: /^Open / }).first();
    if (await invoice.count()) {
        await invoice.click();
        await expect(page.getByRole("dialog")).toBeVisible();
        await expect(
            page.getByRole("link", { name: "Download PDF" }),
        ).toBeVisible();
        await page.getByRole("button", { name: "Close dialog" }).click();
    }
    await page
        .getByRole("link", { name: "Website & content", exact: true })
        .click();
    await page.getByRole("button", { name: "Create page" }).click();
    await expect(
        page.locator('[contenteditable="true"]').first(),
    ).toBeVisible();
    await page.getByRole("tab", { name: "Layout", exact: true }).click();
    await expect(page.locator(".gjs-editor")).toBeVisible();
    await page.goto("/app");
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(
        page.getByRole("button", { name: "Open navigation" }),
    ).toBeVisible();
    await expect
        .poll(async () =>
            page
                .locator(".sidebar")
                .evaluate((el) => el.getBoundingClientRect().right),
        )
        .toBeLessThanOrEqual(0);
    await expect(page.locator(".sidebar")).toBeHidden();
    await page.screenshot({
        animations: "disabled",
        path: "output/playwright/dashboard-mobile.png",
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.getByRole("button", { name: "Open navigation" }).click();
    await page.getByRole("link", { name: "Jobs", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Jobs", exact: true }),
    ).toBeVisible();
    expect(errors).toEqual([]);
});
test("client portal hides staff creation and payroll controls", async ({
    page,
}) => {
    await signIn(page, "client@example.test");
    await expect(
        page.getByRole("link", { name: "Payroll", exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole("button", { name: "Add a lead", exact: true }),
    ).toHaveCount(0);
    await page.getByRole("link", { name: "Jobs", exact: true }).click();
    await expect(
        page.getByRole("button", { name: "New job", exact: true }),
    ).toHaveCount(0);
    await page.getByRole("link", { name: "Invoices", exact: true }).click();
    await expect(
        page.getByRole("button", { name: "New invoice", exact: true }),
    ).toHaveCount(0);
});

test("a website inquiry reaches the office and a reply reaches the guest", async ({
    page,
    browser,
}) => {
    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    await guest.goto(
        process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:8976/",
    );
    await guest.getByRole("button", { name: "Message our office" }).click();
    const chat = guest.getByRole("region", { name: "Website chat" });
    const visitor = `Browser check ${Date.now()}`;
    await chat.getByLabel("Your name", { exact: true }).fill(visitor);
    await chat
        .getByLabel("Email", { exact: true })
        .fill("browser-check@example.test");
    await chat
        .getByLabel("Message", { exact: true })
        .fill(
            "Synthetic browser test: I would like to arrange a service visit.",
        );
    await chat
        .getByRole("button", { name: "Send message", exact: true })
        .click();
    await expect(guest.getByRole("log")).toContainText(
        "Synthetic browser test",
    );
    await signIn(page);
    await page.getByRole("link", { name: "Messages", exact: true }).click();
    await page
        .getByRole("button", {
            name: new RegExp(`Website inquiry · ${visitor}`),
        })
        .click();
    await expect(page.locator(".chat-messages")).toContainText(
        "Synthetic browser test",
    );
    await page
        .getByLabel("Your message", { exact: true })
        .fill("Synthetic office reply: your inquiry is visible to our team.");
    await page
        .getByRole("button", { name: "Send message", exact: true })
        .click();
    await expect(page.locator(".chat-messages")).toContainText(
        "Synthetic office reply",
    );
    await expect(guest.getByRole("log")).toContainText(
        "Synthetic office reply",
        { timeout: 15000 },
    );
    await guestContext.close();
});

test("office confirms a visit and the client approves the exact change order", async ({
    page,
    browser,
}) => {
    await signIn(page);
    await page.getByRole("link", { name: "Jobs", exact: true }).click();
    await page.getByRole("button", { name: "New job", exact: true }).click();
    const jobName = `Browser workflow ${Date.now()}`;
    await page.getByLabel("Job title", { exact: true }).fill(jobName);
    await page
        .getByLabel("Customer", { exact: true })
        .selectOption({ label: "Casey Walker" });
    await page.getByRole("button", { name: "Save job", exact: true }).click();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await page
        .getByRole("button", { name: `Open ${jobName}`, exact: true })
        .click();
    await page
        .getByRole("button", { name: "Schedule a visit", exact: true })
        .click();
    await page
        .getByLabel("Preferred date and time", { exact: true })
        .fill("2032-01-15T14:00");
    await page.getByRole("button", { name: "Save visit", exact: true }).click();
    await expect(page.locator(".operation-row")).toContainText("Requested");
    await page
        .getByRole("button", { name: "Confirm visit", exact: true })
        .click();
    await page
        .getByRole("dialog", { name: "Confirm appointment", exact: true })
        .getByRole("checkbox")
        .first()
        .check();
    await page
        .getByRole("button", { name: "Confirm appointment", exact: true })
        .click();
    await expect(page.locator(".operation-row")).toContainText("Confirmed");
    await page
        .getByRole("button", { name: "Complete visit", exact: true })
        .click();
    await expect(page.locator(".operation-row")).toContainText("Completed");
    await page.getByRole("tab", { name: "Change orders", exact: true }).click();
    await page
        .getByRole("button", { name: "New change order", exact: true })
        .click();
    await page
        .getByLabel("Change title", { exact: true })
        .fill("Synthetic additional service");
    await page
        .getByLabel("Added or changed scope", { exact: true })
        .fill("Browser workflow validation only.");
    await page.getByLabel("Price adjustment", { exact: true }).fill("25.00");
    await page
        .getByRole("button", { name: "Save change draft", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Send for approval", exact: true })
        .click();
    await expect(page.locator(".change-order")).toContainText("Sent");
    const clientContext = await browser.newContext({
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:8976",
    });
    const clientPage = await clientContext.newPage();
    await signIn(clientPage, "client@example.test");
    await clientPage.getByRole("link", { name: "Jobs", exact: true }).click();
    await clientPage
        .getByRole("button", { name: `Open ${jobName}`, exact: true })
        .click();
    await clientPage
        .getByRole("tab", { name: "Change orders", exact: true })
        .click();
    await expect(clientPage.locator(".change-order")).toContainText("$25.00");
    await clientPage
        .getByRole("button", { name: "Accept this change", exact: true })
        .click();
    await expect(clientPage.locator(".change-order")).toContainText("Accepted");
    await clientContext.close();
});

test("an uploaded starter theme has a dedicated appearance settings page", async ({
    page,
}) => {
    await signIn(page);
    await page.getByRole("link", { name: "Themes", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Website themes", exact: true }),
    ).toBeVisible();
    const downloadEvent = page.waitForEvent("download");
    await page.getByRole("link", { name: "Download starter ZIP" }).click();
    const download = await downloadEvent;
    const zipPath = "output/playwright/starter-theme.zip";
    await download.saveAs(zipPath);
    await page
        .getByRole("link", { name: "Upload theme ZIP", exact: true })
        .click();
    await expect(page).toHaveURL(/\/app\/themes\/upload$/);
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await page.getByLabel("Theme ZIP", { exact: true }).setInputFiles(zipPath);
    await page
        .getByRole("button", { name: "Upload and validate", exact: true })
        .click();
    await expect(page.getByRole("status")).toContainText(
        "Theme uploaded and validated",
    );
    await page.getByRole("link", { name: "Open theme library" }).click();
    await page
        .locator(".theme-card")
        .filter({
            has: page.getByRole("link", { name: "Configure", exact: true }),
        })
        .first()
        .getByRole("link", { name: "Configure", exact: true })
        .click();
    await expect(page).toHaveURL(/\/app\/themes\/[^/]+\/settings$/);
    await expect(page.getByRole("dialog")).toHaveCount(0);
    const inputs = page.locator('.settings-edit-page input[type="text"]');
    await inputs.first().fill("Craftsmanship for your home");
    await page
        .getByRole("button", { name: "Save theme settings", exact: true })
        .click();
    await expect(page.getByRole("status")).toContainText(
        "Theme settings saved",
    );
    await page.reload();
    await expect(inputs.first()).toHaveValue("Craftsmanship for your home");
});

test("settings open as refreshable edit pages with inline feedback", async ({
    page,
}) => {
    await signIn(page);
    await page.getByRole("link", { name: "Settings", exact: true }).click();
    await page.getByRole("link", { name: /Business profile/ }).click();
    await expect(page).toHaveURL(/\/app\/settings\/business$/);
    await expect(
        page.getByRole("heading", { name: "Business profile", exact: true }),
    ).toBeVisible();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    const businessName = await page
        .getByLabel("Business name", { exact: true })
        .inputValue();
    await page
        .getByRole("button", { name: "Save settings", exact: true })
        .click();
    await expect(page.getByRole("status")).toContainText("saved");
    await page.reload();
    await expect(page.getByLabel("Business name", { exact: true })).toHaveValue(
        businessName,
    );
    await page
        .getByRole("link", { name: "Invoice designer", exact: true })
        .click();
    await expect(page).toHaveURL(/\/app\/settings\/invoice-design$/);
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await page.goBack();
    await expect(page.getByLabel("Business name", { exact: true })).toHaveValue(
        businessName,
    );
    await page.goto("/app/settings?tab=mail");
    await expect(page).toHaveURL(/\/app\/settings\/mail$/);
    await expect(
        page.getByRole("heading", { name: "Email delivery", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("link", { name: "AI assistants", exact: true })
        .click();
    let protectedWrites = 0;
    await page.route("**/api/v1/settings/ai", async (route) => {
        if (route.request().method() !== "PUT") return route.continue();
        protectedWrites++;
        return route.fulfill({
            status: 423,
            contentType: "application/json",
            body: JSON.stringify({ message: "Confirm your password." }),
        });
    });
    await page.getByLabel("Model", { exact: true }).fill("disabled-test-model");
    await page
        .getByRole("button", { name: "Save settings", exact: true })
        .click();
    await expect(
        page.getByRole("heading", {
            name: "Confirm your password",
            exact: true,
        }),
    ).toBeVisible();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(page.getByLabel("Model", { exact: true })).toBeDisabled();
    await page
        .getByRole("button", { name: "Cancel confirmation", exact: true })
        .click();
    await expect(page.getByLabel("Model", { exact: true })).toBeEnabled();
    expect(protectedWrites).toBe(1);
});

test("task progress and dated CRM notes persist across reloads", async ({
    page,
}) => {
    await signIn(page);
    const taskTitle = `Browser planning check ${Date.now()}`;
    const noteTitle = `CRM planning check ${Date.now()}`;
    await page.getByRole("link", { name: "Tasks", exact: true }).click();
    await page.getByRole("button", { name: "New task", exact: true }).click();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await page.getByLabel("Task title", { exact: true }).fill(taskTitle);
    await page
        .getByLabel("Task notes", { exact: true })
        .fill("Synthetic planning validation.");
    await page.getByRole("button", { name: "Add item", exact: true }).click();
    await page
        .getByLabel("Checklist item 1", { exact: true })
        .fill("Confirm access");
    await page.getByRole("button", { name: "Save task", exact: true }).click();
    await page
        .getByRole("textbox", { name: "Search tasks…", exact: true })
        .fill(taskTitle);
    const card = page.getByRole("article", { name: taskTitle, exact: true });
    await expect(card).toBeVisible();
    await page
        .getByLabel(`Status for ${taskTitle}`, { exact: true })
        .selectOption("in_progress");
    await expect(
        page
            .getByRole("region", { name: "In progress tasks", exact: true })
            .getByRole("article", { name: taskTitle, exact: true }),
    ).toBeVisible();
    await card
        .getByRole("checkbox", { name: "Confirm access", exact: true })
        .click();
    await expect(
        card.getByRole("checkbox", { name: "Confirm access", exact: true }),
    ).toBeChecked();
    await expect(card.getByRole("progressbar")).toHaveAttribute("value", "1");
    await expect(card).toHaveAttribute("aria-busy", "false");
    await card.dragTo(
        page.getByRole("region", { name: "Done tasks", exact: true }),
        { sourcePosition: { x: 15, y: 15 } },
    );
    await expect(
        page.getByLabel(`Status for ${taskTitle}`, { exact: true }),
    ).toHaveValue("done");
    await page.reload();
    await expect(
        page.getByLabel(`Status for ${taskTitle}`, { exact: true }),
    ).toHaveValue("done");
    await expect(
        card.getByRole("checkbox", { name: "Confirm access", exact: true }),
    ).toBeChecked();
    await page
        .getByRole("link", { name: "Calendar", exact: true })
        .first()
        .click();
    await page
        .getByRole("button", { name: "Add CRM note", exact: true })
        .click();
    await page.getByLabel("Note title", { exact: true }).fill(noteTitle);
    await page
        .getByLabel("CRM note", { exact: true })
        .fill("Synthetic note for the calendar persistence test.");
    await page.getByRole("button", { name: "Save note", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: noteTitle, exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(
        page.getByRole("heading", { name: noteTitle, exact: true }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: `Edit note ${noteTitle}`, exact: true })
        .click();
    await page
        .getByLabel("CRM note", { exact: true })
        .fill("Updated synthetic calendar note.");
    await page.getByRole("button", { name: "Save note", exact: true }).click();
    await expect(
        page.getByText("Updated synthetic calendar note.", { exact: true }),
    ).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await expect(page.locator(".calendar-grid")).toBeVisible();
    await expect(page.getByRole("dialog")).toHaveCount(0);
});
