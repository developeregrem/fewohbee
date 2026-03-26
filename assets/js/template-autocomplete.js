/**
 * CodeMirror autocomplete extension for the template code editor.
 *
 * Provides context-aware variable suggestions inside [[ … ]] placeholders,
 * automatic data-repeat loop generation for collection properties, and
 * scope-aware filtering based on active loops in the document.
 *
 * Usage:
 *   import { templateAutocomplete } from './template-autocomplete';
 *   const ext = templateAutocomplete({ schemaUrl: '/settings/templates/schema/1' });
 *   // pass `ext` as a CodeMirror extension
 */

import { autocompletion } from '@codemirror/autocomplete';

/**
 * Create the autocomplete CodeMirror extension for template variables.
 *
 * @param {Object}  opts
 * @param {string}  opts.schemaUrl       – Backend URL that returns the JSON schema tree
 * @returns {import('@codemirror/state').Extension}
 */
export function templateAutocomplete({ schemaUrl }) {
    let schema = null;
    let schemaPromise = null;

    /**
     * Lazy-load the schema from the backend (fetched once, then cached).
     */
    function ensureSchema() {
        if (schema) return Promise.resolve(schema);
        if (schemaPromise) return schemaPromise;
        schemaPromise = fetch(schemaUrl)
            .then(r => r.json())
            .then(data => { schema = data; return data; })
            .catch(() => { schema = {}; return schema; });
        return schemaPromise;
    }

    // Pre-fetch on init
    ensureSchema();

    return autocompletion({
        override: [
            async (ctx) => completionSource(ctx, await ensureSchema()),
        ],
        activateOnTyping: true,
    });
}

// ─── Core completion source ───────────────────────────────────────────────────

/**
 * CodeMirror completion source that provides variable suggestions
 * inside [[ … ]] blocks, with scope-awareness for data-repeat loops.
 */
function completionSource(ctx, schema) {
    if (!schema || Object.keys(schema).length === 0) return null;

    const pos = ctx.pos;
    const docText = ctx.state.doc.toString();

    // Find the [[ token that the cursor is currently inside of
    const context = findBracketContext(docText, pos);
    if (!context) return null;

    // Parse the expression typed so far (e.g. "reservation1.booker.first")
    const expr = context.expression;
    const parts = expr.split('.');

    // Build scope: global variables + active loop variables at cursor position
    const scopeVars = buildScope(docText, pos, schema);

    // Determine if user typed a partial last segment (for filtering)
    const lastPart = parts[parts.length - 1];
    const pathParts = parts.slice(0, -1);

    // Resolve the schema node for the path before the last dot
    const node = resolveSchemaPath(pathParts, scopeVars);
    if (!node) return null;

    // Collect completions from the resolved node
    const properties = node.properties || node;
    const options = [];

    for (const [key, def] of Object.entries(properties)) {
        const isCollection = def.type === 'collection';
        const isArray = def.type === 'array';
        const isEntity = def.type === 'entity';
        const isDate = def.type === 'date';
        const label = key;
        let detail = def.type;
        if (isCollection) detail = `collection<${def.class || '?'}>`;
        if (isArray) detail = 'array';
        if (isEntity) detail = def.class || 'entity';
        if (isDate) detail = 'date';

        // Collections and arrays are de-prioritised, scalars/dates first
        let boost = 1;
        if (isEntity) boost = 0;
        if (isCollection || isArray) boost = -1;

        options.push({
            label,
            detail,
            type: (isCollection || isArray) ? 'class' : isEntity ? 'variable' : 'property',
            boost,
            apply: (view, completion, from, to) => {
                if (isCollection) {
                    applyCollectionCompletion(view, completion, from, to, def, pathParts, docText, pos);
                } else if (isArray) {
                    // Array without entity introspection: generate data-repeat loop
                    applyArrayCompletion(view, completion, from, to, def, key, pathParts, docText, pos);
                } else if (isEntity) {
                    // Insert property name followed by a dot to encourage further drilling
                    const insert = key + '.';
                    view.dispatch({
                        changes: { from, to, insert },
                        selection: { anchor: from + insert.length },
                    });
                } else if (isDate) {
                    // Date: insert name with date filter and close the bracket
                    const afterCursor = docText.substring(pos);
                    const hasClosing = /^\s*]]/.test(afterCursor);
                    const insert = hasClosing ? key + "|date('d.m.Y')" : key + "|date('d.m.Y') ]]";
                    view.dispatch({
                        changes: { from, to, insert },
                        selection: { anchor: from + insert.length },
                    });
                } else {
                    // Scalar: insert name and close the bracket
                    const afterCursor = docText.substring(pos);
                    const hasClosing = /^\s*]]/.test(afterCursor);
                    const insert = hasClosing ? key : key + ' ]]';
                    view.dispatch({
                        changes: { from, to, insert },
                        selection: { anchor: from + insert.length },
                    });
                }
            },
        });
    }

    if (options.length === 0) return null;

    return {
        from: context.wordStart,
        options,
        filter: true,
    };
}

