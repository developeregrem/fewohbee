/**
 * Lightweight Tiptap HTML editor for simple content editing (emails, PDFs).
 * No code mode, no variables, no snippets — just formatting.
 * Shared between template_editor_controller and reservations_controller.
 */
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyle, FontFamily, FontSize } from '@tiptap/extension-text-style';
import { Table, TableRow, TableHeader, TableCell } from '@tiptap/extension-table';
import { Extension } from '@tiptap/core';

const optionalAttribute = (attributeName, htmlAttribute = attributeName) => ({
    default: null,
    parseHTML: (element) => element.getAttribute(htmlAttribute),
    renderHTML: (attributes) => attributes[attributeName] ? { [htmlAttribute]: attributes[attributeName] } : {},
});

const styleAttribute = () => optionalAttribute('style', 'style');
const classAttribute = () => optionalAttribute('cssClass', 'class');
// Needed so encoded <style> marker nodes survive Tiptap round-trips.
const styleTokenAttribute = () => optionalAttribute('styleToken', 'data-template-style');

const PreserveStyleAndClass = Extension.create({
    /**
     * Keep style/class attributes on common nodes so inline formatting and
     * table styling remain visible in visual mode and survive round-trips.
     */
    addGlobalAttributes() {
        return [
            {
                types: ['paragraph', 'heading', 'blockquote', 'listItem', 'table', 'tableRow', 'tableCell', 'tableHeader'],
                attributes: {
                    styleToken: styleTokenAttribute(),
                    style: styleAttribute(),
                    cssClass: classAttribute(),
                },
            },
            {
                types: ['textStyle'],
                attributes: {
                    cssClass: classAttribute(),
                },
            },
        ];
    },
});

const TOOLBAR_BUTTONS = [
    { command: 'bold', icon: 'fas fa-bold', action: (ed) => ed.chain().focus().toggleBold().run(), isActive: (ed) => ed.isActive('bold') },
    { command: 'italic', icon: 'fas fa-italic', action: (ed) => ed.chain().focus().toggleItalic().run(), isActive: (ed) => ed.isActive('italic') },
    { command: 'underline', icon: 'fas fa-underline', action: (ed) => ed.chain().focus().toggleUnderline().run(), isActive: (ed) => ed.isActive('underline') },
    { sep: true },
    { command: 'alignLeft', icon: 'fas fa-align-left', action: (ed) => ed.chain().focus().setTextAlign('left').run(), isActive: (ed) => ed.isActive({ textAlign: 'left' }) },
    { command: 'alignCenter', icon: 'fas fa-align-center', action: (ed) => ed.chain().focus().setTextAlign('center').run(), isActive: (ed) => ed.isActive({ textAlign: 'center' }) },
    { command: 'alignRight', icon: 'fas fa-align-right', action: (ed) => ed.chain().focus().setTextAlign('right').run(), isActive: (ed) => ed.isActive({ textAlign: 'right' }) },
    { sep: true },
    { command: 'bulletList', icon: 'fas fa-list-ul', action: (ed) => ed.chain().focus().toggleBulletList().run(), isActive: (ed) => ed.isActive('bulletList') },
    { command: 'orderedList', icon: 'fas fa-list-ol', action: (ed) => ed.chain().focus().toggleOrderedList().run(), isActive: (ed) => ed.isActive('orderedList') },
    { command: 'link', icon: 'fas fa-link', action: (ed) => {
        const url = prompt('URL:');
        if (url) {
            ed.chain().focus().setLink({ href: url }).run();
        }
    }, isActive: (ed) => ed.isActive('link') },
];

function base64Encode(input) {
    try {
        return btoa(unescape(encodeURIComponent(input)));
    } catch (error) {
        return '';
    }
}

function base64Decode(input) {
    try {
        return decodeURIComponent(escape(atob(input)));
    } catch (error) {
        return '';
    }
}

/**
 * Tiptap removes <style> tags from editable content.
 * Persist them as hidden marker nodes during editing and restore them on export.
 */
function encodeStyleBlocksForVisual(content) {
    if (!content) {
        return '';
    }
    return content.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, (match) => {
        const encoded = base64Encode(match);
        return `<p data-template-style="${encoded}" class="template-editor-style-token">CSS</p>`;
    });
}

function decodeStyleBlocksFromVisual(content) {
    if (!content) {
        return '';
    }
    return content
        .replace(/<([a-zA-Z][\w:-]*)[^>]*data-template-style="([^"]+)"[^>]*>[\s\S]*?<\/\1>/gi, (match, tagName, encoded) => base64Decode(encoded))
        .replace(/<([a-zA-Z][\w:-]*)[^>]*data-template-style='([^']+)'[^>]*>[\s\S]*?<\/\1>/gi, (match, tagName, encoded) => base64Decode(encoded));
}

/**
 * Creates a Tiptap editor instance with a simple toolbar.
 *
 * @param {HTMLElement} editorContainer - The element to mount the editor into
 * @param {string} initialContent - HTML content to load
 * @param {Object} [options] - Optional settings
 * @param {HTMLElement} [options.toolbarContainer] - Where to render the toolbar (defaults to before editorContainer)
 * @param {Function} [options.onUpdate] - Called with HTML string on every change
 * @returns {{ editor: Editor, getHTML: () => string, destroy: () => void }}
 */
