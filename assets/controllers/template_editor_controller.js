import { Controller } from '@hotwired/stimulus';
import { Editor, Extension, Node } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import TextAlign from '@tiptap/extension-text-align';
import beautifyLib from 'js-beautify';
import { TextStyle, FontFamily, FontSize } from '@tiptap/extension-text-style';
import { Table, TableRow, TableHeader, TableCell } from '@tiptap/extension-table';
import Dropcursor from '@tiptap/extension-dropcursor';
import Gapcursor from '@tiptap/extension-gapcursor';

const optionalAttribute = (attributeName, htmlAttribute = attributeName) => ({
    default: null,
    parseHTML: (element) => element.getAttribute(htmlAttribute),
    renderHTML: (attributes) => attributes[attributeName] ? { [htmlAttribute]: attributes[attributeName] } : {},
});

const classAttribute = () => optionalAttribute('cssClass', 'class');
const styleAttribute = () => optionalAttribute('style', 'style');
const repeatAttribute = () => optionalAttribute('loop', 'data-repeat');
const repeatAsAttribute = () => optionalAttribute('loopAttr', 'data-repeat-as');
const repeatKeyAttribute = () => optionalAttribute('loopKey', 'data-repeat-key');
const conditionAttribute = () => optionalAttribute('condition', 'data-if');
const styleTokenAttribute = () => optionalAttribute('styleToken', 'data-template-style');

const styleAndClassAttributes = () => ({
    style: styleAttribute(),
    cssClass: classAttribute(),
});

const repeatAndConditionAttributes = () => ({
    loop: repeatAttribute(),
    loopAttr: repeatAsAttribute(),
    loopKey: repeatKeyAttribute(),
    condition: conditionAttribute(),
});

const TemplateTable = Table.extend({
    /**
     * Preserve inline style on table nodes.
     */
    addAttributes() {
        return {
            ...this.parent?.(),
            ...styleAndClassAttributes(),
        };
    },
});

const TemplateTableRow = TableRow.extend({
    /**
     * Preserve custom loop/condition metadata on table rows so visual mode keeps
     * template control attributes untouched.
     */
    addAttributes() {
        return {
            ...this.parent?.(),
            ...repeatAndConditionAttributes(),
            ...styleAndClassAttributes(),
        };
    },
});

const TemplateTableHeader = TableHeader.extend({
    /**
     * Preserve inline style on th nodes.
     */
    addAttributes() {
        return {
            ...this.parent?.(),
            ...repeatAndConditionAttributes(),
            ...styleAndClassAttributes(),
        };
    },
});

const TemplateTableCell = TableCell.extend({
    /**
     * Preserve inline style on td nodes.
     */
    addAttributes() {
        return {
            ...this.parent?.(),
            ...repeatAndConditionAttributes(),
            ...styleAndClassAttributes(),
        };
    },
});

const TemplateControlAttributes = Extension.create({
    /**
     * Keep loop/if control attributes on common block nodes and textStyle marks
     * so visual mode does not silently drop template metadata.
     */
    addGlobalAttributes() {
        return [
            {
                types: ['paragraph', 'heading', 'blockquote', 'listItem'],
                attributes: {
                    ...repeatAndConditionAttributes(),
                    styleToken: styleTokenAttribute(),
                    style: styleAttribute(),
                    cssClass: classAttribute(),
                },
            },
            {
                types: ['textStyle'],
                attributes: {
                    ...repeatAndConditionAttributes(),
                    styleToken: styleTokenAttribute(),
                    cssClass: classAttribute(),
                },
            },
        ];
    },
});