// ─── Bracket context detection ────────────────────────────────────────────────

/**
 * Determine if the cursor is inside a [[ … ]] placeholder.
 * Returns { expression, wordStart } or null.
 *
 * `expression` is everything between [[ and the cursor,
 * trimmed of leading whitespace.
 * `wordStart` is the document position where the current word/token starts
 * (used as `from` for the completion range).
 */
function findBracketContext(doc, pos) {
    // Search backwards for the opening [[
    const before = doc.substring(0, pos);
    const openIdx = before.lastIndexOf('[[');
    if (openIdx === -1) return null;

    // Make sure there's no ]] between [[ and cursor (we'd be outside then)
    const between = before.substring(openIdx + 2);
    if (between.includes(']]')) return null;

    const expression = between.trimStart();

    // wordStart = position of the beginning of the current token after the last dot
    const lastDot = expression.lastIndexOf('.');
    let wordStart;
    if (lastDot >= 0) {
        // After the dot
        wordStart = openIdx + 2 + (between.length - between.trimStart().length) + lastDot + 1;
    } else {
        // From the start of the expression
        wordStart = openIdx + 2 + (between.length - between.trimStart().length);
    }

    return { expression, wordStart };
}

// ─── Scope analysis ───────────────────────────────────────────────────────────

/**
 * Build the set of variables available at a given cursor position.
 *
 * This includes the top-level schema variables (globals) plus any
 * loop variables introduced by data-repeat/data-repeat-as attributes
 * that are open (not yet closed) at the cursor position.
 *
 * Returns a flat object: { varName: schemaDef, ... }
 */
function buildScope(doc, pos, schema) {
    const scope = { ...schema };
    const textBefore = doc.substring(0, pos);

    // Find all data-repeat scopes that are open at cursor position.
    // We use a simple regex-based stack approach.
    const loops = parseActiveLoops(textBefore);

    for (const loop of loops) {
        // Resolve the collection's schema to find item properties
        const collectionNode = resolveSchemaPath(loop.collectionPath.split('.'), scope);
        if (collectionNode && collectionNode.properties) {
            // Add the loop variable with the collection's item properties
            scope[loop.alias] = {
                type: 'entity',
                class: collectionNode.class || loop.alias,
                properties: collectionNode.properties,
            };
        }
    }

    return scope;
}

/**
 * Parse the document text before the cursor to find all currently open
 * data-repeat loops (whose closing tags haven't appeared yet).
 *
 * Returns an array of { collectionPath, alias } objects, from outermost to innermost.
 */
function parseActiveLoops(textBefore) {
    const loops = [];

    // Match opening tags with data-repeat and data-repeat-as
    const openRegex = /<(\w+)\s[^>]*?data-repeat="([^"]+)"[^>]*?data-repeat-as="([^"]+)"[^>]*?>/gi;
    // We need to track which loops are still open. Simple approach:
    // scan forwards through the text tracking open/close of each loop tag.

    const allTags = [];
    const tagRegex = /<\/?(\w+)(\s[^>]*)?\/?>/gi;
    let match;

    while ((match = tagRegex.exec(textBefore)) !== null) {
        const isClosing = match[0].charAt(1) === '/';
        const isSelfClosing = match[0].endsWith('/>');
        const tagName = match[1].toLowerCase();
        const attrs = match[2] || '';

        if (isSelfClosing) continue;

        let repeatVal = null;
        let repeatAs = null;
        if (!isClosing) {
            const rMatch = attrs.match(/data-repeat="([^"]+)"/);
            const raMatch = attrs.match(/data-repeat-as="([^"]+)"/);
            if (rMatch) repeatVal = rMatch[1];
            if (raMatch) repeatAs = raMatch[1];
        }

        allTags.push({ tagName, isClosing, repeatVal, repeatAs });
    }

    // Walk through tags maintaining a stack to detect unclosed repeat loops
    const stack = []; // stack of { tagName, collectionPath, alias }

    for (const tag of allTags) {
        if (tag.isClosing) {
            // Pop matching opening tags from stack
            for (let i = stack.length - 1; i >= 0; i--) {
                if (stack[i].tagName === tag.tagName) {
                    stack.splice(i, 1);
                    break;
                }
            }
        } else {
            if (tag.repeatVal && tag.repeatAs) {
                stack.push({
                    tagName: tag.tagName,
                    collectionPath: tag.repeatVal,
                    alias: tag.repeatAs,
                });
            } else {
                // Non-repeat tag: still push to track nesting for correct closing
                stack.push({ tagName: tag.tagName, collectionPath: null, alias: null });
            }
        }
    }

    // Filter to only repeat loops that are still open
    for (const item of stack) {
        if (item.collectionPath && item.alias) {
            loops.push({ collectionPath: item.collectionPath, alias: item.alias });
        }
    }

    return loops;
}

