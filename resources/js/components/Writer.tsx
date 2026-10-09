/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useCreateBlockNote } from "@blocknote/react";
import { BlockNoteView } from "@blocknote/mantine";
import { BlockNoteSchema, defaultBlockSpecs } from "@blocknote/core";
import "@blocknote/core/fonts/inter.css";
import "@blocknote/mantine/style.css";
import { api } from "../api";
const {
    paragraph,
    heading,
    bulletListItem,
    numberedListItem,
    checkListItem,
    quote,
    table,
    image,
} = defaultBlockSpecs;
const schema = BlockNoteSchema.create({
    blockSpecs: {
        paragraph,
        heading,
        bulletListItem,
        numberedListItem,
        checkListItem,
        quote,
        table,
        image,
    },
});
export default function Writer({
    initialContent,
    onChange,
}: {
    initialContent: any[];
    onChange: (content: any[]) => void;
}) {
    const editor = useCreateBlockNote({
        schema,
        initialContent: initialContent?.length ? initialContent : undefined,
        uploadFile: async (file) => {
            const form = new FormData();
            form.append("file", file);
            form.append("visibility", "draft");
            const r = await api("files", "POST", form);
            return `/files/${r.data.id}`;
        },
    });
    return (
        <div className="writer">
            <BlockNoteView
                editor={editor}
                theme="light"
                onChange={() => onChange(editor.document)}
                sideMenu={true}
            />
        </div>
    );
}
