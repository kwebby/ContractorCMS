/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { defineConfig, devices } from "@playwright/test";
export default defineConfig({
    testDir: "tests/Browser",
    fullyParallel: false,
    workers: 1,
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:8976",
        trace: "retain-on-failure",
    },
    outputDir: "output/playwright/results",
    reporter: "list",
    projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