// ─── Schema path resolution ───────────────────────────────────────────────────

/**
 * Walk down the schema tree following a dot-separated property path.
 *
 * Given path ["reservation1", "booker"] and the scope,
 * returns the schema node for Customer (with its properties).
 *
 * @param {string[]} parts  – path segments (e.g. ["reservation1", "booker"])
 * @param {Object}   scope  – current scope (globals + loop vars)
 * @returns {Object|null}
 */
function resolveSchemaPath(parts, scope) {
    if (parts.length === 0) {
        // Root level – return scope itself as a "virtual node" with properties
        return { properties: scope };
    }

    let node = scope[parts[0]];
    if (!node) return null;

    for (let i = 1; i < parts.length; i++) {
        if (!node.properties) return null;
        node = node.properties[parts[i]];
        if (!node) return null;
    }

    return node;
}

// ─── Collection completion (auto loop generation) ─────────────────────────────

/**
 * When the user selects a collection property from autocomplete,
 * automatically wrap it in a data-repeat loop element.
 *
 * If the cursor is inside a <table>, uses <tr> as the loop element.
 * Otherwise uses <span>.
 */
function applyCollectionCompletion(view, completion, from, to, def, pathParts, docText, cursorPos) {
    const singularName = def.singularName || completion.label.replace(/s$/, '');
    const collectionLabel = completion.label;

    // Build the full path to the collection (e.g. "reservation1.invoices" or just "reservations")
    const collectionPath = pathParts.length > 0
        ? pathParts.join('.') + '.' + collectionLabel
        : collectionLabel;

    // Determine if we're inside a <table> context
    const isInTable = isInsideTable(docText, cursorPos);
    const tag = isInTable ? 'tr' : 'span';

    // We need to replace the entire [[ … ]] placeholder
    const textBefore = docText.substring(0, from);
    const bracketOpen = textBefore.lastIndexOf('[[');
    const textAfter = docText.substring(to);
    const bracketCloseMatch = textAfter.match(/\s*]]/);
    const bracketCloseEnd = bracketCloseMatch
        ? to + bracketCloseMatch[0].length
        : to;

    // Build the loop markup
    const loopHtml = `<${tag} data-repeat="${collectionPath}" data-repeat-as="${singularName}">[[ ${singularName}. ]]</${tag}>`;

    const replaceFrom = bracketOpen;
    const replaceTo = bracketCloseEnd;

    // Calculate cursor position: right after "singularName." inside the new [[ ]]
    const cursorOffset = `<${tag} data-repeat="${collectionPath}" data-repeat-as="${singularName}">[[ ${singularName}.`.length;

    view.dispatch({
        changes: { from: replaceFrom, to: replaceTo, insert: loopHtml },
        selection: { anchor: replaceFrom + cursorOffset },
    });
}

/**
 * When the user selects an array-typed variable from autocomplete,
 * generate a data-repeat loop just like for collections but without
 * placing a property placeholder inside (since there is no schema
 * introspection for plain arrays).
 */
function applyArrayCompletion(view, _completion, from, to, def, key, pathParts, docText, cursorPos) {
    const singularName = def.singularName || key.replace(/s$/, '');

    // Build the full path (e.g. "simple.reservationsRows" or just "reservations")
    const arrayPath = pathParts.length > 0
        ? pathParts.join('.') + '.' + key
        : key;

    const isInTable = isInsideTable(docText, cursorPos);
    const tag = isInTable ? 'tr' : 'span';

    // Replace the entire [[ … ]] placeholder
    const textBefore = docText.substring(0, from);
    const bracketOpen = textBefore.lastIndexOf('[[');
    const textAfter = docText.substring(to);
    const bracketCloseMatch = textAfter.match(/\s*]]/);
    const bracketCloseEnd = bracketCloseMatch
        ? to + bracketCloseMatch[0].length
        : to;

    // Build the loop markup – cursor is placed inside the tags for manual editing
    const loopHtml = `<${tag} data-repeat="${arrayPath}" data-repeat-as="${singularName}"></${tag}>`;

    const replaceFrom = bracketOpen;
    const replaceTo = bracketCloseEnd;

    // Place cursor between the opening and closing tags
    const cursorOffset = `<${tag} data-repeat="${arrayPath}" data-repeat-as="${singularName}">`.length;

    view.dispatch({
        changes: { from: replaceFrom, to: replaceTo, insert: loopHtml },
        selection: { anchor: replaceFrom + cursorOffset },
    });
}

/**
 * Check if the cursor position is inside a <table> element
 * by counting unclosed <table> tags before the position.
 */
function isInsideTable(doc, pos) {
    const before = doc.substring(0, pos).toLowerCase();
    const opens = (before.match(/<table[\s>]/g) || []).length;
    const closes = (before.match(/<\/table>/g) || []).length;
    return opens > closes;
}
