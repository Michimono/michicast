/*
 * Inline mapping editor on the broadcast overview.
 * Recipe-style Set field steps; previews via the shared MappingEngine endpoint.
 */
(function () {
    const root = document.querySelector('[data-mapping-editor]');

    if (root === null) {
        return;
    }

    let state;
    try {
        state = JSON.parse(root.getAttribute('data-mapping-editor') || '{}');
    } catch (e) {
        return;
    }

    if (!state.ready) {
        return;
    }

    const stepsEl = root.querySelector('[data-mapping-steps]');
    const emptyEl = root.querySelector('[data-mapping-empty]');
    const addBtn = root.querySelector('[data-mapping-add]');
    const addMenu = root.querySelector('[data-mapping-add-menu]');
    let editorEl = null;
    let editorFields = null;
    const NEW_STEP_ID = '__new__';
    const autoEl = root.querySelector('[data-mapping-auto]');
    const autoText = root.querySelector('[data-mapping-auto-text]');
    const saveStatus = root.querySelector('[data-mapping-save-status]');
    const previewText = root.querySelector('[data-mapping-preview-text]');
    const previewMediaWrap = root.querySelector('[data-mapping-preview-media-wrap]');
    const previewMedia = root.querySelector('[data-mapping-preview-media]');
    const previewRaw = root.querySelector('[data-mapping-preview-raw]');
    const previewErrors = root.querySelector('[data-mapping-preview-errors]');
    const sourceDrawer = document.querySelector('[data-mapping-drawer="source"]');
    const destDrawer = document.querySelector('[data-mapping-drawer="destination"]');

    let steps = (state.mapping && state.mapping.steps ? state.mapping.steps : []).map(normalizeStep);
    let sample = (state.source && state.source.sample) ? state.source.sample : {};
    let editingId = null;
    let expandedResultId = null;
    let previewTimer = null;
    let livePreviewTimer = null;
    let currentOperation = null;
    let openMenuId = null;

    function normalizeStep(step) {
        return {
            id: step.id || cryptoRandom(),
            operation: step.operation,
            order: step.order != null ? step.order : 0,
            config: step.config || {},
        };
    }

    function cryptoRandom() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'step-' + Math.random().toString(36).slice(2, 10);
    }

    function operationLabel(id) {
        const found = (state.operations || []).find(function (op) { return op.id === id; });
        return found ? found.label : id;
    }

    function fieldLabel(schema, path) {
        const fields = (schema && schema.fields) ? schema.fields : [];
        const found = fields.find(function (f) { return f.name === path; });
        return found ? found.label : (path || '');
    }

    function setFieldParts(step) {
        const cfg = step.config || {};
        const src = state.source && state.source.schema;
        const dst = state.destination && state.destination.schema;
        const field = cfg.field || cfg.to || '';
        let label = fieldLabel(dst, field);
        if (!label || label === field) {
            label = fieldLabel(src, field) || field;
        }
        let value = String(cfg.value != null ? cfg.value : '');
        let short = value.replace(/\n/g, '↵');
        if (short.length > 56) short = short.slice(0, 53) + '…';
        return { field: field, label: label || '—', value: value, short: short };
    }

    function summarize(step) {
        const cfg = step.config || {};
        const src = state.source && state.source.schema;
        const dst = state.destination && state.destination.schema;
        switch (step.operation) {
            case 'set_field': {
                const field = cfg.field || cfg.to || '';
                let label = fieldLabel(dst, field);
                if (!label || label === field) {
                    label = fieldLabel(src, field) || field;
                }
                let t = String(cfg.value != null ? cfg.value : '').replace(/\n/g, '↵');
                if (t.length > 48) t = t.slice(0, 45) + '…';
                return label + ' = "' + t + '"';
            }
            case 'map':
                return fieldLabel(src, cfg.from) + ' → ' + fieldLabel(dst, cfg.to);
            case 'concatenate':
                return fieldLabel(src, cfg.value) + ' → ' + fieldLabel(dst, cfg.to);
            case 'add_text': {
                let t = String(cfg.text != null ? cfg.text : '').replace(/\n/g, '↵');
                if (t.length > 40) t = t.slice(0, 37) + '…';
                return '"' + t + '" → ' + fieldLabel(dst, cfg.to);
            }
            case 'replace':
                return '"' + (cfg.find || '') + '" in ' + fieldLabel(dst, cfg.in);
            case 'trim':
                return fieldLabel(dst, cfg.field) + ' → ' + (cfg.max_length || '') + ' characters';
            case 'format_date':
                return fieldLabel(src, cfg.field) + ' → ' + fieldLabel(dst, cfg.to);
            case 'first':
                return 'First of ' + fieldLabel(src, cfg.field) + ' → ' + fieldLabel(dst, cfg.to);
            case 'last':
                return 'Last of ' + fieldLabel(src, cfg.field) + ' → ' + fieldLabel(dst, cfg.to);
            case 'join':
                return fieldLabel(src, cfg.field) + ' → ' + fieldLabel(dst, cfg.to);
            default:
                return '';
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function destinationParseMode() {
        const mode = state.destination && state.destination.parse_mode;
        return typeof mode === 'string' ? mode : '';
    }

    function formatHintHtml() {
        if ((state.destination && state.destination.type) !== 'telegram') {
            return '';
        }
        const mode = destinationParseMode();
        if (mode === 'MarkdownV2') {
            return '<span class="mt-1 text-xs text-muted">Telegram Markdown V2: wrap with *single asterisks* for bold (not **). Example: *{{title}}*. Special characters inside variables are escaped when sending.</span>';
        }
        if (mode === 'HTML') {
            return '<span class="mt-1 text-xs text-muted">Telegram HTML: use tags such as &lt;b&gt;{{title}}&lt;/b&gt;. Angle brackets in variables are escaped when sending.</span>';
        }
        return '<span class="mt-1 text-xs text-muted">Telegram formatting follows the destination parse mode (HTML or Markdown V2).</span>';
    }

    function closeStepMenus() {
        if (!stepsEl) return;
        stepsEl.querySelectorAll('[data-step-menu]').forEach(function (menu) {
            menu.hidden = true;
        });
        stepsEl.querySelectorAll('[data-step-menu-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-expanded', 'false');
        });
        openMenuId = null;
    }

    function refreshEditorMount() {
        editorEl = root.querySelector('[data-mapping-step-editor]');
        editorFields = root.querySelector('[data-mapping-step-editor-fields]');
    }

    function editorShellHtml() {
        return '<div data-mapping-step-editor class="mt-1 flex flex-col gap-3">' +
            '<div class="flex flex-col gap-3" data-mapping-step-editor-fields></div>' +
            '<div class="mt-1 flex flex-wrap gap-2">' +
            '<button type="button" class="cursor-pointer rounded-md border border-line bg-paper-raised px-3 py-1.5 text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-mapping-step-cancel>Cancel</button>' +
            '<button type="button" class="cursor-pointer rounded-md bg-ink px-3 py-1.5 text-sm text-paper focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-mapping-step-save>Save step</button>' +
            '</div></div>';
    }

    function closeEditor() {
        editingId = null;
        currentOperation = null;
        editorEl = null;
        editorFields = null;
        renderSteps();
    }

    function renderSteps() {
        if (!stepsEl) return;
        stepsEl.innerHTML = '';
        steps.forEach(function (step, index) {
            step.order = index;
            const row = document.createElement('article');
            // Level 2 step card on Level 1 Mapping surface
            row.className = 'group relative rounded-lg border border-line bg-paper p-3 sm:p-3.5';
            row.dataset.stepId = step.id;
            row.setAttribute('role', 'listitem');

            const pill = '<span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-ink text-[0.6875rem] font-medium text-paper" aria-hidden="true">' + (index + 1) + '</span>';

            if (editingId === step.id && currentOperation) {
                row.innerHTML =
                    '<div class="flex items-start gap-3">' +
                    pill +
                    '<div class="min-w-0 flex-1">' + editorShellHtml() + '</div>' +
                    '</div>';
                stepsEl.appendChild(row);
                return;
            }

            const op = escapeHtml(operationLabel(step.operation));
            let bodyHtml;
            if (step.operation === 'set_field') {
                const parts = setFieldParts(step);
                bodyHtml =
                    '<p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">' + op + '</p>' +
                    '<p class="mt-1 text-sm text-ink"><span class="text-muted">Field</span> <span class="font-medium">' + escapeHtml(parts.label) + '</span></p>' +
                    '<p class="mt-0.5 text-sm text-ink break-words"><span class="text-muted">Value</span> <span class="font-mono text-[0.8125rem]">' + escapeHtml(parts.short || '—') + '</span></p>';
            } else {
                bodyHtml =
                    '<p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">' + op + '</p>' +
                    '<p class="mt-1 text-sm text-ink break-words">' + escapeHtml(summarize(step)) + '</p>';
            }

            row.innerHTML =
                '<div class="flex items-start gap-3">' +
                pill +
                '<div class="min-w-0 flex-1">' + bodyHtml + '</div>' +
                '<div class="relative shrink-0">' +
                '<button type="button" class="cursor-pointer rounded-md p-1.5 text-muted hover:bg-paper-raised hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-step-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Step ' + (index + 1) + ' actions">' +
                '<svg viewBox="0 0 24 24" fill="currentColor" class="size-5" aria-hidden="true"><circle cx="12" cy="5" r="1.75"/><circle cx="12" cy="12" r="1.75"/><circle cx="12" cy="19" r="1.75"/></svg>' +
                '</button>' +
                '<div hidden data-step-menu role="menu" class="absolute right-0 z-20 mt-1 min-w-[10rem] rounded-md border border-line bg-paper-raised p-1 shadow-sm">' +
                '<button type="button" role="menuitem" class="cursor-pointer block w-full rounded px-3 py-1.5 text-left text-sm hover:bg-paper focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-step-edit>Edit</button>' +
                '<button type="button" role="menuitem" class="cursor-pointer block w-full rounded px-3 py-1.5 text-left text-sm hover:bg-paper focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-step-up' + (index === 0 ? ' disabled' : '') + '>Move up</button>' +
                '<button type="button" role="menuitem" class="cursor-pointer block w-full rounded px-3 py-1.5 text-left text-sm hover:bg-paper focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-step-down' + (index === steps.length - 1 ? ' disabled' : '') + '>Move down</button>' +
                '<button type="button" role="menuitem" class="cursor-pointer block w-full rounded px-3 py-1.5 text-left text-sm hover:bg-paper focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-step-preview>Show result</button>' +
                '<button type="button" role="menuitem" class="cursor-pointer block w-full rounded px-3 py-1.5 text-left text-sm text-state-fail hover:bg-paper focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-step-remove>Remove</button>' +
                '</div></div></div>' +
                '<div hidden class="mt-3 rounded-md border border-line bg-paper-raised p-3" data-step-result>' +
                '<div class="flex items-center justify-between gap-2">' +
                '<p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">After step ' + (index + 1) + '</p>' +
                '<button type="button" class="cursor-pointer text-xs text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-step-collapse>Collapse</button>' +
                '</div>' +
                '<pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap text-sm text-ink" data-step-result-body></pre>' +
                '</div>';
            stepsEl.appendChild(row);
        });

        if (editingId === NEW_STEP_ID && currentOperation) {
            const draft = document.createElement('article');
            draft.className = 'group relative rounded-lg border border-line bg-paper p-3 sm:p-3.5';
            draft.dataset.stepId = NEW_STEP_ID;
            draft.setAttribute('role', 'listitem');
            const n = steps.length + 1;
            draft.innerHTML =
                '<div class="flex items-start gap-3">' +
                '<span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-ink text-[0.6875rem] font-medium text-paper" aria-hidden="true">' + n + '</span>' +
                '<div class="min-w-0 flex-1">' + editorShellHtml() + '</div>' +
                '</div>';
            stepsEl.appendChild(draft);
        }

        if (emptyEl) emptyEl.hidden = steps.length > 0 || editingId === NEW_STEP_ID;
        refreshEditorMount();
    }

    function sourceFields() {
        return (state.source && state.source.schema && state.source.schema.fields) || [];
    }

    function destinationFields() {
        return (state.destination && state.destination.schema && state.destination.schema.fields) || [];
    }

    function fieldSuggestions() {
        const seen = {};
        const list = [];
        destinationFields().forEach(function (f) {
            if (!seen[f.name]) {
                seen[f.name] = true;
                list.push({ value: f.name, label: f.label, group: 'Destination', type: f.type, required: !!f.required });
            }
        });
        sourceFields().forEach(function (f) {
            if (!seen[f.name]) {
                seen[f.name] = true;
                list.push({ value: f.name, label: f.label, group: 'Source', type: f.type, required: false });
            }
        });
        steps.forEach(function (step) {
            if (step.operation === 'set_field') {
                const name = (step.config && step.config.field) || '';
                if (name && !seen[name]) {
                    seen[name] = true;
                    list.push({ value: name, label: name, group: 'Intermediate', type: 'text', required: false });
                }
            }
        });
        return list;
    }

    function insertChipsHtml() {
        const fields = sourceFields().concat(destinationFields());
        const seen = {};
        const chips = [];
        fields.forEach(function (f) {
            if (seen[f.name]) return;
            seen[f.name] = true;
            let sample = '';
            if (state.source && state.source.sample && state.source.sample[f.name] != null) {
                sample = String(state.source.sample[f.name]);
            }
            if (sample.length > 28) sample = sample.slice(0, 25) + '…';
            const title = sample ? (f.label + ' — e.g. ' + sample) : f.label;
            chips.push(
                '<button type="button" class="cursor-pointer rounded-full border border-line bg-paper-raised px-2.5 py-1 text-xs text-ink hover:border-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-insert-field="' +
                escapeHtml(f.name) + '" title="' + escapeHtml(title) + '" aria-label="Insert ' + escapeHtml(f.label) + '">' +
                '<span class="font-mono">{{' + escapeHtml(f.name) + '}}</span>' +
                (sample ? '<span class="ml-1 text-muted">' + escapeHtml(sample) + '</span>' : '') +
                '</button>'
            );
        });
        return chips.join('');
    }

    function selectHtml(name, options, selected, label) {
        const opts = options.map(function (o) {
            return '<option value="' + escapeHtml(o.value) + '"' + (o.value === selected ? ' selected' : '') + '>' + escapeHtml(o.label) + '</option>';
        }).join('');
        return '<label class="flex flex-col gap-1 text-sm"><span class="font-medium">' + escapeHtml(label) + '</span>' +
            '<select name="' + escapeHtml(name) + '" class="rounded-md border border-line bg-paper-raised px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent">' +
            '<option value="">Choose…</option>' + opts + '</select></label>';
    }

    function inputHtml(name, label, value) {
        return '<label class="flex flex-col gap-1 text-sm"><span class="font-medium">' + escapeHtml(label) + '</span>' +
            '<input name="' + escapeHtml(name) + '" value="' + escapeHtml(value != null ? value : '') + '" class="rounded-md border border-line bg-paper-raised px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" /></label>';
    }

    function textareaHtml(name, label, value) {
        return '<label class="flex flex-col gap-1 text-sm"><span class="font-medium">' + escapeHtml(label) + '</span>' +
            '<textarea name="' + escapeHtml(name) + '" rows="3" class="rounded-md border border-line bg-paper-raised px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent">' + escapeHtml(value != null ? value : '') + '</textarea>' +
            '<span class="flex flex-wrap gap-2 text-xs text-muted">' +
            '<button type="button" class="cursor-pointer underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-insert="space">space</button>' +
            '<button type="button" class="cursor-pointer underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-insert="lf">line break</button>' +
            '<button type="button" class="cursor-pointer underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-insert="para">paragraph break</button>' +
            '</span></label>';
    }

    function sourceOptions() {
        return sourceFields().map(function (f) {
            return { value: f.name, label: f.label + ' · ' + f.type };
        });
    }

    function destinationOptions() {
        return destinationFields().map(function (f) {
            return { value: f.name, label: f.label + ' · ' + f.type + (f.required ? ' · required' : '') };
        });
    }

    function openSetFieldEditor(step) {
        const cfg = step ? (step.config || {}) : {};
        const suggestions = fieldSuggestions();
        const selected = cfg.field || cfg.to || '';
        const inputClass = 'rounded-md border border-line bg-paper-raised px-3 py-2 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent';

        let html = '<p class="text-sm font-medium text-ink">Set field</p>';

        let options = suggestions.map(function (s) {
            const req = s.required ? ' · required' : '';
            return '<option value="' + escapeHtml(s.value) + '"' + (s.value === selected ? ' selected' : '') + '>' +
                escapeHtml(s.label) + ' · ' + escapeHtml(s.group) + req + '</option>';
        }).join('');

        if (selected && !suggestions.some(function (s) { return s.value === selected; })) {
            options += '<option value="' + escapeHtml(selected) + '" selected>' + escapeHtml(selected) + ' · Custom</option>';
        }

        html += '<label class="flex flex-col gap-1 text-sm"><span class="font-medium">Field</span>' +
            '<select name="field" class="' + inputClass + '">' +
            '<option value="">Choose field…</option>' +
            options +
            '</select></label>';

        html += '<label class="flex flex-col gap-1 text-sm"><span class="font-medium">Value</span>' +
            '<textarea name="value" rows="5" class="' + inputClass + ' font-mono" placeholder="Use {{field}} to insert values">' +
            escapeHtml(cfg.value != null ? cfg.value : '') +
            '</textarea>' + formatHintHtml() + '</label>';

        html += '<div class="flex flex-col gap-1.5"><p class="text-xs font-medium text-muted">Insert variable</p>' +
            '<div class="flex flex-wrap gap-1.5" data-insert-chips>' + insertChipsHtml() + '</div></div>';

        html += '<div class="rounded-md border border-line bg-paper-raised px-3 py-2" data-live-preview-wrap>' +
            '<p class="text-[0.6875rem] font-medium tracking-wide text-muted uppercase">Resolved preview</p>' +
            '<pre class="mt-1 whitespace-pre-wrap text-sm text-ink" data-live-preview>(empty)</pre></div>';

        if (editorFields) editorFields.innerHTML = html;
        wireSetFieldEditor();
        scheduleLivePreview();
    }

    function wireSetFieldEditor() {
        if (!editorFields) return;

        editorFields.querySelectorAll('[data-insert-field]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const ta = editorFields.querySelector('textarea[name="value"]');
                if (!ta) return;
                const token = '{{' + btn.getAttribute('data-insert-field') + '}}';
                const start = ta.selectionStart != null ? ta.selectionStart : ta.value.length;
                const end = ta.selectionEnd != null ? ta.selectionEnd : start;
                ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
                ta.focus();
                const caret = start + token.length;
                ta.setSelectionRange(caret, caret);
                scheduleLivePreview();
            });
        });

        const valueEl = editorFields.querySelector('textarea[name="value"]');
        const fieldEl = editorFields.querySelector('[name="field"]');
        if (valueEl) {
            valueEl.addEventListener('input', scheduleLivePreview);
        }
        if (fieldEl) {
            fieldEl.addEventListener('input', scheduleLivePreview);
        }
    }

    function scheduleLivePreview() {
        clearTimeout(livePreviewTimer);
        livePreviewTimer = setTimeout(runLivePreview, 180);
    }

    function clientResolve(template, context) {
        const single = /^\s*\{\{\s*([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\s*\}\}\s*$/;
        const token = /\{\{\s*([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\s*\}\}/g;
        const m = String(template).match(single);
        if (m) {
            const path = m[1];
            if (Object.prototype.hasOwnProperty.call(context, path)) {
                return context[path];
            }
            return '';
        }
        return String(template).replace(token, function (_, path) {
            if (!Object.prototype.hasOwnProperty.call(context, path)) return '';
            const v = context[path];
            if (v == null) return '';
            if (typeof v === 'object') return '';
            return String(v);
        });
    }

    function runLivePreview() {
        const wrap = editorFields && editorFields.querySelector('[data-live-preview]');
        if (!wrap) return;
        const valueEl = editorFields.querySelector('textarea[name="value"]');
        const template = valueEl ? valueEl.value : '';
        const resolved = clientResolve(template, sample || {});
        if (resolved === '' || resolved == null) {
            wrap.textContent = '(empty)';
        } else if (typeof resolved === 'object') {
            wrap.textContent = JSON.stringify(resolved, null, 2);
        } else {
            wrap.textContent = String(resolved);
        }
    }

    function fillEditorFields(operation, step) {
        const cfg = step ? (step.config || {}) : {};
        refreshEditorMount();

        if (operation === 'set_field') {
            openSetFieldEditor(step);
            return;
        }

        let html = '<p class="text-sm font-medium text-ink">' + escapeHtml(operationLabel(operation)) + '</p>';

        if (operation === 'map') {
            html += selectHtml('from', sourceOptions(), cfg.from || '', 'From source field');
            html += selectHtml('to', destinationOptions(), cfg.to || '', 'To destination field');
        } else if (operation === 'concatenate') {
            html += selectHtml('value', sourceOptions(), cfg.value || '', 'Value');
            html += selectHtml('to', destinationOptions(), cfg.to || '', 'To');
            html += '<p class="text-sm text-muted">Appends the value to the current destination field.</p>';
        } else if (operation === 'add_text') {
            html += textareaHtml('text', 'Text', cfg.text || '');
            html += selectHtml('to', destinationOptions(), cfg.to || 'text', 'To');
        } else if (operation === 'replace') {
            html += selectHtml('in', destinationOptions(), cfg.in || 'text', 'Replace in');
            html += inputHtml('find', 'Find', cfg.find || '');
            html += inputHtml('replace', 'Replace with', cfg.replace || '');
        } else if (operation === 'trim') {
            html += selectHtml('field', destinationOptions(), cfg.field || 'text', 'Field');
            html += inputHtml('max_length', 'Maximum length', cfg.max_length != null ? cfg.max_length : 1000);
        } else if (operation === 'format_date') {
            html += selectHtml('field', sourceOptions(), cfg.field || '', 'Field');
            const presets = Object.entries(state.date_presets || {}).map(function (pair) {
                return { value: pair[0], label: pair[1] };
            });
            html += selectHtml('format', presets, cfg.format || 'j M Y', 'Format');
            html += selectHtml('to', destinationOptions(), cfg.to || 'text', 'To');
        } else if (operation === 'first' || operation === 'last' || operation === 'join') {
            html += selectHtml('field', sourceOptions(), cfg.field || '', 'Field');
            if (operation === 'join') {
                html += inputHtml('separator', 'Separator', cfg.separator != null ? cfg.separator : ', ');
            }
            html += selectHtml('to', destinationOptions(), cfg.to || 'text', 'Target');
        }

        if (editorFields) editorFields.innerHTML = html;

        editorFields.querySelectorAll('[data-insert]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const ta = editorFields.querySelector('textarea[name="text"]');
                if (!ta) return;
                const kind = btn.getAttribute('data-insert');
                const token = kind === 'lf' ? '\n' : (kind === 'para' ? '\n\n' : ' ');
                const start = ta.selectionStart != null ? ta.selectionStart : ta.value.length;
                ta.value = ta.value.slice(0, start) + token + ta.value.slice(start);
                ta.focus();
            });
        });
    }

    function openEditor(operation, step) {
        editingId = step ? step.id : NEW_STEP_ID;
        currentOperation = operation;
        expandedResultId = null;
        closeStepMenus();
        if (addMenu) addMenu.hidden = true;
        if (addBtn) addBtn.setAttribute('aria-expanded', 'false');

        renderSteps();
        fillEditorFields(operation, step);

        const card = stepsEl && stepsEl.querySelector('[data-step-id="' + editingId + '"]');
        if (card && card.scrollIntoView) {
            card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        const focusEl = editorFields && editorFields.querySelector(operation === 'set_field' ? 'textarea[name="value"]' : 'input, select, textarea');
        if (focusEl) focusEl.focus();
    }

    function readEditorConfig() {
        const config = {};
        if (!editorFields) return config;
        editorFields.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (!el.name) return;
            if (el.name === 'max_length') {
                config[el.name] = Number(el.value);
            } else {
                config[el.name] = el.value;
            }
        });
        return config;
    }

    function saveEditor() {
        if (!currentOperation) return;
        refreshEditorMount();
        const config = readEditorConfig();
        if (editingId && editingId !== NEW_STEP_ID) {
            const idx = steps.findIndex(function (s) { return s.id === editingId; });
            if (idx >= 0) {
                steps[idx] = { id: steps[idx].id, operation: currentOperation, order: idx, config: config };
            }
        } else {
            steps.push({ id: cryptoRandom(), operation: currentOperation, order: steps.length, config: config });
        }
        editingId = null;
        currentOperation = null;
        editorEl = null;
        editorFields = null;
        renderSteps();
        schedulePreview();
        schedulePersist();
    }

    function mappingPayload() {
        return {
            version: (state.mapping && state.mapping.version) ? state.mapping.version : 1,
            steps: steps.map(function (s, i) {
                return { id: s.id, operation: s.operation, order: i, config: s.config };
            }),
            source_provider: state.source && state.source.type,
            destination_provider: state.destination && state.destination.type,
            source_schema_version: state.source && state.source.schema && state.source.schema.version,
            destination_schema_version: state.destination && state.destination.schema && state.destination.schema.version,
        };
    }

    function schedulePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(function () { runPreview(null); }, 200);
    }

    let persistTimer = null;
    let persistInFlight = false;
    let persistQueued = false;

    function schedulePersist() {
        clearTimeout(persistTimer);
        persistTimer = setTimeout(function () { saveMapping(); }, 180);
    }

    async function runPreview(throughStepId) {
        try {
            const response = await fetch(state.urls.preview, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': state.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    mapping: mappingPayload(),
                    source_data: sample,
                    through_step_id: throughStepId,
                }),
            });
            const data = await response.json();
            if (throughStepId) {
                const card = stepsEl && stepsEl.querySelector('[data-step-id="' + throughStepId + '"]');
                const box = card && card.querySelector('[data-step-result]');
                const body = card && card.querySelector('[data-step-result-body]');
                if (box && body) {
                    box.hidden = false;
                    if (data.data && typeof data.data === 'object') {
                        body.textContent = JSON.stringify(data.data, null, 2);
                    } else {
                        const text = (data.human && data.human.text != null) ? data.human.text : '';
                        body.textContent = text || '(empty)';
                    }
                }
                return;
            }
            renderFinalPreview(data);
        } catch (e) {
            if (saveStatus) saveStatus.textContent = 'Could not update preview.';
        }
    }

    function renderFinalPreview(data) {
        if (previewText) previewText.textContent = (data.human && data.human.text) ? data.human.text : '';
        if (previewRaw) previewRaw.textContent = JSON.stringify(data.data || {}, null, 2);
        if (previewMedia && previewMediaWrap) {
            const media = (data.human && data.human.media) ? data.human.media : [];
            previewMedia.innerHTML = '';
            if (media.length === 0) {
                previewMediaWrap.hidden = true;
            } else {
                previewMediaWrap.hidden = false;
                media.forEach(function (item) {
                    const li = document.createElement('li');
                    li.textContent = typeof item === 'string' ? item : (item.url || item.type || 'media item');
                    previewMedia.appendChild(li);
                });
            }
        }
        if (previewErrors) {
            previewErrors.innerHTML = '';
            (data.errors || []).forEach(function (err) {
                const p = document.createElement('p');
                p.className = 'text-sm text-state-fail';
                p.textContent = err.message;
                previewErrors.appendChild(p);
            });
        }
    }

    async function saveMapping() {
        if (persistInFlight) {
            persistQueued = true;
            return;
        }
        persistInFlight = true;
        if (saveStatus) saveStatus.textContent = 'Saving…';
        try {
            const response = await fetch(state.urls.update, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': state.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    _method: 'PATCH',
                    mapping: mappingPayload(),
                    source_data: sample,
                }),
            });
            const data = await response.json();
            if (!response.ok) {
                if (saveStatus) saveStatus.textContent = data.message || 'Could not save.';
                if (data.errors) {
                    renderFinalPreview({ human: state.preview && state.preview.human, data: state.preview && state.preview.data, errors: data.errors });
                }
                return;
            }
            state.has_stored_mapping = true;
            state.mapping = mappingPayload();
            if (saveStatus) saveStatus.textContent = 'Saved.';
            if (autoEl) autoEl.hidden = true;
        } catch (e) {
            if (saveStatus) saveStatus.textContent = 'Could not save.';
        } finally {
            persistInFlight = false;
            if (persistQueued) {
                persistQueued = false;
                schedulePersist();
            }
        }
    }

    function renderFieldTree(schema, sampleData) {
        const fields = (schema && schema.fields) ? schema.fields : [];
        return fields.map(function (field) {
            const value = sampleData ? sampleData[field.name] : undefined;
            let example = '';
            if (Array.isArray(value)) {
                example = value.length + ' item' + (value.length === 1 ? '' : 's');
            } else if (value !== null && value !== undefined) {
                const s = String(value);
                example = '"' + s.slice(0, 80) + (s.length > 80 ? '…' : '') + '"';
            }
            return '<div class="border-b border-line py-3">' +
                '<p class="text-sm font-medium text-ink">' + escapeHtml(field.label) + '</p>' +
                '<p class="font-mono text-xs text-muted">' + escapeHtml(field.type) + (field.required ? ' · required' : ' · optional') + '</p>' +
                (example ? '<p class="mt-1 text-sm text-ink">' + escapeHtml(example) + '</p>' : '') +
                (field.description ? '<p class="mt-1 text-xs text-muted">' + escapeHtml(field.description) + '</p>' : '') +
                '</div>';
        }).join('');
    }

    function populateDrawers() {
        const sourceBody = document.querySelector('[data-mapping-drawer-source-body]');
        const sourceRaw = document.querySelector('[data-mapping-drawer-source-raw]');
        const destBody = document.querySelector('[data-mapping-drawer-destination-body]');
        if (sourceBody) sourceBody.innerHTML = renderFieldTree(state.source && state.source.schema, sample);
        if (sourceRaw) sourceRaw.textContent = JSON.stringify(sample, null, 2);
        if (destBody) destBody.innerHTML = renderFieldTree(state.destination && state.destination.schema, {});
    }

    function showAutoBanner() {
        if (!autoEl || !autoText) return;
        if (state.has_stored_mapping) {
            autoEl.hidden = true;
            return;
        }
        const count = state.proposed_count || steps.length;
        if (count <= 0) {
            autoEl.hidden = true;
            return;
        }
        autoText.textContent = count === 1 ? '1 field mapped' : (count + ' fields mapped');
        autoEl.hidden = false;
    }

    function sortedOperations() {
        const ops = (state.operations || []).slice();
        const secondaryIds = { map: true, add_text: true, concatenate: true };
        ops.sort(function (a, b) {
            const ap = a.id === 'set_field' || a.primary ? 0 : (secondaryIds[a.id] || a.secondary ? 2 : 1);
            const bp = b.id === 'set_field' || b.primary ? 0 : (secondaryIds[b.id] || b.secondary ? 2 : 1);
            if (ap !== bp) return ap - bp;
            if (a.id === 'set_field') return -1;
            if (b.id === 'set_field') return 1;
            return 0;
        });
        return ops;
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            if (!addMenu) return;
            const open = addMenu.hidden;
            addMenu.hidden = !open;
            addBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                const secondaryIds = { map: true, add_text: true, concatenate: true };
                const ops = sortedOperations();
                const primary = [];
                const tools = [];
                const more = [];
                ops.forEach(function (op) {
                    if (op.id === 'set_field' || op.primary) primary.push(op);
                    else if (secondaryIds[op.id] || op.secondary) more.push(op);
                    else tools.push(op);
                });
                function opButton(op, emphasized) {
                    return '<button type="button" role="menuitem" class="cursor-pointer block w-full rounded px-3 py-2 text-left text-sm focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent ' +
                        (emphasized ? 'font-medium text-ink hover:bg-paper' : 'text-muted hover:bg-paper hover:text-ink') +
                        '" data-op="' + escapeHtml(op.id) + '">' +
                        escapeHtml(op.label) +
                        '</button>';
                }
                let html = '';
                primary.forEach(function (op) { html += opButton(op, true); });
                if (tools.length) {
                    html += '<p class="px-3 pt-2 pb-1 text-[0.625rem] font-medium tracking-wide text-muted uppercase">Tools</p>';
                    tools.forEach(function (op) { html += opButton(op, false); });
                }
                if (more.length) {
                    html += '<p class="px-3 pt-2 pb-1 text-[0.625rem] font-medium tracking-wide text-muted uppercase">More</p>';
                    more.forEach(function (op) { html += opButton(op, false); });
                }
                addMenu.innerHTML = html;
            }
        });
    }

    if (addMenu) {
        addMenu.addEventListener('click', function (event) {
            const btn = event.target.closest('[data-op]');
            if (!btn) return;
            openEditor(btn.getAttribute('data-op'));
        });
    }

    if (stepsEl) {
        stepsEl.addEventListener('click', function (event) {
            if (event.target.closest('[data-mapping-step-cancel]')) {
                closeEditor();
                return;
            }
            if (event.target.closest('[data-mapping-step-save]')) {
                saveEditor();
                return;
            }

            const card = event.target.closest('[data-step-id]');
            if (!card) return;
            const id = card.getAttribute('data-step-id');
            if (id === NEW_STEP_ID) return;
            const index = steps.findIndex(function (s) { return s.id === id; });
            if (index < 0) return;

            if (event.target.closest('[data-step-menu-toggle]')) {
                const menu = card.querySelector('[data-step-menu]');
                const toggle = card.querySelector('[data-step-menu-toggle]');
                const willOpen = menu && menu.hidden;
                closeStepMenus();
                if (willOpen && menu && toggle) {
                    menu.hidden = false;
                    toggle.setAttribute('aria-expanded', 'true');
                    openMenuId = id;
                }
                return;
            }

            if (event.target.closest('[data-step-edit]')) {
                closeStepMenus();
                openEditor(steps[index].operation, steps[index]);
                return;
            }
            if (event.target.closest('[data-step-remove]')) {
                closeStepMenus();
                steps.splice(index, 1);
                renderSteps();
                schedulePreview();
                schedulePersist();
                return;
            }
            if (event.target.closest('[data-step-up]') && index > 0) {
                closeStepMenus();
                const tmp = steps[index - 1];
                steps[index - 1] = steps[index];
                steps[index] = tmp;
                renderSteps();
                schedulePreview();
                schedulePersist();
                return;
            }
            if (event.target.closest('[data-step-down]') && index < steps.length - 1) {
                closeStepMenus();
                const tmp = steps[index + 1];
                steps[index + 1] = steps[index];
                steps[index] = tmp;
                renderSteps();
                schedulePreview();
                schedulePersist();
                return;
            }
            if (event.target.closest('[data-step-preview]')) {
                closeStepMenus();
                if (expandedResultId && expandedResultId !== id) {
                    const prev = stepsEl.querySelector('[data-step-id="' + expandedResultId + '"] [data-step-result]');
                    if (prev) prev.hidden = true;
                }
                expandedResultId = id;
                runPreview(id);
                return;
            }
            if (event.target.closest('[data-step-collapse]')) {
                const box = card.querySelector('[data-step-result]');
                if (box) box.hidden = true;
                if (expandedResultId === id) expandedResultId = null;
            }
        });
    }

    const resetBtn = root.querySelector('[data-mapping-reset-defaults]');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            const defaults = state.provider_default || state.default_mapping;
            steps = ((defaults && defaults.steps) ? defaults.steps : []).map(normalizeStep);
            editingId = null;
            currentOperation = null;
            editorEl = null;
            editorFields = null;
            renderSteps();
            schedulePreview();
            schedulePersist();
            showAutoBanner();
        });
    }

    const reviewBtn = root.querySelector('[data-mapping-review]');
    if (reviewBtn) {
        reviewBtn.addEventListener('click', function () {
            if (stepsEl) stepsEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    const viewSource = document.querySelector('[data-mapping-view-source]');
    if (viewSource) {
        viewSource.addEventListener('click', function () {
            populateDrawers();
            if (sourceDrawer && sourceDrawer.showModal) sourceDrawer.showModal();
        });
    }

    const viewDest = document.querySelector('[data-mapping-view-destination]');
    if (viewDest) {
        viewDest.addEventListener('click', function () {
            populateDrawers();
            if (destDrawer && destDrawer.showModal) destDrawer.showModal();
        });
    }

    const refreshSample = document.querySelector('[data-mapping-refresh-sample]');
    if (refreshSample) {
        refreshSample.addEventListener('click', async function () {
            try {
                const response = await fetch(state.urls.sample, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': state.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await response.json();
                sample = data.sample || sample;
                populateDrawers();
                schedulePreview();
            } catch (e) {
                if (saveStatus) saveStatus.textContent = 'Could not refresh sample.';
            }
        });
    }

    document.addEventListener('click', function (event) {
        if (addMenu && !addMenu.hidden && !event.target.closest('[data-mapping-add]') && !addMenu.contains(event.target)) {
            addMenu.hidden = true;
            if (addBtn) addBtn.setAttribute('aria-expanded', 'false');
        }
        if (openMenuId && !event.target.closest('[data-step-menu-toggle]') && !event.target.closest('[data-step-menu]')) {
            closeStepMenus();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeStepMenus();
            if (addMenu && !addMenu.hidden) {
                addMenu.hidden = true;
                if (addBtn) addBtn.setAttribute('aria-expanded', 'false');
            }
            if (currentOperation) {
                closeEditor();
            }
        }
    });

    renderSteps();
    populateDrawers();
    showAutoBanner();
    if (state.preview) {
        renderFinalPreview(state.preview);
    } else {
        schedulePreview();
    }
})();
