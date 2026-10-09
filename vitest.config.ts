/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { defineConfig } from "vitest/config";
export default defineConfig({
    test: {
        environment: "jsdom",
        setupFiles: ["resources/js/__tests__/setup.ts"],
        include: ["resources/js/__tests__/*.test.ts*"],
    },
});
