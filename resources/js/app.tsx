/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import "../css/app.css";
import "@fontsource-variable/dm-sans";
import { createInertiaApp } from "@inertiajs/react";
import { createRoot } from "react-dom/client";
const pages = import.meta.glob("./pages/*.tsx");
createInertiaApp({
    title: (title) => (title ? `${title} · ContractorCMS` : "ContractorCMS"),
    resolve: async (name) => {
        const loader = pages[`./pages/${name}.tsx`];
        if (!loader) throw new Error(`Unknown page: ${name}`);
        return loader() as any;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: "#2563eb" },
});