const TemplateDiv = Node.create({
    name: 'div',
    group: 'block',
    content: 'block*',
    defining: true,

    /**
     * Preserve div blocks and their relevant template/CSS attributes.
     */
    parseHTML() {
        return [{ tag: 'div' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', HTMLAttributes, 0];
    },

    addAttributes() {
        return {
            ...styleAndClassAttributes(),
            ...repeatAndConditionAttributes(),
        };
    },
});

const TemplateInlineControlSpan = Node.create({
    name: 'templateInlineControlSpan',
    inline: true,
    group: 'inline',
    content: 'inline*',

    /**
     * Preserve span wrappers that carry template control attributes.
     */
    parseHTML() {
        return [
            { tag: 'span[data-repeat]' },
            { tag: 'span[data-if]' },
        ];
    },

    renderHTML({ HTMLAttributes }) {
        return ['span', HTMLAttributes, 0];
    },

    addAttributes() {
        return {
            ...repeatAndConditionAttributes(),
            ...styleAndClassAttributes(),
        };
    },
});

export default class extends Controller {
    static targets = [
        'editor',
        'source',
        'code',
        'codeWrapper',
        'visualWrapper',
        'fileInput',
        'snippetSidebar',
        'toolbarHost',
        'beautifyButton',
        'modeToggle',
        'tabEdit',
        'tabPreview',
        'panelEdit',
        'panelPreview',
        'previewResult',
        'previewWarning',
        'tableActionButton',
        'tableToolsRow',
        'rowControlPanel',
        'rowRepeatEnabled',
        'rowRepeatCollection',
        'rowRepeatAs',
        'rowRepeatKey',
        'rowMetaBadge',
        'pdfParamsPanel',
    ];

    connect() {
        this.form = this.element.closest('form') || this.element;
        this.uploadUrl = this.form?.dataset.templatesUploadUrl || '';
        this.snippetsUrlTemplate = this.form?.dataset.templatesSnippetsUrl || '';
        this.previewRenderUrl = this.form?.dataset.templatesPreviewRenderUrl || '';
        this.previewPdfUrl = this.form?.dataset.templatesPreviewPdfUrl || '';
        this.templateTypeSelect = this.form?.querySelector('#template-type');
        this.snippets = [];
        this.codeDropListenersAttached = false;
        this.i18n = this.hasToolbarHostTarget ? this.toolbarHostTarget.dataset : {};

        const initialContent = this.sourceTarget?.value || '';
        this.hasProtectedTemplateComments = this.hasPseudoTwigComments(initialContent);
        const visualContent = this.prepareContentForVisual(initialContent);
        if (this.hasCodeTarget) {
            this.codeTarget.value = initialContent;
        }

        const advanced = this.isAdvancedContent(initialContent);
        this.initEditor(visualContent);
        this.initToolbar();

        if (advanced || this.hasProtectedTemplateComments) {
            this.enterCodeMode();
        } else {
            this.enterVisualMode();
        }

        this.refreshSnippets();
        this.updatePdfParamsVisibility();
        this.showEditTab();
        this.refreshToolbarState();
        this.previewPdfObjectUrl = null;
    }

    disconnect() {
        if (this.editorInstance) {
            this.editorInstance.destroy();
        }
        if (this.hasCodeWrapperTarget && this.codeDropListenersAttached) {
            this.codeWrapperTarget.removeEventListener('dragover', this.onCodeWrapperDragOver);
            this.codeWrapperTarget.removeEventListener('drop', this.onCodeWrapperDrop);
            this.codeDropListenersAttached = false;
        }
        this.revokePreviewPdfUrl();
    }

    beforeSubmitAction() {
        if (this.isCodeMode()) {
            this.sourceTarget.value = this.codeTarget.value;
        } else if (this.editorInstance) {
            this.sourceTarget.value = this.restoreContentFromVisual(this.editorInstance.getHTML());
        }
    }

    showEditTab(event = null) {
        if (event) {
            event.preventDefault();
        }
        if (this.hasPanelEditTarget) {
            this.panelEditTarget.classList.remove('d-none');
        }
        if (this.hasPanelPreviewTarget) {
            this.panelPreviewTarget.classList.add('d-none');
        }
        if (this.hasTabEditTarget) {
            this.tabEditTarget.classList.add('active');
        }
        if (this.hasTabPreviewTarget) {
            this.tabPreviewTarget.classList.remove('active');
        }
    }

    async showPreviewTab(event = null) {
        if (event) {
            event.preventDefault();
        }
        if (this.hasPanelEditTarget) {
            this.panelEditTarget.classList.add('d-none');
        }
        if (this.hasPanelPreviewTarget) {
            this.panelPreviewTarget.classList.remove('d-none');
        }
        if (this.hasTabEditTarget) {
            this.tabEditTarget.classList.remove('active');
        }
        if (this.hasTabPreviewTarget) {
            this.tabPreviewTarget.classList.add('active');
        }

        await this.renderPreview();
    }

    async renderPreview() {
        if (!this.previewRenderUrl) {
            return;
        }

        this.beforeSubmitAction();
        if (this.isCurrentTemplateTypePdf()) {
            await this.renderPdfPreview();
            return;
        }

        await this.renderHtmlPreview();
    }

    async renderHtmlPreview() {
        const formData = this.buildPreviewFormData();
        try {
            const response = await fetch(this.previewRenderUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            if (this.hasPreviewResultTarget) {
                this.revokePreviewPdfUrl();
                this.previewResultTarget.innerHTML = payload.html || '';
            }
            this.applyPreviewWarning(payload.warningText);
        } catch (error) {
            // ignore
        }
    }

    async renderPdfPreview() {
        if (!this.previewPdfUrl) {
            return;
        }

        try {
            const warningResponse = await fetch(this.previewRenderUrl, {
                method: 'POST',
                body: this.buildPreviewFormData(),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (warningResponse.ok) {
                const warningPayload = await warningResponse.json();
                this.applyPreviewWarning(warningPayload.warningText);
            }

            const response = await fetch(this.previewPdfUrl, {
                method: 'POST',
                body: this.buildPreviewFormData(),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                return;
            }
            const blob = await response.blob();
            this.revokePreviewPdfUrl();
            this.previewPdfObjectUrl = URL.createObjectURL(blob);
            if (this.hasPreviewResultTarget) {
                this.previewResultTarget.innerHTML = `<iframe src="${this.previewPdfObjectUrl}" class="template-preview-pdf-frame" style="width:100%;height:75vh;border:0;" title="PDF Preview"></iframe>`;
            }
        } catch (error) {
            // ignore
        }
    }

    buildPreviewFormData() {
        const formData = new FormData();
        formData.append('previewText', this.sourceTarget.value || '');
        this.appendPreviewContextToFormData(formData);

        return formData;
    }

    applyPreviewWarning(warningText) {
        if (!this.hasPreviewWarningTarget) {
            return;
        }
        if (warningText) {
            this.previewWarningTarget.textContent = warningText;
            this.previewWarningTarget.classList.remove('d-none');
        } else {
            this.previewWarningTarget.textContent = '';
            this.previewWarningTarget.classList.add('d-none');
        }
    }

    revokePreviewPdfUrl() {
        if (this.previewPdfObjectUrl) {
            URL.revokeObjectURL(this.previewPdfObjectUrl);
            this.previewPdfObjectUrl = null;
        }
    }

    isCurrentTemplateTypePdf() {
        if (!this.templateTypeSelect) {
            return false;
        }
        const selectedOption = this.templateTypeSelect.options[this.templateTypeSelect.selectedIndex];
        const typeName = selectedOption?.dataset?.templateTypeName || '';

        return typeName.includes('_PDF');
    }

    toggleCodeMode() {
        if (this.isCodeMode()) {
            this.enterVisualMode();
        } else {
            this.enterCodeMode();
        }
    }

    enterCodeMode() {
        const shouldSyncFromVisual = !this.hasProtectedTemplateComments || this.mode === 'visual';
        if (this.editorInstance && this.hasCodeTarget && shouldSyncFromVisual) {
            this.codeTarget.value = this.restoreContentFromVisual(this.editorInstance.getHTML());
        }
        if (this.hasVisualWrapperTarget) {
            this.visualWrapperTarget.classList.add('d-none');
        }
        if (this.hasCodeWrapperTarget) {
            this.codeWrapperTarget.classList.remove('d-none');
            if (!this.codeDropListenersAttached) {
                this.codeWrapperTarget.addEventListener('dragover', this.onCodeWrapperDragOver);
                this.codeWrapperTarget.addEventListener('drop', this.onCodeWrapperDrop);
                this.codeDropListenersAttached = true;
            }
        }
        if (this.hasModeToggleTarget) {
            const visualLabel = this.modeToggleTarget.dataset.visualLabel || 'Visual';
            this.modeToggleTarget.title = visualLabel;
            this.modeToggleTarget.setAttribute('aria-label', visualLabel);
            const icon = this.modeToggleTarget.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-code');
                icon.classList.add('fa-eye');
            }
        }
        if (this.hasBeautifyButtonTarget) {
            this.beautifyButtonTarget.classList.remove('d-none');
        }
        this.mode = 'code';
    }

    enterVisualMode() {
        // Tiptap drops comment nodes in complex structures (e.g. table bodies).
        // Keep templates with pseudo-twig comments in code mode to avoid data loss.
        if (this.hasProtectedTemplateComments) {
            window.alert(this.i18n.i18nProtectedComments || 'Dieses Template enthaelt Pseudo-Twig in HTML-Kommentaren und kann nur im Code-Modus bearbeitet werden.');
            return;
        }
        if (this.editorInstance && this.hasCodeTarget) {
            this.editorInstance.commands.setContent(this.prepareContentForVisual(this.codeTarget.value || ''), false);
        }
        if (this.hasCodeWrapperTarget) {
            this.codeWrapperTarget.classList.add('d-none');
        }
        if (this.hasVisualWrapperTarget) {
            this.visualWrapperTarget.classList.remove('d-none');
        }
        if (this.hasModeToggleTarget) {
            const codeLabel = this.modeToggleTarget.dataset.codeLabel || 'Code';
            this.modeToggleTarget.title = codeLabel;
            this.modeToggleTarget.setAttribute('aria-label', codeLabel);
            const icon = this.modeToggleTarget.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-code');
            }
        }
        if (this.hasBeautifyButtonTarget) {
            this.beautifyButtonTarget.classList.add('d-none');
        }
        this.mode = 'visual';
    }

    isCodeMode() {
        return this.mode === 'code';
    }

    initToolbar() {
        if (!this.hasToolbarHostTarget) {
            return;
        }
        this.toolbarCommandRegistry = this.buildToolbarCommandRegistry();
        this.toolbarConfig = this.buildToolbarConfig();
        this.renderToolbar();
    }

    buildToolbarConfig() {
        const t = (key, fallback) => this.getToolbarI18n(key, fallback);
        return [
            {
                type: 'group',
                items: [
                    { type: 'button', command: 'bold', icon: 'fas fa-bold', title: t('bold', 'Bold') },
                    { type: 'button', command: 'italic', icon: 'fas fa-italic', title: t('italic', 'Italic') },
                    { type: 'button', command: 'underline', icon: 'fas fa-underline', title: t('underline', 'Underline') },
                ],
            },
            {
                type: 'group',
                items: [
                    {
                        type: 'select',
                        command: 'fontFamily',
                        title: t('fontFamily', 'Font family'),
                        options: [
                            { value: '', label: t('fontFamily', 'Font family') },
                            { value: 'Arial, sans-serif', label: 'Arial' },
                            { value: "'Times New Roman', serif", label: 'Times New Roman' },
                            { value: "'Courier New', monospace", label: 'Courier New' },
                            { value: 'Georgia, serif', label: 'Georgia' },
                            { value: 'Verdana, sans-serif', label: 'Verdana' },
                        ],
                    },
                    {
                        type: 'select',
                        command: 'fontSize',
                        title: t('fontSize', 'Font size'),
                        options: [
                            { value: '', label: t('fontSize', 'Font size') },
                            { value: '10px', label: '10' },
                            { value: '12px', label: '12' },
                            { value: '14px', label: '14' },
                            { value: '16px', label: '16' },
                            { value: '18px', label: '18' },
                            { value: '22px', label: '22' },
                        ],
                    },
                ],
            },
            {
                type: 'group',
                items: [
                    { type: 'button', command: 'alignLeft', icon: 'fas fa-align-left', title: t('alignLeft', 'Align left') },
                    { type: 'button', command: 'alignCenter', icon: 'fas fa-align-center', title: t('alignCenter', 'Align center') },
                    { type: 'button', command: 'alignRight', icon: 'fas fa-align-right', title: t('alignRight', 'Align right') },
                ],
            },
            {
                type: 'group',
                items: [
                    { type: 'button', command: 'bulletList', icon: 'fas fa-list-ul', title: t('bullets', 'Bullet list') },
                    { type: 'button', command: 'orderedList', icon: 'fas fa-list-ol', title: t('ordered', 'Numbered list') },
                    { type: 'button', command: 'link', icon: 'fas fa-link', title: t('link', 'Insert link') },
                    { type: 'button', command: 'table', icon: 'fas fa-table', title: t('table', 'Insert table') },
                    { type: 'button', command: 'image', icon: 'fas fa-image', title: t('image', 'Insert image') },
                ],
            },
        ];
    }

    getToolbarI18n(key, fallback) {
        if (!this.hasToolbarHostTarget) {
            return fallback;
        }
        const map = {
            bold: 'i18nBold',
            italic: 'i18nItalic',
            underline: 'i18nUnderline',
            bullets: 'i18nBullets',
            ordered: 'i18nOrdered',
            link: 'i18nLink',
            table: 'i18nTable',
            image: 'i18nImage',
            fontFamily: 'i18nFontFamily',
            fontSize: 'i18nFontSize',
            alignLeft: 'i18nAlignLeft',
            alignCenter: 'i18nAlignCenter',
            alignRight: 'i18nAlignRight',
        };
        const dataKey = map[key];
        if (!dataKey) {
            return fallback;
        }
        return this.toolbarHostTarget.dataset[dataKey] || fallback;
    }

    buildToolbarCommandRegistry() {
        return {
            bold: { run: () => this.toggleBold(), isActive: () => this.editorInstance?.isActive('bold') },
            italic: { run: () => this.toggleItalic(), isActive: () => this.editorInstance?.isActive('italic') },
            underline: { run: () => this.toggleUnderline(), isActive: () => this.editorInstance?.isActive('underline') },
            bulletList: { run: () => this.toggleBulletList(), isActive: () => this.editorInstance?.isActive('bulletList') },
            orderedList: { run: () => this.toggleOrderedList(), isActive: () => this.editorInstance?.isActive('orderedList') },
            link: { run: () => this.addLink(), isActive: () => this.editorInstance?.isActive('link') },
            table: { run: () => this.addTable(), isActive: () => false },
            image: { run: () => this.triggerImageUpload(), isActive: () => false },
            alignLeft: { run: () => this.setTextAlignment('left'), isActive: () => this.editorInstance?.isActive({ textAlign: 'left' }) },
            alignCenter: { run: () => this.setTextAlignment('center'), isActive: () => this.editorInstance?.isActive({ textAlign: 'center' }) },
            alignRight: { run: () => this.setTextAlignment('right'), isActive: () => this.editorInstance?.isActive({ textAlign: 'right' }) },
        };
    }

    renderToolbar() {
        const host = this.toolbarHostTarget;
        host.innerHTML = '';

        this.toolbarConfig.forEach((group) => {
            if (group.type !== 'group') {
                return;
            }
            const groupEl = document.createElement('div');
            groupEl.className = 'template-editor-toolbar-group';
            groupEl.setAttribute('role', 'group');

            group.items.forEach((item) => {
                if (item.type === 'button') {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn btn-secondary btn-sm template-editor-btn';
                    button.dataset.templateCommand = item.command;
                    button.title = item.title;
                    button.innerHTML = `<i class="${item.icon}" aria-hidden="true"></i>`;
                    button.addEventListener('click', () => this.executeToolbarCommand(item.command));
                    groupEl.appendChild(button);
                    return;
                }

                if (item.type === 'select') {
                    const select = document.createElement('select');
                    select.className = 'form-select form-select-sm template-editor-select';
                    select.dataset.templateCommandSelect = item.command;
                    select.title = item.title;
                    item.options.forEach((optionDef) => {
                        const option = document.createElement('option');
                        option.value = optionDef.value;
                        option.textContent = optionDef.label;
                        select.appendChild(option);
                    });
                    select.addEventListener('change', (event) => {
                        const value = event.currentTarget.value || '';
                        this.handleToolbarSelect(item.command, value);
                    });
                    groupEl.appendChild(select);
                }
            });

            host.appendChild(groupEl);
        });
    }

    executeToolbarCommand(commandName) {
        if (!this.toolbarCommandRegistry || this.isCodeMode()) {
            return;
        }
        const command = this.toolbarCommandRegistry[commandName];
        if (!command || typeof command.run !== 'function') {
            return;
        }
        command.run();
        this.refreshToolbarState();
    }

    handleToolbarSelect(commandName, value) {
        if (this.isCodeMode()) {
            return;
        }
        if (commandName === 'fontFamily') {
            this.applyFontFamilyValue(value);
        } else if (commandName === 'fontSize') {
            this.applyFontSizeValue(value);
        }
        this.refreshToolbarState();
    }

    onTemplateTypeChange() {
        this.refreshSnippets();
        this.updatePdfParamsVisibility();
    }

    updatePdfParamsVisibility() {
        if (!this.hasPdfParamsPanelTarget) {
            return;
        }
        const isPdf = this.isCurrentTemplateTypePdf();
        this.pdfParamsPanelTarget.classList.toggle('d-none', !isPdf);
    }

    async refreshSnippets() {
        if (!this.snippetsUrlTemplate || !this.templateTypeSelect) {
            return;
        }
        const typeId = this.templateTypeSelect.value;
        if (!typeId) {
            return;
        }
        const url = this.snippetsUrlTemplate.replace('placeholder', typeId);
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                return;
            }
            this.snippets = await response.json();
            this.renderSnippetSidebar();
        } catch (error) {
            // ignore
        }
    }

    renderSnippetSidebar() {
        if (!this.hasSnippetSidebarTarget) {
            return;
        }

        const container = this.snippetSidebarTarget;
        container.innerHTML = '';
        const groups = this.groupSnippets();

        for (const [group, items] of groups.entries()) {
            const title = document.createElement('h6');
            title.className = 'mt-2 mb-2';
            title.textContent = group;
            container.appendChild(title);

            items.forEach((snippet) => {
                const item = document.createElement('div');
                item.className = 'snippet-item border rounded p-2 mb-2';
                item.draggable = true;
                item.dataset.content = snippet.content;
                item.dataset.complexity = snippet.complexity || 'simple';
                item.textContent = snippet.label;
                item.addEventListener('dragstart', (event) => this.handleSnippetDragStart(event));
                item.addEventListener('click', () => this.insertSnippetContent(snippet.content, snippet.complexity || 'simple'));
                container.appendChild(item);
            });
        }
    }

    groupSnippets() {
        const groups = new Map();
        this.snippets.forEach((snippet) => {
            const group = snippet.group || 'General';
            if (!groups.has(group)) {
                groups.set(group, []);
            }
            groups.get(group).push(snippet);
        });
        return groups;
    }

    handleSnippetDragStart(event) {
        const { content, complexity } = event.currentTarget.dataset;
        event.dataTransfer.setData('application/x-template-snippet', content || '');
        event.dataTransfer.setData('application/x-template-snippet-complexity', complexity || 'simple');
        event.dataTransfer.effectAllowed = 'copy';
    }

    handleSnippetDropOnCode(event) {
        const content = event.dataTransfer?.getData('application/x-template-snippet');
        if (!content || !this.hasCodeTarget) {
            return;
        }
        event.preventDefault();
        this.insertIntoTextarea(this.codeTarget, content);
    }

    insertSnippetContent(content, complexity) {
        if (complexity === 'advanced' && !this.isCodeMode()) {
            this.enterCodeMode();
        }

        if (this.isCodeMode()) {
            this.insertIntoTextarea(this.codeTarget, content);
        } else if (this.editorInstance) {
            if (this.isTableRowSnippet(content)) {
                this.applyRowSnippetToCurrentRow(content);
                return;
            }
            const normalizedContent = this.normalizeSnippetContentForInsert(content);
            this.editorInstance.chain().focus().insertContent(normalizedContent).run();
            this.refreshToolbarState();
        }
    }

    isTableRowSnippet(content) {
        return /^\s*<tr\b/i.test(content || '');
    }

    applyRowSnippetToCurrentRow(content) {
        if (!this.editorInstance || this.isCodeMode()) {
            return;
        }
        if (!this.editorInstance.isActive('tableRow')) {
            window.alert('Bitte zuerst den Cursor in die gewünschte Tabellenzeile setzen.');
            return;
        }

        const repeat = this.extractAttributeFromSnippet(content, 'data-repeat');
        const repeatAs = this.extractAttributeFromSnippet(content, 'data-repeat-as');
        const repeatKey = this.extractAttributeFromSnippet(content, 'data-repeat-key');
        const condition = this.extractAttributeFromSnippet(content, 'data-if');

        const attrs = this.editorInstance.getAttributes('tableRow');
        this.editorInstance.chain().focus().updateAttributes('tableRow', {
            ...attrs,
            loop: repeat || attrs.loop || null,
            loopAttr: repeatAs || attrs.loopAttr || null,
            loopKey: repeatKey || attrs.loopKey || null,
            condition: condition || attrs.condition || null,
        }).run();

        this.refreshToolbarState();
    }

    extractAttributeFromSnippet(content, attributeName) {
        if (!content || !attributeName) {
            return '';
        }
        const pattern = new RegExp(`${attributeName}=(["'])(.*?)\\1`, 'i');
        const match = content.match(pattern);
        return match && match[2] ? match[2].trim() : '';
    }

    normalizeSnippetContentForInsert(content) {
        if (!content) {
            return '';
        }
        // Prevent table parsing artifacts: whitespace between table tags can be
        // interpreted as extra text nodes/cells by the ProseMirror table parser.
        if (/^\s*<table\b/i.test(content)) {
            return content.replace(/>\s+</g, '><').trim();
        }
        return content;
    }

    addLink() {
        if (!this.editorInstance || this.isCodeMode()) {
            return;
        }
        const url = window.prompt('URL');
        if (!url) {
            return;
        }
        this.editorInstance.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }

    addTable() {
        if (!this.editorInstance) {
            return;
        }
        if (this.isCodeMode()) {
            this.insertIntoTextarea(
                this.codeTarget,
                '<table><tbody><tr><th>Header 1</th><th>Header 2</th></tr><tr><td>Cell 1</td><td>Cell 2</td></tr><tr><td>Cell 3</td><td>Cell 4</td></tr></tbody></table>\n'
            );
            return;
        }
        this.editorInstance.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
        this.refreshToolbarState();
    }

    addTableRow() {
        if (!this.editorInstance || this.isCodeMode() || !this.editorInstance.isActive('table')) {
            return;
        }
        this.editorInstance.chain().focus().addRowAfter().run();
        this.refreshToolbarState();
    }

    deleteTableRow() {
        if (!this.editorInstance || this.isCodeMode() || !this.editorInstance.isActive('table')) {
            return;
        }
        this.editorInstance.chain().focus().deleteRow().run();
        this.refreshToolbarState();
    }

    addTableColumn() {
        if (!this.editorInstance || this.isCodeMode() || !this.editorInstance.isActive('table')) {
            return;
        }
        this.editorInstance.chain().focus().addColumnAfter().run();
        this.refreshToolbarState();
    }

    deleteTableColumn() {
        if (!this.editorInstance || this.isCodeMode() || !this.editorInstance.isActive('table')) {
            return;
        }
        this.editorInstance.chain().focus().deleteColumn().run();
        this.refreshToolbarState();
    }

    deleteTable() {
        if (!this.editorInstance || this.isCodeMode() || !this.editorInstance.isActive('table')) {
            return;
        }
        this.editorInstance.chain().focus().deleteTable().run();
        this.refreshToolbarState();
    }

    applyRowControls() {
        if (!this.editorInstance || this.isCodeMode() || !this.editorInstance.isActive('tableRow')) {
            return;
        }

        const attrs = this.editorInstance.getAttributes('tableRow');
        const repeatEnabled = this.hasRowRepeatEnabledTarget && this.rowRepeatEnabledTarget.checked;

        const collection = this.hasRowRepeatCollectionTarget
            ? this.rowRepeatCollectionTarget.value.trim()
            : '';
        const repeatAs = this.hasRowRepeatAsTarget
            ? this.rowRepeatAsTarget.value.trim()
            : '';
        const repeatKey = this.hasRowRepeatKeyTarget
            ? this.rowRepeatKeyTarget.value.trim()
            : '';
        const hasRepeatInput = collection !== '' || repeatAs !== '' || repeatKey !== '';
        const repeatActive = repeatEnabled || hasRepeatInput;

        const nextAttrs = {
            ...attrs,
            // Keep partial input on the row so user can fill fields step by step.
            loop: repeatActive ? (collection || null) : null,
            loopAttr: repeatActive ? (repeatAs || null) : null,
            loopKey: repeatActive ? (repeatKey || null) : null,
            // Do not touch condition via table UI; keep backend support intact.
            condition: attrs.condition || null,
        };

        this.editorInstance.chain().focus().updateAttributes('tableRow', nextAttrs).run();
        this.refreshToolbarState();
    }

    clearRowRepeat() {
        if (!this.editorInstance || this.isCodeMode() || !this.editorInstance.isActive('tableRow')) {
            return;
        }
        const attrs = this.editorInstance.getAttributes('tableRow');
        this.editorInstance.chain().focus().updateAttributes('tableRow', {
            ...attrs,
            loop: null,
            loopAttr: null,
            loopKey: null,
        }).run();
        this.refreshToolbarState();
    }

    toggleBold() {
        if (this.editorInstance && !this.isCodeMode()) {
            this.editorInstance.chain().focus().toggleBold().run();
            this.refreshToolbarState();
        }
    }

    toggleItalic() {
        if (this.editorInstance && !this.isCodeMode()) {
            this.editorInstance.chain().focus().toggleItalic().run();
            this.refreshToolbarState();
        }
    }

    toggleUnderline() {
        if (this.editorInstance && !this.isCodeMode()) {
            this.editorInstance.chain().focus().toggleUnderline().run();
            this.refreshToolbarState();
        }
    }

    toggleBulletList() {
        if (this.editorInstance && !this.isCodeMode()) {
            this.editorInstance.chain().focus().toggleBulletList().run();
            this.refreshToolbarState();
        }
    }

    toggleOrderedList() {
        if (this.editorInstance && !this.isCodeMode()) {
            this.editorInstance.chain().focus().toggleOrderedList().run();
            this.refreshToolbarState();
        }
    }

    applyFontFamilyValue(value) {
        if (!this.editorInstance || this.isCodeMode()) {
            return;
        }
        if (value) {
            this.editorInstance.chain().focus().setFontFamily(value).run();
        } else {
            this.editorInstance.chain().focus().unsetFontFamily().run();
        }
    }

    applyFontSizeValue(value) {
        if (!this.editorInstance || this.isCodeMode()) {
            return;
        }
        if (value) {
            this.editorInstance.chain().focus().setFontSize(value).run();
        } else {
            this.editorInstance.chain().focus().unsetFontSize().run();
        }
    }

    setTextAlignment(alignment) {
        if (!this.editorInstance || this.isCodeMode()) {
            return;
        }
        this.editorInstance.chain().focus().setTextAlign(alignment).run();
    }

    beautifyCodeMode() {
        if (!this.hasCodeTarget || !this.isCodeMode()) {
            return;
        }

        const formatter = beautifyLib?.html
            || beautifyLib?.html_beautify
            || beautifyLib;

        if (typeof formatter !== 'function') {
            return;
        }

        try {
            this.codeTarget.value = formatter(this.codeTarget.value || '', {
                indent_size: 2,
                indent_char: ' ',
                preserve_newlines: true,
                max_preserve_newlines: 2,
                wrap_line_length: 120,
                end_with_newline: false,
                extra_liners: [],
            });
        } catch (error) {
            // ignore
        }
    }

    triggerImageUpload() {
        if (this.hasFileInputTarget) {
            this.fileInputTarget.click();
        }
    }

    async handleFileChange(event) {
        const file = event.target.files && event.target.files[0];
        if (!file) {
            return;
        }
        await this.uploadAndInsertImage(file);
        event.target.value = '';
    }

    initEditor(content) {
        if (!this.hasEditorTarget) {
            return;
        }

        this.editorInstance = new Editor({
            element: this.editorTarget,
            content,
            extensions: [
                TemplateDiv,
                TemplateInlineControlSpan,
                StarterKit.configure({
                    heading: { levels: [1, 2, 3, 4] },
                    link: false,
                    underline: false,
                    dropcursor: false,
                    gapcursor: false,
                }),
                Underline,
                Link.configure({ openOnClick: false }),
                Image,
                TextStyle,
                TemplateControlAttributes,
                TextAlign.configure({ types: ['heading', 'paragraph', 'div'] }),
                FontFamily.configure({ types: ['textStyle'] }),
                FontSize.configure({ types: ['textStyle'] }),
                TemplateTable.configure({ resizable: true }),
                TemplateTableRow,
                TemplateTableHeader,
                TemplateTableCell,
                Dropcursor,
                Gapcursor,
            ],
            editorProps: {
                attributes: { class: 'template-editor-content' },
                handleDrop: (view, event) => this.handleDrop(view, event),
                handlePaste: (view, event) => this.handlePaste(view, event),
            },
        });

        this.editorInstance.on('selectionUpdate', () => this.refreshToolbarState());
        this.editorInstance.on('transaction', () => this.refreshToolbarState());
    }

    async handleDrop(view, event) {
        const snippetContent = event.dataTransfer?.getData('application/x-template-snippet');
        if (snippetContent) {
            const complexity = event.dataTransfer?.getData('application/x-template-snippet-complexity') || 'simple';
            event.preventDefault();
            this.insertSnippetContent(snippetContent, complexity);
            return true;
        }

        const files = event.dataTransfer?.files;
        if (!files || files.length === 0) {
            return false;
        }
        const file = files[0];
        if (!file.type.startsWith('image/')) {
            return false;
        }
        event.preventDefault();
        await this.uploadAndInsertImage(file);
        return true;
    }

    async handlePaste(view, event) {
        const items = event.clipboardData?.items || [];
        for (const item of items) {
            if (item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (file) {
                    event.preventDefault();
                    await this.uploadAndInsertImage(file);
                    return true;
                }
            }
        }
        return false;
    }

    onCodeWrapperDragOver = (event) => {
        event.preventDefault();
    };

    onCodeWrapperDrop = (event) => {
        this.handleSnippetDropOnCode(event);
    };

    async uploadAndInsertImage(file) {
        if (!this.uploadUrl || !this.editorInstance || this.isCodeMode()) {
            return;
        }
        try {
            const formData = new FormData();
            formData.append('file', file);
            const response = await fetch(this.uploadUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            if (payload?.location) {
                this.editorInstance.chain().focus().setImage({ src: payload.location }).run();
            }
        } catch (error) {
            // ignore
        }
    }

    appendPreviewContextToFormData(formData) {
        this.getPreviewContextInputs().forEach((input) => {
            if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) {
                return;
            }
            formData.append(input.name, input.value);
        });
    }

    getPreviewContextInputs() {
        return Array.from(this.form.querySelectorAll('[name^="previewContext["]'));
    }

    insertIntoTextarea(textarea, text) {
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        const before = textarea.value.substring(0, start);
        const after = textarea.value.substring(end);
        textarea.value = `${before}${text}${after}`;
        const cursor = start + text.length;
        textarea.setSelectionRange(cursor, cursor);
        textarea.focus();
    }

    isAdvancedContent(content) {
        if (!content) {
            return false;
        }

        const hasPseudoTwigBlocks = /\[\%[\s\S]*?\%\]|\[\#[\s\S]*?\#\]/i.test(content);
        const hasMpdfDirectives = /<\/?(htmlpageheader|htmlpagefooter|sethtmlpageheader|sethtmlpagefooter)\b/i.test(content);

        return hasPseudoTwigBlocks || hasMpdfDirectives;
    }

    refreshToolbarState() {
        if (!this.editorInstance) {
            return;
        }
        const inTable = !this.isCodeMode() && this.editorInstance.isActive('table');
        if (this.hasTableToolsRowTarget) {
            this.tableToolsRowTarget.classList.toggle('d-none', !inTable);
        }
        if (this.hasRowControlPanelTarget) {
            this.rowControlPanelTarget.classList.toggle('d-none', !inTable);
        }
        this.updateToolbarActiveState();

        this.refreshTableRowControls();
    }

    updateToolbarActiveState() {
        if (!this.hasToolbarHostTarget || !this.toolbarCommandRegistry) {
            return;
        }
        this.toolbarHostTarget.querySelectorAll('[data-template-command]').forEach((button) => {
            const commandName = button.dataset.templateCommand;
            const config = this.toolbarCommandRegistry[commandName];
            const active = !this.isCodeMode() && config && typeof config.isActive === 'function'
                ? !!config.isActive()
                : false;
            button.classList.toggle('is-active', active);
        });

        const attrs = this.editorInstance.getAttributes('textStyle');
        const familySelect = this.toolbarHostTarget.querySelector('[data-template-command-select="fontFamily"]');
        const sizeSelect = this.toolbarHostTarget.querySelector('[data-template-command-select="fontSize"]');
        if (familySelect) {
            familySelect.value = attrs.fontFamily || '';
        }
        if (sizeSelect) {
            sizeSelect.value = attrs.fontSize || '';
        }
    }

    refreshTableRowControls() {
        if (!this.editorInstance || this.isCodeMode()) {
            return;
        }
        const rowActive = this.editorInstance.isActive('tableRow');
        const rowAttrs = rowActive ? this.editorInstance.getAttributes('tableRow') : {};

        if (this.hasRowControlPanelTarget) {
            this.rowControlPanelTarget.classList.toggle('opacity-50', !rowActive);
            this.rowControlPanelTarget.classList.toggle('pe-none', !rowActive);
        }

        if (this.hasRowRepeatEnabledTarget) {
            this.rowRepeatEnabledTarget.checked = !!(rowAttrs.loop || rowAttrs.loopAttr || rowAttrs.loopKey);
        }
        if (this.hasRowRepeatCollectionTarget) {
            this.rowRepeatCollectionTarget.value = rowAttrs.loop || '';
        }
        if (this.hasRowRepeatAsTarget) {
            this.rowRepeatAsTarget.value = rowAttrs.loopAttr || '';
        }
        if (this.hasRowRepeatKeyTarget) {
            this.rowRepeatKeyTarget.value = rowAttrs.loopKey || '';
        }
        if (this.hasRowMetaBadgeTarget) {
            if (!rowActive) {
                this.rowMetaBadgeTarget.textContent = '';
                this.rowMetaBadgeTarget.classList.add('d-none');
                return;
            }
            const parts = [];
            if (rowAttrs.loop && rowAttrs.loopAttr) {
                const keyPart = rowAttrs.loopKey ? `${rowAttrs.loopKey}, ` : '';
                parts.push(`Repeat: ${rowAttrs.loop} as ${keyPart}${rowAttrs.loopAttr}`);
            }
            if (parts.length > 0) {
                this.rowMetaBadgeTarget.textContent = parts.join(' | ');
                this.rowMetaBadgeTarget.classList.remove('d-none');
            } else {
                this.rowMetaBadgeTarget.textContent = '';
                this.rowMetaBadgeTarget.classList.add('d-none');
            }
        }
    }

    hasPseudoTwigComments(content) {
        if (!content) {
            return false;
        }

        return /<!--[\s\S]*?(\[\%[\s\S]*?\%\]|\[\[[\s\S]*?\]\]|\[\#[\s\S]*?\#\])[\s\S]*?-->/i.test(content);
    }

    encodeTemplateCommentsForVisual(content) {
        if (!content) {
            return '';
        }
        return content.replace(/<!--([\s\S]*?)-->/g, (match, inner) => {
            if (!/\[\%|\%\]|\[\[|\]\]|\[\#|\#\]/.test(inner)) {
                return match;
            }
            const encoded = this.base64Encode(inner.trim());
            return `<span data-template-comment="${encoded}" class="template-editor-comment-token" contenteditable="false"></span>`;
        });
    }

    decodeTemplateCommentsFromVisual(content) {
        if (!content) {
            return '';
        }
        return content
            .replace(/<span[^>]*data-template-comment="([^"]+)"[^>]*><\/span>/g, (match, encoded) => `<!-- ${this.base64Decode(encoded)} -->`)
            .replace(/<span[^>]*data-template-comment='([^']+)'[^>]*><\/span>/g, (match, encoded) => `<!-- ${this.base64Decode(encoded)} -->`);
    }

    prepareContentForVisual(content) {
        const normalized = this.normalizeLegacyDocumentHtml(content);
        return this.encodeStyleBlocksForVisual(this.encodeTemplateCommentsForVisual(normalized));
    }

    restoreContentFromVisual(content) {
        return this.decodeStyleBlocksFromVisual(this.decodeTemplateCommentsFromVisual(content));
    }

    normalizeLegacyDocumentHtml(content) {
        if (!content) {
            return '';
        }

        const styles = [];
        let normalized = content.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, (match) => {
            styles.push(match.trim());
            return '';
        });

        const bodyMatch = normalized.match(/<body\b[^>]*>([\s\S]*?)<\/body>/i);
        if (bodyMatch) {
            normalized = bodyMatch[1];
        }

        normalized = normalized.replace(/<\/?(html|head|body)\b[^>]*>/gi, '').trim();

        if (styles.length > 0) {
            normalized = `${styles.join('\n')}\n${normalized}`.trim();
        }

        return normalized;
    }

    encodeStyleBlocksForVisual(content) {
        if (!content) {
            return '';
        }
        return content.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, (match) => {
            const encoded = this.base64Encode(match);
            return `<p data-template-style="${encoded}" class="template-editor-style-token">CSS</p>`;
        });
    }

    decodeStyleBlocksFromVisual(content) {
        if (!content) {
            return '';
        }
        return content
            .replace(/<([a-zA-Z][\w:-]*)[^>]*data-template-style="([^"]+)"[^>]*>[\s\S]*?<\/\1>/gi, (match, tagName, encoded) => this.base64Decode(encoded))
            .replace(/<([a-zA-Z][\w:-]*)[^>]*data-template-style='([^']+)'[^>]*>[\s\S]*?<\/\1>/gi, (match, tagName, encoded) => this.base64Decode(encoded));
    }

    base64Encode(input) {
        try {
            return btoa(unescape(encodeURIComponent(input)));
        } catch (error) {
            return '';
        }
    }

    base64Decode(input) {
        try {
            return decodeURIComponent(escape(atob(input)));
        } catch (error) {
            return '';
        }
    }

}
