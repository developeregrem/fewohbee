<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.23',
    ],
    '@simplewebauthn/browser' => [
        'version' => '13.3.0',
    ],
    'js-beautify' => [
        'version' => '1.15.4',
    ],
    '@tiptap/core' => [
        'version' => '3.22.3',
    ],
    '@tiptap/starter-kit' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-image' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-link' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-text-align' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-table' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-underline' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-text-style' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-dropcursor' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-gapcursor' => [
        'version' => '3.22.3',
    ],
    '@tiptap/pm/transform' => [
        'version' => '3.22.3',
    ],
    '@tiptap/pm/commands' => [
        'version' => '3.22.3',
    ],
    '@tiptap/pm/state' => [
        'version' => '3.22.3',
    ],
    '@tiptap/pm/model' => [
        'version' => '3.22.3',
    ],
    '@tiptap/pm/schema-list' => [
        'version' => '3.22.3',
    ],
    '@tiptap/pm/view' => [
        'version' => '3.22.3',
    ],
    '@tiptap/pm/keymap' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-blockquote' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-bold' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-code' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-code-block' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-document' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-hard-break' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-heading' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-horizontal-rule' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-italic' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-list' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-paragraph' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-strike' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-text' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extensions' => [
        'version' => '3.22.3',
    ],
    'linkifyjs' => [
        'version' => '4.3.2',
    ],
    '@tiptap/pm/tables' => [
        'version' => '3.22.3',
    ],
    'prosemirror-transform' => [
        'version' => '1.12.0',
    ],
    'prosemirror-commands' => [
        'version' => '1.7.1',
    ],
    'prosemirror-state' => [
        'version' => '1.4.4',
    ],
    'prosemirror-model' => [
        'version' => '1.25.4',
    ],
    'prosemirror-schema-list' => [
        'version' => '1.5.1',
    ],
    'prosemirror-view' => [
        'version' => '1.41.8',
    ],
    'prosemirror-keymap' => [
        'version' => '1.2.3',
    ],
    '@tiptap/core/jsx-runtime' => [
        'version' => '3.22.3',
    ],
    '@tiptap/pm/dropcursor' => [
        'version' => '3.22.3',
    ],
    '@tiptap/pm/gapcursor' => [
        'version' => '3.22.3',
    ],
    '@tiptap/pm/history' => [
        'version' => '3.22.3',
    ],
    'prosemirror-tables' => [
        'version' => '1.8.5',
    ],
    'orderedmap' => [
        'version' => '2.1.1',
    ],
    'w3c-keyname' => [
        'version' => '2.2.8',
    ],
    'prosemirror-dropcursor' => [
        'version' => '1.8.2',
    ],
    'prosemirror-gapcursor' => [
        'version' => '1.4.1',
    ],
    'prosemirror-history' => [
        'version' => '1.5.0',
    ],
    'prosemirror-view/style/prosemirror.min.css' => [
        'version' => '1.41.8',
        'type' => 'css',
    ],
    'prosemirror-tables/style/tables.min.css' => [
        'version' => '1.8.5',
        'type' => 'css',
    ],
    'rope-sequence' => [
        'version' => '1.3.4',
    ],
    'prosemirror-gapcursor/style/gapcursor.min.css' => [
        'version' => '1.4.1',
        'type' => 'css',
    ],
    '@tiptap/extension-table-row' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-table-cell' => [
        'version' => '3.22.3',
    ],
    '@tiptap/extension-table-header' => [
        'version' => '3.22.3',
    ],
    'codemirror' => [
        'version' => '6.0.2',
    ],
    '@codemirror/lang-html' => [
        'version' => '6.4.11',
    ],
    '@codemirror/autocomplete' => [
        'version' => '6.20.1',
    ],
    '@codemirror/view' => [
        'version' => '6.41.0',
    ],
    '@codemirror/state' => [
        'version' => '6.6.0',
    ],
    '@codemirror/language' => [
        'version' => '6.12.3',
    ],
    '@codemirror/commands' => [
        'version' => '6.10.3',
    ],
    '@codemirror/search' => [
        'version' => '6.6.0',
    ],
    '@codemirror/lint' => [
        'version' => '6.9.5',
    ],
    '@lezer/html' => [
        'version' => '1.3.13',
    ],
    '@codemirror/lang-css' => [
        'version' => '6.3.1',
    ],
    '@codemirror/lang-javascript' => [
        'version' => '6.2.5',
    ],
    'style-mod' => [
        'version' => '4.1.3',
    ],
    'crelt' => [
        'version' => '1.0.6',
    ],
    '@marijn/find-cluster-break' => [
        'version' => '1.0.2',
    ],
    '@lezer/common' => [
        'version' => '1.5.2',
    ],
    '@lezer/highlight' => [
        'version' => '1.2.3',
    ],
    '@lezer/lr' => [
        'version' => '1.4.9',
    ],
    '@lezer/css' => [
        'version' => '1.3.3',
    ],
    '@lezer/javascript' => [
        'version' => '1.5.4',
    ],
];