export function createSimpleHtmlEditor(editorContainer, initialContent, options = {}) {
    const labels = {
        fontFamily: options?.labels?.fontFamily || 'Font',
        fontSize: options?.labels?.fontSize || 'Size',
    };

    const editor = new Editor({
        element: editorContainer,
        extensions: [
            StarterKit.configure({ heading: false }),
            Underline,
            Link.configure({ openOnClick: false }),
            Image,
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            TextStyle,
            PreserveStyleAndClass,
            FontFamily,
            FontSize,
            Table.configure({ resizable: false }),
            TableRow,
            TableHeader,
            TableCell,
        ],
        content: encodeStyleBlocksForVisual(initialContent),
        onUpdate: ({ editor: ed }) => {
            options.onUpdate?.(decodeStyleBlocksFromVisual(ed.getHTML()));
        },
    });

    // Build toolbar
    const toolbar = document.createElement('div');
    toolbar.className = 'simple-html-editor-toolbar d-flex flex-wrap gap-1 mb-2 p-1 border rounded';
    toolbar.style.background = 'var(--bs-tertiary-bg)';

    // Font family select
    const fontFamilySelect = document.createElement('select');
    fontFamilySelect.className = 'form-select form-select-sm w-auto';
    fontFamilySelect.style.fontSize = '0.78rem';
    [
        { value: '', label: labels.fontFamily },
        { value: 'Arial, sans-serif', label: 'Arial' },
        { value: "'Times New Roman', serif", label: 'Times New Roman' },
        { value: "'Courier New', monospace", label: 'Courier New' },
        { value: 'Georgia, serif', label: 'Georgia' },
        { value: 'Verdana, sans-serif', label: 'Verdana' },
    ].forEach((opt) => {
        const o = document.createElement('option');
        o.value = opt.value;
        o.textContent = opt.label;
        fontFamilySelect.appendChild(o);
    });
    fontFamilySelect.addEventListener('change', () => {
        const v = fontFamilySelect.value;
        if (v) { editor.chain().focus().setFontFamily(v).run(); }
        else { editor.chain().focus().unsetFontFamily().run(); }
    });
    toolbar.appendChild(fontFamilySelect);

    // Font size select
    const fontSizeSelect = document.createElement('select');
    fontSizeSelect.className = 'form-select form-select-sm w-auto pe-5';
    fontSizeSelect.style.fontSize = '0.78rem';
    [
        { value: '', label: labels.fontSize },
        { value: '10px', label: '10' },
        { value: '12px', label: '12' },
        { value: '14px', label: '14' },
        { value: '16px', label: '16' },
        { value: '18px', label: '18' },
        { value: '22px', label: '22' },
    ].forEach((opt) => {
        const o = document.createElement('option');
        o.value = opt.value;
        o.textContent = opt.label;
        fontSizeSelect.appendChild(o);
    });
    fontSizeSelect.addEventListener('change', () => {
        const v = fontSizeSelect.value;
        if (v) { editor.chain().focus().setFontSize(v).run(); }
        else { editor.chain().focus().unsetFontSize().run(); }
    });
    toolbar.appendChild(fontSizeSelect);

    const sep0 = document.createElement('div');
    sep0.className = 'vr mx-1';
    toolbar.appendChild(sep0);

    TOOLBAR_BUTTONS.forEach((btn) => {
        if (btn.sep) {
            const sep = document.createElement('div');
            sep.className = 'vr mx-1';
            toolbar.appendChild(sep);
            return;
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-secondary btn-sm';
        button.style.lineHeight = '1';
        button.innerHTML = `<i class="${btn.icon}" aria-hidden="true"></i>`;
        button.addEventListener('click', () => btn.action(editor));
        toolbar.appendChild(button);
    });

    // Insert toolbar
    const toolbarTarget = options.toolbarContainer || editorContainer;
    toolbarTarget.parentNode.insertBefore(toolbar, toolbarTarget);

    // Refresh active states on selection change
    const refreshToolbar = () => {
        toolbar.querySelectorAll('button').forEach((button, i) => {
            const btnDef = TOOLBAR_BUTTONS.filter((b) => !b.sep)[i];
            if (btnDef) {
                button.classList.toggle('active', btnDef.isActive(editor));
                button.style.background = btnDef.isActive(editor) ? 'var(--bs-primary)' : '';
                button.style.color = btnDef.isActive(editor) ? 'var(--bs-white)' : '';
            }
        });
        fontFamilySelect.value = editor.getAttributes('textStyle').fontFamily || '';
        fontSizeSelect.value = editor.getAttributes('textStyle').fontSize || '';
    };
    editor.on('selectionUpdate', refreshToolbar);
    editor.on('transaction', refreshToolbar);

    return {
        editor,
        getHTML: () => decodeStyleBlocksFromVisual(editor.getHTML()),
        destroy: () => {
            toolbar.remove();
            editor.destroy();
        },
    };
}
