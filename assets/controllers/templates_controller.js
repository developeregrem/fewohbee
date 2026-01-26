import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.modalContent = document.getElementById('modal-content-ajax');
        const modalDialog = document.querySelector('#modalCenter .modal-dialog');

        if (modalDialog && !modalDialog.classList.contains('modal-lg')) {
            modalDialog.classList.add('modal-lg');
        }
        this.registerTinyMceFocusFix();
        this.observeModalContent();
        this.initTinyMceEditor();
    }

    disconnect() {
        if (this.modalObserver) {
            this.modalObserver.disconnect();
        }
    }

    registerTinyMceFocusFix() {
        if (window.templatesTinymceFocusFix) {
            return;
        }
        document.addEventListener('focusin', (event) => {
            if (event.target.closest('.tox-tinymce, .tox-tinymce-aux, .moxman-window, .tam-assetmanager-root') !== null) {
                event.stopImmediatePropagation();
            }
        });
        window.templatesTinymceFocusFix = true;
    }

    beforeSubmitAction(event) {
        const form = event.target.closest('form');
        if (!form) {
            return;
        }
        this.syncEditorContent(form);
    }

    changeTemplateTypeAction(event) {
        const select = event.currentTarget;
        const urlTemplate = select.dataset.templateUrl || select.closest('form')?.dataset.templatesTemplateUrl;
        if (!urlTemplate || typeof tinymce === 'undefined') {
            return;
        }
        const newTemplateUrl = urlTemplate.replace('placeholder', select.value);
        const editor = tinymce.activeEditor || tinymce.get('editor1');
        if (editor) {
            if (editor.options && typeof editor.options.set === 'function') {
                editor.options.set('templates', newTemplateUrl);
            }
            editor.settings = editor.settings || {};
            editor.settings.templates = newTemplateUrl;
        }
    }

    initTinyMceEditor() {
        if (typeof tinymce === 'undefined') {
            return;
        }
        const editorNode = document.getElementById('editor1');
        if (!editorNode) {
            return;
        }
        const existing = tinymce.get('editor1');
        if (existing && editorNode.dataset.tinymceInitialized === 'true') {
            return;
        }
        if (existing) {
            existing.destroy();
        }
        if (editorNode.dataset.tinymceInitializing === 'true') {
            return;
        }
        editorNode.dataset.tinymceInitializing = 'true';
        const form = editorNode.closest('form');
        const templateSelect = form?.querySelector('#template-type');
        const urlTemplate = templateSelect?.dataset.templateUrl || form?.dataset.templatesTemplateUrl;
        const uploadUrl = form?.dataset.templatesUploadUrl;
        const language = form?.dataset.templatesLocale || document.documentElement.lang || 'de';
        const basepath = form?.dataset.templatesBasepath || '';
        const templatesUrl = urlTemplate && templateSelect
            ? urlTemplate.replace('placeholder', templateSelect.value)
            : urlTemplate;

        tinymce.init({
            selector: '#editor1',
            language,
            toolbar_mode: 'sliding',
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview', 'anchor',
                'searchreplace', 'visualblocks', 'code', 'fullscreen', 'image',
                'insertdatetime', 'media', 'table', 'code', 'help', 'wordcount', 'table', 'template',
            ],
            toolbar: 'undo redo | fontselect fontsizeselect | bold italic underline forecolor backcolor image | template | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent |  table | removeformat | code | fullscreen preview | help',
            menubar: false,
            images_upload_url: uploadUrl,
            relative_urls: false,
            protect: [
                /\{\%[\s\S]*?%\}/g,
                /\{\#[\s\S]*?#\}/g,
            ],
            custom_elements: 'htmlpageheader,htmlpagefooter,sethtmlpageheader,sethtmlpagefooter',
            extended_valid_elements: 'htmlpageheader[name|class|style],htmlpagefooter[name|class|style],sethtmlpageheader[name|value|show-this-page],sethtmlpagefooter[name|value|page]',
            templates: templatesUrl,
            entity_encoding: 'raw',
            branding: false,
            promotion: false,
            valid_children: '+body[style|htmlpageheader|htmlpagefooter|sethtmlpageheader|sethtmlpagefooter],+htmlpageheader[div|span|p|br|#text],+htmlpagefooter[div|span|p|br|#text]',
            content_css: [
                `${basepath}/resources/css/editor.css`,
            ],
            fontsize_formats: '8pt 9pt 10pt 11pt 12pt 14pt 16pt 18pt 24pt 36pt',
            noneditable_class: 'mceNonEditable2',
            setup: () => {
                editorNode.dataset.tinymceInitialized = 'true';
                editorNode.dataset.tinymceInitializing = 'false';
            },
        });

        if (templateSelect && !templateSelect.dataset.templateEnhanced) {
            templateSelect.dataset.templateEnhanced = 'true';
            templateSelect.addEventListener('change', (e) => this.changeTemplateTypeAction(e));
        }
    }

    syncEditorContent(form) {
        if (typeof tinymce === 'undefined') {
            return;
        }
        const editor = tinymce.get('editor1');
        if (editor) {
            const textarea = form.querySelector('#editor1');
            if (textarea) {
                textarea.value = editor.getContent();
            }
        }
    }

    observeModalContent() {
        if (!this.modalContent) {
            return;
        }
        const observer = new MutationObserver(() => {
            this.initTinyMceEditor();
        });
        observer.observe(this.modalContent, { childList: true, subtree: true });
        this.modalObserver = observer;
    }
}
