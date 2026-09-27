/*
 * Inline mapping editor on the broadcast overview.
 * Configures ordered steps; previews via the shared MappingEngine endpoint.
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
    const editorEl = root.querySelector('[data-mapping-step-editor]');
    const editorFields = root.querySelector('[data-mapping-step-editor-fields]');
    const autoEl = root.querySelector('[data-mapping-auto]');
    const autoText = root.querySelector('[data-mapping-auto-text]');
    const saveBtn = root.querySelector('[data-mapping-save]');
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
    let currentOperation = null;

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

    function summarize(step) {
        const cfg = step.config || {};
        const src = state.source && state.source.schema;
        const dst = state.destination && state.destination.schema;
        switch (step.operation) {
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

    function renderSteps() {
        if (!stepsEl) return;
        stepsEl.innerHTML = '';
        steps.forEach(function (step, index) {
            step.order = index;
            const card = document.createElement('article');
            card.className = 'rounded-md bg-paper px-3 py-3';
            card.dataset.stepId = step.id;
            card.innerHTML =
                '<div class="flex flex-wrap items-start justify-between gap-3">' +
                '<div class="min-w-0 flex flex-col gap-1">' +
                '<p class="font-mono text-[0.6875rem] text-muted">Step ' + (index + 1) + '</p>' +
                '<p class="text-sm font-medium text-ink">' + escapeHtml(operationLabel(step.operation)) + '</p>' +
                '<p class="text-sm text-ink">' + escapeHtml(summarize(step)) + '</p>' +
                '</div>' +
                '<div class="flex flex-wrap gap-1">' +
                '<button type="button" class="rounded px-2 py-1 text-xs text-muted hover:text-ink" data-step-up' + (index === 0 ? ' disabled' : '') + '>Up</button>' +
                '<button type="button" class="rounded px-2 py-1 text-xs text-muted hover:text-ink" data-step-down' + (index === steps.length - 1 ? ' disabled' : '') + '>Down</button>' +
                '<button type="button" class="rounded px-2 py-1 text-xs text-muted hover:text-ink" data-step-preview>Show result</button>' +
                '<button type="button" class="rounded px-2 py-1 text-xs text-accent hover:underline" data-step-edit>Edit</button>' +
                '<button type="button" class="rounded px-2 py-1 text-xs text-state-fail hover:underline" data-step-remove>Remove</button>' +
                '</div></div>' +
                '<div hidden class="mt-3 rounded-md border border-line bg-paper-raised p-3" data-step-result>' +
                '<div class="flex items-center justify-between gap-2">' +
                '<p class="text-xs font-medium tracking-wide text-muted uppercase">Result after step ' + (index + 1) + '</p>' +
                '<button type="button" class="text-xs text-muted hover:text-ink" data-step-collapse>Collapse</button>' +
                '</div>' +
                '<pre class="mt-2 whitespace-pre-wrap text-sm text-ink" data-step-result-body></pre>' +
                '</div>';
            stepsEl.appendChild(card);
        });
        if (emptyEl) emptyEl.hidden = steps.length > 0;
    }

    function sourceOptions() {
        return ((state.source && state.source.schema && state.source.schema.fields) || []).map(function (f) {
            return { value: f.name, label: f.label + ' · ' + f.type };
        });
    }

    function destinationOptions() {
        return ((state.destination && state.destination.schema && state.destination.schema.fields) || []).map(function (f) {
            return { value: f.name, label: f.label + ' · ' + f.type + (f.required ? ' · required' : '') };
        });
    }

    function selectHtml(name, options, selected, label) {
        const opts = options.map(function (o) {
            return '<option value="' + escapeHtml(o.value) + '"' + (o.value === selected ? ' selected' : '') + '>' + escapeHtml(o.label) + '</option>';
        }).join('');
        return '<label class="flex flex-col gap-1 text-sm"><span class="font-medium">' + escapeHtml(label) + '</span>' +
            '<select name="' + escapeHtml(name) + '" class="rounded-md border border-line bg-paper-raised px-3 py-2 text-sm">' +
            '<option value="">Choose…</option>' + opts + '</select></label>';
    }

    function inputHtml(name, label, value) {
        return '<label class="flex flex-col gap-1 text-sm"><span class="font-medium">' + escapeHtml(label) + '</span>' +
            '<input name="' + escapeHtml(name) + '" value="' + escapeHtml(value != null ? value : '') + '" class="rounded-md border border-line bg-paper-raised px-3 py-2 text-sm" /></label>';
    }

    function textareaHtml(name, label, value) {
        return '<label class="flex flex-col gap-1 text-sm"><span class="font-medium">' + escapeHtml(label) + '</span>' +
            '<textarea name="' + escapeHtml(name) + '" rows="3" class="rounded-md border border-line bg-paper-raised px-3 py-2 text-sm">' + escapeHtml(value != null ? value : '') + '</textarea>' +
            '<span class="flex flex-wrap gap-2 text-xs text-muted">' +
            '<button type="button" class="underline" data-insert="space">space</button>' +
            '<button type="button" class="underline" data-insert="lf">line break</button>' +
            '<button type="button" class="underline" data-insert="para">paragraph break</button>' +
            '</span></label>';
    }

    function openEditor(operation, step) {
        editingId = step ? step.id : null;
        currentOperation = operation;
        const cfg = step ? (step.config || {}) : {};
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
        if (editorEl) editorEl.hidden = false;
        if (addMenu) addMenu.hidden = true;
        if (addBtn) addBtn.setAttribute('aria-expanded', 'false');

        if (editorFields) {
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
        const config = readEditorConfig();
        if (editingId) {
            const idx = steps.findIndex(function (s) { return s.id === editingId; });
            if (idx >= 0) {
                steps[idx] = { id: steps[idx].id, operation: currentOperation, order: idx, config: config };
            }
        } else {
            steps.push({ id: cryptoRandom(), operation: currentOperation, order: steps.length, config: config });
        }
        editingId = null;
        currentOperation = null;
        if (editorEl) editorEl.hidden = true;
        renderSteps();
        schedulePreview();
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
                    const text = (data.human && data.human.text != null) ? data.human.text : JSON.stringify(data.data || {}, null, 2);
                    body.textContent = text || '(empty)';
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
            if (saveBtn) {
                const blocking = (data.errors || []).some(function (e) {
                    return e.type === 'required' || e.type === 'type' || e.type === 'config';
                });
                saveBtn.disabled = blocking && steps.length > 0;
                saveBtn.title = blocking ? 'Fix mapping problems before saving.' : '';
            }
        }
    }

    async function saveMapping() {
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
            if (saveStatus) saveStatus.textContent = 'Saved.';
            if (autoEl) autoEl.hidden = true;
        } catch (e) {
            if (saveStatus) saveStatus.textContent = 'Could not save.';
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
        if (count > 0) {
            autoText.textContent = count === 1 ? '1 field mapped automatically.' : (count + ' fields mapped automatically.');
            autoEl.hidden = false;
        }
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            if (!addMenu) return;
            const open = addMenu.hidden;
            addMenu.hidden = !open;
            addBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                addMenu.innerHTML = (state.operations || []).map(function (op) {
                    return '<button type="button" role="menuitem" class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-paper" data-op="' + escapeHtml(op.id) + '">' + escapeHtml(op.label) + '</button>';
                }).join('');
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

    const cancelBtn = root.querySelector('[data-mapping-step-cancel]');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            editingId = null;
            currentOperation = null;
            if (editorEl) editorEl.hidden = true;
        });
    }

    const stepSaveBtn = root.querySelector('[data-mapping-step-save]');
    if (stepSaveBtn) {
        stepSaveBtn.addEventListener('click', saveEditor);
    }

    if (stepsEl) {
        stepsEl.addEventListener('click', function (event) {
            const card = event.target.closest('[data-step-id]');
            if (!card) return;
            const id = card.getAttribute('data-step-id');
            const index = steps.findIndex(function (s) { return s.id === id; });
            if (index < 0) return;

            if (event.target.closest('[data-step-edit]')) {
                openEditor(steps[index].operation, steps[index]);
                return;
            }
            if (event.target.closest('[data-step-remove]')) {
                steps.splice(index, 1);
                renderSteps();
                schedulePreview();
                return;
            }
            if (event.target.closest('[data-step-up]') && index > 0) {
                const tmp = steps[index - 1];
                steps[index - 1] = steps[index];
                steps[index] = tmp;
                renderSteps();
                schedulePreview();
                return;
            }
            if (event.target.closest('[data-step-down]') && index < steps.length - 1) {
                const tmp = steps[index + 1];
                steps[index + 1] = steps[index];
                steps[index] = tmp;
                renderSteps();
                schedulePreview();
                return;
            }
            if (event.target.closest('[data-step-preview]')) {
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

    if (saveBtn) saveBtn.addEventListener('click', saveMapping);

    const resetBtn = root.querySelector('[data-mapping-reset-defaults]');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            const defaults = state.provider_default || state.default_mapping;
            steps = ((defaults && defaults.steps) ? defaults.steps : []).map(normalizeStep);
            renderSteps();
            schedulePreview();
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
