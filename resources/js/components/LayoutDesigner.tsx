/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useEffect, useRef } from "react";
import grapesjs, { type Editor } from "grapesjs";
import "grapesjs/dist/css/grapes.min.css";
export default function LayoutDesigner({
    initialProject,
    onChange,
}: {
    initialProject: any;
    onChange: (project: any) => void;
}) {
    const host = useRef<HTMLDivElement>(null);
    const instance = useRef<Editor | null>(null);
    const notify = useRef(onChange);
    notify.current = onChange;
    useEffect(() => {
        if (!host.current) return;
        const editor = grapesjs.init({
            container: host.current,
            height: "590px",
            width: "auto",
            storageManager: false,
            fromElement: false,
            noticeOnUnload: false,
            selectorManager: { componentFirst: true },
            canvas: { scripts: [] },
            blockManager: {
                blocks: [
                    {
                        id: "cms-content",
                        label: "Page content",
                        content: {
                            type: "cms-content",
                            tagName: "div",
                            content: "Your page content appears here.",
                        },
                    },
                    {
                        id: "cms-contact",
                        label: "Service request",
                        content: {
                            type: "cms-contact",
                            tagName: "div",
                            content: "Customer service request form",
                        },
                    },
                    {
                        id: "section",
                        label: "Section",
                        content: {
                            tagName: "section",
                            style: { padding: "48px 32px" },
                            components: [
                                {
                                    type: "text",
                                    tagName: "h2",
                                    content: "A service you can count on",
                                },
                                {
                                    type: "text",
                                    tagName: "p",
                                    content:
                                        "Describe your service and what customers can expect.",
                                },
                            ],
                        },
                    },
                    {
                        id: "heading",
                        label: "Heading",
                        content: {
                            type: "text",
                            tagName: "h2",
                            content: "Your heading",
                        },
                    },
                    {
                        id: "text",
                        label: "Text",
                        content: {
                            type: "text",
                            tagName: "p",
                            content: "Write a helpful description.",
                        },
                    },
                    {
                        id: "columns",
                        label: "Two columns",
                        content: {
                            tagName: "div",
                            style: {
                                display: "flex",
                                gap: "24px",
                                padding: "24px",
                            },
                            components: [
                                {
                                    tagName: "div",
                                    style: { width: "50%" },
                                    components: [
                                        {
                                            type: "text",
                                            tagName: "h3",
                                            content: "Your first point",
                                        },
                                    ],
                                },
                                {
                                    tagName: "div",
                                    style: { width: "50%" },
                                    components: [
                                        {
                                            type: "text",
                                            tagName: "h3",
                                            content: "Your second point",
                                        },
                                    ],
                                },
                            ],
                        },
                    },
                    {
                        id: "divider",
                        label: "Divider",
                        content: { tagName: "hr" },
                    },
                    {
                        id: "link",
                        label: "Link",
                        content: {
                            type: "link",
                            content: "Request a service",
                            attributes: { href: "#request-service" },
                        },
                    },
                ],
            },
        });
        editor.DomComponents.addType("cms-content", {
            model: {
                defaults: {
                    tagName: "section",
                    droppable: false,
                    editable: false,
                    content: "Page content appears here.",
                    style: { padding: "24px", background: "#f1f5fb" },
                },
            },
        });
        editor.DomComponents.addType("cms-contact", {
            model: {
                defaults: {
                    tagName: "section",
                    droppable: false,
                    editable: false,
                    content: "Customer service request form",
                    style: { padding: "24px", background: "#f1f5fb" },
                },
            },
        });
        editor.on("load", () =>
            editor.Panels.getButton("views", "open-blocks")?.set(
                "active",
                true,
            ),
        );
        instance.current = editor;
        if (initialProject?.pages?.length)
            editor.loadProjectData(initialProject);
        editor.on("update", () => notify.current(editor.getProjectData()));
        return () => {
            editor.destroy();
            instance.current = null;
        };
    }, []);
    return <div className="layout-designer" ref={host} />;
}
