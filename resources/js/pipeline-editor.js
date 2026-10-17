/*
 * Pipeline editor for visual broadcasts.
 *
 * The canvas is a pannable, zoomable world of typed nodes joined by directed
 * edges. The document (nodes, edges, viewport) is posted whole to the
 * pipeline endpoint, which compiles it into routes and answers with per-node
 * issues and route state. Everything else the editor does — testing, running,
 * pausing, adding or editing sources and destinations, reading activity,
 * changing settings — talks to the resources that already exist for the
 * broadcast, asking for JSON, so the canvas never reloads. Nothing here
 * publishes anything: a test is a dry run on the server.
 *
 * Positions are written through the CSSOM (style properties), never as style
 * attributes, so the page stays within the production CSP.
 */
(function () {
    const root = document.querySelector('[data-pipeline-editor]');

    if (root === null) {
        return;
    }

    let payload;

    try {
        payload = JSON.parse(root.getAttribute('data-pipeline-editor') || '{}');
    } catch {
        return;
    }

    if (!payload || !payload.graph) {
        return;
    }

    /*
     * Node footprint. The CSS sets the same values (--node-w, --node-h), so
     * edges can be computed from constants and never from measured boxes:
     * measuring made two connections into one node land a pixel apart.
     */
    const NODE_WIDTH = 216;
    const NODE_HEIGHT = 72;
    const HANDLE_R = 7;
    const GRID = 8;
    const ZOOM_MIN = 0.25;
    const ZOOM_MAX = 2.5;
    const SAVE_DELAY = 700;
    const HISTORY_LIMIT = 100;
    const DRAG_THRESHOLD = 4;

    const canEdit = payload.can_edit === true;
    const catalog = payload.catalog || {};
    const urls = payload.urls || {};
    const permissions = payload.permissions || {};
    const settingsData = payload.settings || {};
    const nodeTypes = new Map((catalog.node_types || []).map((type) => [type.type, type]));
    const filterRuleTypes = catalog.filter_rule_types || [];
    const transformRuleTypes = catalog.transform_rule_types || [];
    const filterFields = catalog.filter_fields || {};
    const placeholders = catalog.placeholders || [];
    const sourceTypes = catalog.source_types || [];
    const destinationTypes = catalog.destination_types || [];

    const els = {
        canvas: root.querySelector('[data-canvas]'),
        world: root.querySelector('[data-world]'),
        edgeLayer: root.querySelector('[data-edge-layer]'),
        draftEdge: root.querySelector('[data-draft-edge]'),
        nodesLayer: root.querySelector('[data-nodes]'),
        inspector: root.querySelector('[data-inspector]'),
        emptyState: root.querySelector('[data-empty-state]'),
        zoomLevel: root.querySelector('[data-zoom-level]'),
        undo: root.querySelector('[data-undo]'),
        redo: root.querySelector('[data-redo]'),
        palette: root.querySelector('[data-palette]'),
        ghost: root.querySelector('[data-palette-ghost]'),
        toast: root.querySelector('[data-pipeline-toast]'),
        toastText: root.querySelector('[data-pipeline-toast-text]'),
        name: document.querySelector('[data-pipeline-name]'),
        saveStatus: document.querySelector('[data-pipeline-save-status]'),
        saveText: document.querySelector('[data-pipeline-save-text]'),
        issues: document.querySelector('[data-pipeline-issues]'),
        issuesCount: document.querySelector('[data-pipeline-issues-count]'),
        issuesLabel: document.querySelector('[data-pipeline-issues-label]'),
        status: document.querySelector('[data-pipeline-status]'),
        lifecycle: document.querySelector('[data-pipeline-lifecycle]'),
        test: document.querySelector('[data-pipeline-test]'),
    };

    if (!els.canvas || !els.world || !els.edgeLayer || !els.nodesLayer || !els.inspector) {
        return;
    }

    /* ------------------------------------------------------------------ */
    /* State                                                                */
    /* ------------------------------------------------------------------ */

    const state = {
        nodes: new Map(),
        edges: new Map(),
    };
    const view = { x: 0, y: 0, zoom: 1 };
    const selection = { nodes: new Set(), edge: null };
    const nodeEls = new Map();
    const history = { past: [], future: [] };

    let issues = Array.isArray(payload.issues) ? payload.issues : [];
    let routes = Array.isArray(payload.routes) ? payload.routes : [];
    let project = payload.project || {};
    let sources = Array.isArray(payload.sources) ? payload.sources : [];
    let destinations = Array.isArray(payload.destinations) ? payload.destinations : [];
    const traces = new Map((Array.isArray(payload.traces) ? payload.traces : []).map((trace) => [trace.edge_id, trace]));

    let dirty = false;
    let saving = false;
    let pendingSave = false;
    let saveTimer = null;
    let liveEditKey = null;
    let toastTimer = null;
    let spaceHeld = false;
    let drag = null;
    let paletteDragHandled = false;
    let lastTest = null;
    let testing = false;
    const lifecycleBusy = new Set();
    const titleSuffix = document.title.includes(' - ') ? document.title.slice(document.title.lastIndexOf(' - ')) : '';

    /*
     * The inspector shows one context at a time. `serial` changes whenever
     * the context changes, so an async answer can tell whether the panel
     * still wants it. Fetched data lives in caches keyed by resource, not by
     * panel, so a stale response never lands on the wrong node or edge.
     */
    const panel = {
        kind: null,
        nodeId: null,
        edgeId: null,
        tab: 'configure',
        mode: 'view',
        formType: null,
        formDirty: false,
        busy: false,
        errors: {},
        notice: null,
        serial: 0,
    };
    const summaryCache = new Map();
    const traceCache = new Map();
    const inflight = new Set();
    let runsCache = null;

    (payload.graph.nodes || []).forEach((node) => {
        state.nodes.set(node.id, {
            id: node.id,
            type: node.type,
            x: Number(node.x) || 0,
            y: Number(node.y) || 0,
            config: node.config && typeof node.config === 'object' ? { ...node.config } : {},
        });
    });

    (payload.graph.edges || []).forEach((edge) => {
        if (state.nodes.has(edge.from) && state.nodes.has(edge.to)) {
            state.edges.set(edge.id, { id: edge.id, from: edge.from, to: edge.to });
        }
    });

    /* ------------------------------------------------------------------ */
    /* Helpers                                                              */
    /* ------------------------------------------------------------------ */

    const ICONS = {
        source: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="size-4"><path d="M12 3.5v10" /><path d="m8 10 4 4 4-4" /><path d="M5 17.5h14" /><path d="M7 20.5h10" /></svg>',
        filter: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="size-4"><path d="M4 5h16l-6.5 7.5V19l-3 1.5V12.5L4 5Z" /></svg>',
        transform: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="size-4"><path d="M3.5 7h10" /><circle cx="17" cy="7" r="2.5" /><path d="M20.5 17h-10" /><circle cx="7" cy="17" r="2.5" /></svg>',
        destination: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="size-4"><path d="M12 20.5V10.5" /><path d="m8 14 4-4 4 4" /><path d="M5 6.5h14" /><path d="M7 3.5h10" /></svg>',
    };
    const CLOSE_ICON = '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>';
    const SPINNER = '<svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path class="opacity-30" d="M12 3a9 9 0 1 0 9 9" /><path d="M21 12a9 9 0 0 0-9-9" /></svg>';

    const INPUT_CLASS = 'w-full rounded-md border border-line bg-paper-raised px-2.5 py-1.5 text-sm text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent disabled:opacity-60';
    const LABEL_CLASS = 'text-xs font-medium text-ink';
    const HINT_CLASS = 'text-xs text-muted';
    const GHOST_BUTTON_CLASS = 'inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-xs font-medium text-ink hover:border-line-strong focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-50';
    const PRIMARY_BUTTON_CLASS = 'inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-accent px-3 py-1.5 text-xs font-medium text-white hover:bg-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-accent';
    const LINK_CLASS = 'cursor-pointer text-xs text-accent underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent';
    const KICKER_CLASS = 'font-mono text-[0.5625rem] tracking-[0.14em] uppercase';
    const ERROR_CLASS = 'text-xs text-state-fail';

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function uid(prefix) {
        if (window.crypto && window.crypto.randomUUID) {
            return prefix + '-' + window.crypto.randomUUID().slice(0, 8);
        }

        return prefix + '-' + Math.random().toString(36).slice(2, 10);
    }

    function snap(value) {
        return Math.round(value / GRID) * GRID;
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function plural(count, singular, pluralForm) {
        return count + ' ' + (count === 1 ? singular : pluralForm || singular + 's');
    }

    function formatTime(iso) {
        if (!iso) {
            return '';
        }

        const date = new Date(iso);

        if (Number.isNaN(date.getTime())) {
            return String(iso);
        }

        const now = new Date();
        const sameDay = date.toDateString() === now.toDateString();
        const time = date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });

        return sameDay ? time : date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' ' + time;
    }

    function typeDef(type) {
        return nodeTypes.get(type) || { type, label: type, category: 'transform', has_input: true, has_output: true, accepts: [] };
    }

    function sourceById(id) {
        return sources.find((source) => Number(source.id) === Number(id)) || null;
    }

    function destinationById(id) {
        return destinations.find((destination) => Number(destination.id) === Number(id)) || null;
    }

    function recordFor(node) {
        if (node.type === 'source') {
            return sourceById(node.config.source_id);
        }

        if (node.type === 'destination') {
            return destinationById(node.config.destination_id);
        }

        return null;
    }

    function providerType(kind, type) {
        return (kind === 'source' ? sourceTypes : destinationTypes).find((entry) => entry.type === type) || null;
    }

    function nodesUsing(kind, id) {
        const key = kind === 'source' ? 'source_id' : 'destination_id';

        return Array.from(state.nodes.values()).filter((node) => node.type === kind && String(node.config[key]) === String(id));
    }

    function nodeTitle(node) {
        switch (node.type) {
            case 'source': {
                const source = sourceById(node.config.source_id);

                return source ? source.name : 'Choose a source';
            }
            case 'destination': {
                const destination = destinationById(node.config.destination_id);

                return destination ? destination.name : 'Choose a destination';
            }
            case 'filter':
                return node.config.label || 'Filter';
            case 'transform':
                return node.config.label || 'Transform';
            default:
                return typeDef(node.type).label;
        }
    }

    function filterRuleSummary(rule) {
        const def = filterRuleTypes.find((type) => type.value === rule.type);

        if (!def) {
            return 'Rule';
        }

        if (def.needs_hours) {
            return def.label + ' ' + (rule.hours || '…') + ' h';
        }

        const field = def.needs_field && filterFields[rule.field] ? filterFields[rule.field] : null;
        const label = field ? def.label.replace('Title or body', field) : def.label;

        return label + ' “' + (rule.value || '…') + '”';
    }

    function nodeMeta(node) {
        switch (node.type) {
            case 'source': {
                const source = sourceById(node.config.source_id);

                return source ? source.type_label + (source.archived ? ' · archived' : '') : 'No source selected';
            }
            case 'destination': {
                const destination = destinationById(node.config.destination_id);

                return destination ? destination.type_label + (destination.archived ? ' · archived' : '') : 'No destination selected';
            }
            case 'filter': {
                const rules = Array.isArray(node.config.rules) ? node.config.rules : [];

                if (rules.length === 0) {
                    return 'No rules yet';
                }

                return rules.length === 1 ? filterRuleSummary(rules[0]) : rules.length + ' rules, all must match';
            }
            case 'transform': {
                const template = String(node.config.template || '').trim();
                const rules = Array.isArray(node.config.rules) ? node.config.rules : [];
                const first = template.split('\n').find((line) => line.trim() !== '') || '';
                const parts = [];

                if (first !== '') {
                    parts.push(first.length > 36 ? first.slice(0, 34) + '…' : first);
                } else if (rules.length === 0) {
                    parts.push('Empty transform');
                } else {
                    parts.push('Default template');
                }

                if (rules.length > 0) {
                    parts.push(plural(rules.length, 'rule'));
                }

                return parts.join(' · ');
            }
            default:
                return '';
        }
    }

    function issuesFor(nodeId) {
        return issues.filter((issue) => issue.node_id === nodeId);
    }

    function routesTo(nodeId) {
        return routes.filter((route) => route.destination_node_id === nodeId);
    }

    function completeEdgeKeys() {
        const keys = new Set();

        routes.forEach((route) => {
            const ids = route.node_ids || [];

            for (let index = 0; index < ids.length - 1; index++) {
                keys.add(ids[index] + '>' + ids[index + 1]);
            }
        });

        return keys;
    }

    function hasEdge(from, to) {
        for (const edge of state.edges.values()) {
            if (edge.from === from && edge.to === to) {
                return true;
            }
        }

        return false;
    }

    function connectBlockReason(fromId, toId) {
        if (fromId === toId) {
            return 'A node cannot connect to itself.';
        }

        const from = state.nodes.get(fromId);
        const to = state.nodes.get(toId);

        if (!from || !to) {
            return 'Unknown node.';
        }

        const fromDef = typeDef(from.type);
        const toDef = typeDef(to.type);

        if (!fromDef.has_output) {
            return 'A ' + fromDef.label.toLowerCase() + ' has no output.';
        }

        if (!toDef.has_input) {
            return 'A ' + toDef.label.toLowerCase() + ' has no input.';
        }

        if (!(toDef.accepts || []).includes(from.type)) {
            return 'A ' + toDef.label.toLowerCase() + ' cannot follow a ' + fromDef.label.toLowerCase() + '.';
        }

        if (hasEdge(fromId, toId)) {
            return 'These nodes are already connected.';
        }

        if (wouldCreateCycle(fromId, toId)) {
            return 'That would send content round in a loop.';
        }

        return null;
    }

    function canConnect(fromId, toId) {
        return connectBlockReason(fromId, toId) === null;
    }

    function wouldCreateCycle(fromId, toId) {
        const stack = [toId];
        const seen = new Set();

        while (stack.length > 0) {
            const current = stack.pop();

            if (current === fromId) {
                return true;
            }

            if (seen.has(current)) {
                continue;
            }

            seen.add(current);

            for (const edge of state.edges.values()) {
                if (edge.from === current) {
                    stack.push(edge.to);
                }
            }
        }

        return false;
    }

    function requestHeaders() {
        return {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': payload.csrf,
            'X-Requested-With': 'XMLHttpRequest',
        };
    }

    /*
     * One place for every JSON call: same headers, same session handling,
     * and a parsed body whatever the status, so callers only look at
     * `ok`, `status` and `data`.
     */
    async function request(url, { method = 'GET', body = undefined } = {}) {
        const response = await fetch(url, {
            method,
            headers: requestHeaders(),
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));

        if (response.status === 419 || response.status === 401) {
            toast('Your session expired. Reload the page to keep going.');
        }

        return { ok: response.ok, status: response.status, data };
    }

    function firstError(data, fallback) {
        if (data && data.errors) {
            const first = Object.values(data.errors).flat()[0];

            if (first) {
                return first;
            }
        }

        return (data && data.message) || fallback;
    }

    /* ------------------------------------------------------------------ */
    /* Viewport                                                             */
    /* ------------------------------------------------------------------ */

    function toWorld(clientX, clientY) {
        const rect = els.canvas.getBoundingClientRect();

        return {
            x: (clientX - rect.left - view.x) / view.zoom,
            y: (clientY - rect.top - view.y) / view.zoom,
        };
    }

    function applyView() {
        els.world.style.transform = 'translate(' + view.x + 'px, ' + view.y + 'px) scale(' + view.zoom + ')';
        els.canvas.style.setProperty('--grid-size', 24 * view.zoom + 'px');
        els.canvas.style.setProperty('--grid-offset-x', view.x + 'px');
        els.canvas.style.setProperty('--grid-offset-y', view.y + 'px');

        if (els.zoomLevel) {
            els.zoomLevel.textContent = Math.round(view.zoom * 100) + '%';
        }

        try {
            window.sessionStorage.setItem(viewStorageKey(), JSON.stringify(view));
        } catch {
            // Storage may be unavailable; the viewport is also saved with the graph.
        }
    }

    function viewStorageKey() {
        return 'michicast.pipeline.view.' + (project.id || 'x');
    }

    function zoomAt(clientX, clientY, factor) {
        const before = toWorld(clientX, clientY);
        view.zoom = clamp(view.zoom * factor, ZOOM_MIN, ZOOM_MAX);
        const rect = els.canvas.getBoundingClientRect();
        view.x = clientX - rect.left - before.x * view.zoom;
        view.y = clientY - rect.top - before.y * view.zoom;
        applyView();
    }

    function zoomCenter(factor) {
        const rect = els.canvas.getBoundingClientRect();
        zoomAt(rect.left + rect.width / 2, rect.top + rect.height / 2, factor);
    }

    function contentBounds() {
        if (state.nodes.size === 0) {
            return null;
        }

        let minX = Infinity;
        let minY = Infinity;
        let maxX = -Infinity;
        let maxY = -Infinity;

        state.nodes.forEach((node) => {
            minX = Math.min(minX, node.x);
            minY = Math.min(minY, node.y);
            maxX = Math.max(maxX, node.x + NODE_WIDTH);
            maxY = Math.max(maxY, node.y + NODE_HEIGHT);
        });

        return { minX, minY, maxX, maxY, width: maxX - minX, height: maxY - minY };
    }

    /*
     * The area of the canvas not covered by floating panels: the toolbox on
     * the left and, when open, the inspector on the right.
     */
    function visibleArea() {
        const rect = els.canvas.getBoundingClientRect();
        const paletteOpen = els.palette && window.getComputedStyle(els.palette).display !== 'none';
        const left = paletteOpen ? els.palette.offsetWidth + 32 : 0;
        const panelOpen = !els.inspector.hidden;
        const overlay = rect.width < 640;
        const right = panelOpen && !overlay ? els.inspector.offsetWidth + 32 : 0;

        return { left, width: Math.max(240, rect.width - left - right), height: rect.height };
    }

    function fitToContent() {
        const bounds = contentBounds();
        const area = visibleArea();

        if (bounds === null) {
            view.zoom = 1;
            view.x = area.left + Math.max(120, area.width / 2 - 360);
            view.y = Math.max(80, area.height / 2 - 160);
            applyView();

            return;
        }

        const padding = 64;
        const zoom = clamp(
            Math.min((area.width - padding * 2) / bounds.width, (area.height - padding * 2) / bounds.height, 1),
            ZOOM_MIN,
            ZOOM_MAX,
        );

        view.zoom = zoom;
        view.x = area.left + (area.width - bounds.width * zoom) / 2 - bounds.minX * zoom;
        view.y = (area.height - bounds.height * zoom) / 2 - bounds.minY * zoom;
        applyView();
    }

    function centerOn(nodeId) {
        const node = state.nodes.get(nodeId);

        if (!node) {
            return;
        }

        const area = visibleArea();
        view.x = area.left + area.width / 2 - (node.x + NODE_WIDTH / 2) * view.zoom;
        view.y = area.height / 2 - (node.y + NODE_HEIGHT / 2) * view.zoom;
        applyView();
    }

    function viewportCenterWorld() {
        const rect = els.canvas.getBoundingClientRect();

        return toWorld(rect.left + rect.width / 2, rect.top + rect.height / 2);
    }

    function ensureVisible(nodeId) {
        const node = state.nodes.get(nodeId);

        if (!node) {
            return;
        }

        const area = visibleArea();
        const left = view.x + node.x * view.zoom;
        const top = view.y + node.y * view.zoom;
        const right = left + NODE_WIDTH * view.zoom;
        const bottom = top + NODE_HEIGHT * view.zoom;
        const margin = 24;
        const areaRight = area.left + area.width;
        let moved = false;

        if (right > areaRight - margin) {
            view.x -= right - (areaRight - margin);
            moved = true;
        } else if (left < area.left + margin) {
            view.x += area.left + margin - left;
            moved = true;
        }

        if (bottom > area.height - margin) {
            view.y -= bottom - (area.height - margin);
            moved = true;
        } else if (top < margin) {
            view.y += margin - top;
            moved = true;
        }

        if (moved) {
            applyView();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Rendering: nodes                                                     */
    /* ------------------------------------------------------------------ */

    function nodeMarkup(node, def) {
        return (
            '<div class="pipeline-node-body">' +
            '<span class="pipeline-node-tile grid size-8 shrink-0 place-items-center rounded-md">' + (ICONS[node.type] || '') + '</span>' +
            '<div class="min-w-0 flex-1">' +
            '<div class="flex items-center justify-between gap-2">' +
            '<span class="pipeline-node-kicker ' + KICKER_CLASS + '">' + esc(def.label) + '</span>' +
            '<span class="flex items-center gap-1" data-node-flags></span>' +
            '</div>' +
            '<p class="truncate text-sm font-medium leading-5 text-ink" data-node-title></p>' +
            '<p class="truncate text-xs leading-4 text-muted" data-node-meta></p>' +
            '</div>' +
            '</div>'
        );
    }

    function createNodeEl(node) {
        const def = typeDef(node.type);
        const el = document.createElement('div');
        el.className = 'pipeline-node rounded-lg border border-line bg-paper-raised shadow-sm';
        el.dataset.nodeId = node.id;
        el.dataset.nodeCategory = def.category;
        el.tabIndex = 0;
        el.setAttribute('role', 'button');

        const handles = ['left', 'top', 'right', 'bottom']
            .map((side) => '<button type="button" class="pipeline-handle" data-handle data-side="' + side + '" tabindex="-1"></button>')
            .join('');

        el.innerHTML = nodeMarkup(node, def) + handles;

        return el;
    }

    function capturePointer(target, pointerId) {
        try {
            target.setPointerCapture(pointerId);
        } catch {
            // Synthetic or already-released pointers cannot be captured; dragging still works.
        }
    }

    function updateNodeEl(el, node, usedSides) {
        el.style.transform = 'translate(' + node.x + 'px, ' + node.y + 'px)';
        el.classList.toggle('is-selected', selection.nodes.has(node.id));

        const def = typeDef(node.type);
        const title = nodeTitle(node);
        el.querySelector('[data-node-title]').textContent = title;
        el.querySelector('[data-node-meta]').textContent = nodeMeta(node);
        el.setAttribute('aria-label', def.label + ': ' + title);
        el.querySelectorAll('[data-handle]').forEach((handle) => {
            const side = handle.getAttribute('data-side');
            handle.classList.toggle('is-used', usedSides.has(node.id + ':' + side));
            handle.setAttribute('aria-label', (def.has_output ? 'Connect from ' : 'Connect into ') + title + ' (' + side + ')');
        });

        const flags = el.querySelector('[data-node-flags]');
        const nodeIssues = issuesFor(node.id);
        let html = '';

        if (node.type === 'destination') {
            routesTo(node.id).forEach((route) => {
                const tone = route.paused_reason ? 'warn' : route.enabled ? 'ok' : 'skip';
                const label = route.paused_reason ? 'Paused' : route.enabled ? 'Live' : 'Disabled';
                html += '<span class="inline-block size-2 rounded-full bg-state-' + tone + '" title="' + esc(label + ' · ' + route.name) + '"></span>';
            });
        }

        if (nodeIssues.length > 0) {
            const hasError = nodeIssues.some((issue) => issue.level === 'error');
            const tone = hasError ? 'fail' : 'warn';
            html +=
                '<span class="grid size-4 place-items-center rounded-full bg-state-' + tone + '/15 font-mono text-[0.625rem] font-semibold leading-none text-state-' + tone + '" title="' +
                esc(nodeIssues.map((issue) => issue.message).join('\n')) +
                '" aria-label="' + esc(plural(nodeIssues.length, 'issue')) + '">!</span>';
        }

        flags.innerHTML = html;
    }

    function usedHandleSides() {
        const used = new Set();

        state.edges.forEach((edge) => {
            const from = state.nodes.get(edge.from);
            const to = state.nodes.get(edge.to);

            if (from && to) {
                const sides = anchorSides(from, to);
                used.add(from.id + ':' + sides.from);
                used.add(to.id + ':' + sides.to);
            }
        });

        return used;
    }

    function renderNodes() {
        const seen = new Set();
        const used = usedHandleSides();

        state.nodes.forEach((node) => {
            let el = nodeEls.get(node.id);

            if (!el) {
                el = createNodeEl(node);
                nodeEls.set(node.id, el);
                els.nodesLayer.appendChild(el);
            }

            updateNodeEl(el, node, used);
            seen.add(node.id);
        });

        nodeEls.forEach((el, id) => {
            if (!seen.has(id)) {
                el.remove();
                nodeEls.delete(id);
            }
        });

        if (els.emptyState) {
            els.emptyState.hidden = state.nodes.size > 0;
        }
    }

    function positionNodeEl(node) {
        const el = nodeEls.get(node.id);

        if (el) {
            el.style.transform = 'translate(' + node.x + 'px, ' + node.y + 'px)';
        }
    }

    /* ------------------------------------------------------------------ */
    /* Rendering: edges                                                     */
    /* ------------------------------------------------------------------ */

    const OPPOSITE = { left: 'right', right: 'left', top: 'bottom', bottom: 'top' };

    /*
     * Which side each end of a connection leaves from and arrives at. The
     * dominant direction between the two centres decides: a target to the
     * right is entered from its left, a target below from its top, and so
     * on. Handles exist on all four sides, so nothing depends on layout.
     */
    function anchorSides(from, to) {
        const dx = to.x - from.x;
        const dy = to.y - from.y;

        if (Math.abs(dx) >= Math.abs(dy) || Math.abs(dy) < NODE_HEIGHT) {
            return dx >= 0 ? { from: 'right', to: 'left' } : { from: 'left', to: 'right' };
        }

        return dy >= 0 ? { from: 'bottom', to: 'top' } : { from: 'top', to: 'bottom' };
    }

    /* Centre of the handle on a side, which sits exactly on the border. */
    function sidePoint(node, side) {
        switch (side) {
            case 'left':
                return { x: node.x, y: node.y + NODE_HEIGHT / 2 };
            case 'right':
                return { x: node.x + NODE_WIDTH, y: node.y + NODE_HEIGHT / 2 };
            case 'top':
                return { x: node.x + NODE_WIDTH / 2, y: node.y };
            default:
                return { x: node.x + NODE_WIDTH / 2, y: node.y + NODE_HEIGHT };
        }
    }

    function outward(point, side, distance) {
        switch (side) {
            case 'left':
                return { x: point.x - distance, y: point.y };
            case 'right':
                return { x: point.x + distance, y: point.y };
            case 'top':
                return { x: point.x, y: point.y - distance };
            default:
                return { x: point.x, y: point.y + distance };
        }
    }

    /* Where a connection touches a node: the rim of the handle, not its centre. */
    function rimPoint(node, side) {
        return outward(sidePoint(node, side), side, HANDLE_R);
    }

    /*
     * Orthogonal connector between two rim points with rounded corners.
     * Horizontal runs bend a fixed distance before the target, so several
     * connections into one node share their last vertical leg and meet as
     * one line. Aligned ends draw a straight line.
     */
    function edgePath(a, aSide, b, bSide) {
        const stub = 20;
        const approach = 28;
        const points = [a];

        if (aSide === 'right' && bSide === 'left') {
            if (Math.abs(a.y - b.y) < 0.5 && b.x >= a.x) {
                return 'M' + a.x + ' ' + a.y + ' L' + b.x + ' ' + b.y;
            }

            if (b.x - a.x >= stub + approach) {
                const x = b.x - approach;
                points.push({ x, y: a.y }, { x, y: b.y });
            } else {
                const midY = Math.abs(b.y - a.y) > NODE_HEIGHT + 24 ? (a.y + b.y) / 2 : Math.max(a.y, b.y) + NODE_HEIGHT / 2 + 24;
                points.push({ x: a.x + stub, y: a.y }, { x: a.x + stub, y: midY }, { x: b.x - stub, y: midY }, { x: b.x - stub, y: b.y });
            }
        } else if (aSide === 'left' && bSide === 'right') {
            if (Math.abs(a.y - b.y) < 0.5 && b.x <= a.x) {
                return 'M' + a.x + ' ' + a.y + ' L' + b.x + ' ' + b.y;
            }

            if (a.x - b.x >= stub + approach) {
                const x = b.x + approach;
                points.push({ x, y: a.y }, { x, y: b.y });
            } else {
                const midY = Math.abs(b.y - a.y) > NODE_HEIGHT + 24 ? (a.y + b.y) / 2 : Math.max(a.y, b.y) + NODE_HEIGHT / 2 + 24;
                points.push({ x: a.x - stub, y: a.y }, { x: a.x - stub, y: midY }, { x: b.x + stub, y: midY }, { x: b.x + stub, y: b.y });
            }
        } else if (aSide === 'bottom' && bSide === 'top') {
            if (Math.abs(a.x - b.x) < 0.5 && b.y >= a.y) {
                return 'M' + a.x + ' ' + a.y + ' L' + b.x + ' ' + b.y;
            }

            if (b.y - a.y >= stub + approach) {
                const y = b.y - approach;
                points.push({ x: a.x, y }, { x: b.x, y });
            } else {
                const midX = Math.abs(b.x - a.x) > NODE_WIDTH + 24 ? (a.x + b.x) / 2 : Math.max(a.x, b.x) + NODE_WIDTH / 2 + 24;
                points.push({ x: a.x, y: a.y + stub }, { x: midX, y: a.y + stub }, { x: midX, y: b.y - stub }, { x: b.x, y: b.y - stub });
            }
        } else if (aSide === 'top' && bSide === 'bottom') {
            if (Math.abs(a.x - b.x) < 0.5 && b.y <= a.y) {
                return 'M' + a.x + ' ' + a.y + ' L' + b.x + ' ' + b.y;
            }

            if (a.y - b.y >= stub + approach) {
                const y = b.y + approach;
                points.push({ x: a.x, y }, { x: b.x, y });
            } else {
                const midX = Math.abs(b.x - a.x) > NODE_WIDTH + 24 ? (a.x + b.x) / 2 : Math.max(a.x, b.x) + NODE_WIDTH / 2 + 24;
                points.push({ x: a.x, y: a.y - stub }, { x: midX, y: a.y - stub }, { x: midX, y: b.y + stub }, { x: b.x, y: b.y + stub });
            }
        } else {
            // Mixed axes only happen for the preview line following the pointer.
            const a1 = outward(a, aSide, stub);
            const b1 = outward(b, bSide, stub);
            points.push(a1);

            if (aSide === 'left' || aSide === 'right') {
                points.push({ x: a1.x, y: b1.y });
            } else {
                points.push({ x: b1.x, y: a1.y });
            }

            points.push(b1);
        }

        points.push(b);

        return roundedPolyline(points, 10);
    }

    function roundedPolyline(points, radius) {
        let d = 'M' + points[0].x + ' ' + points[0].y;

        for (let index = 1; index < points.length - 1; index++) {
            const previous = points[index - 1];
            const corner = points[index];
            const next = points[index + 1];
            const inLength = Math.hypot(corner.x - previous.x, corner.y - previous.y);
            const outLength = Math.hypot(next.x - corner.x, next.y - corner.y);
            const r = Math.min(radius, inLength / 2, outLength / 2);
            const straight = (previous.x === corner.x && corner.x === next.x) || (previous.y === corner.y && corner.y === next.y);

            if (r < 0.5 || straight) {
                d += ' L' + corner.x + ' ' + corner.y;

                continue;
            }

            const inX = corner.x - ((corner.x - previous.x) / inLength) * r;
            const inY = corner.y - ((corner.y - previous.y) / inLength) * r;
            const outX = corner.x + ((next.x - corner.x) / outLength) * r;
            const outY = corner.y + ((next.y - corner.y) / outLength) * r;

            d += ' L' + inX + ' ' + inY + ' Q' + corner.x + ' ' + corner.y + ' ' + outX + ' ' + outY;
        }

        const last = points[points.length - 1];

        return d + ' L' + last.x + ' ' + last.y;
    }

    /* A point halfway along a path, for the trace badge. */
    function pathMidpoint(pathEl) {
        try {
            const length = pathEl.getTotalLength();
            const point = pathEl.getPointAtLength(length / 2);

            return { x: point.x, y: point.y };
        } catch {
            return null;
        }
    }

    function edgeGeometry(edge) {
        const from = state.nodes.get(edge.from);
        const to = state.nodes.get(edge.to);
        const sides = anchorSides(from, to);

        return edgePath(rimPoint(from, sides.from), sides.from, rimPoint(to, sides.to), sides.to);
    }

    function renderEdges() {
        const complete = completeEdgeKeys();
        const fragment = document.createDocumentFragment();
        const badges = [];

        state.edges.forEach((edge) => {
            if (!state.nodes.has(edge.from) || !state.nodes.has(edge.to)) {
                return;
            }

            const d = edgeGeometry(edge);
            const trace = traces.get(edge.id) || null;
            const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            group.classList.add('pipeline-edge-group');
            group.dataset.edgeId = edge.id;

            if (selection.edge === edge.id) {
                group.classList.add('is-selected');
            }

            const hit = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            hit.setAttribute('class', 'pipeline-edge-hit');
            hit.setAttribute('d', d);

            const line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            const incomplete = complete.size > 0 && !complete.has(edge.from + '>' + edge.to);
            const failed = trace && trace.status === 'failed';
            line.setAttribute('class', 'pipeline-edge' + (incomplete ? ' is-incomplete' : '') + (failed ? ' is-failed' : ''));
            line.setAttribute('d', d);
            line.setAttribute(
                'marker-end',
                selection.edge === edge.id ? 'url(#pipeline-arrow-accent)' : failed ? 'url(#pipeline-arrow-fail)' : 'url(#pipeline-arrow)',
            );

            group.append(hit, line);

            if (trace && (trace.status === 'flowed' || trace.status === 'failed' || trace.status === 'empty')) {
                badges.push({ group, line, trace });
            }

            fragment.appendChild(group);
        });

        els.edgeLayer.replaceChildren(fragment);

        // Badges need the path in the document to measure its midpoint.
        badges.forEach(({ group, line, trace }) => {
            const mid = pathMidpoint(line);

            if (!mid) {
                return;
            }

            const label = trace.status === 'failed' ? '!' : String(trace.items_out);
            const width = Math.max(18, 8 + label.length * 7);
            const badge = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            badge.setAttribute('class', 'pipeline-edge-badge' + (trace.status === 'empty' ? ' is-empty' : '') + (trace.status === 'failed' ? ' is-failed' : ''));
            badge.setAttribute('transform', 'translate(' + (mid.x - width / 2) + ' ' + (mid.y - 9) + ')');

            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('width', String(width));
            rect.setAttribute('height', '18');
            rect.setAttribute('rx', '9');

            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', String(width / 2));
            text.setAttribute('y', '9');
            text.textContent = label;

            const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            title.textContent = trace.status_label + ' in the latest test';

            badge.append(rect, text, title);
            group.appendChild(badge);
        });
    }

    function showDraftEdge(d, tone) {
        if (!els.draftEdge) {
            return;
        }

        els.draftEdge.setAttribute('d', d);
        els.draftEdge.classList.toggle('is-valid', tone === 'valid');
        els.draftEdge.classList.toggle('is-invalid', tone === 'invalid');
        els.draftEdge.removeAttribute('hidden');
    }

    function hideDraftEdge() {
        if (els.draftEdge) {
            els.draftEdge.setAttribute('hidden', '');
            els.draftEdge.classList.remove('is-valid', 'is-invalid');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Rendering: header chrome                                             */
    /* ------------------------------------------------------------------ */

    function setSaveStatus(stateName, text, title) {
        if (!els.saveStatus) {
            return;
        }

        els.saveStatus.dataset.state = stateName;
        els.saveStatus.title = title || text;

        if (els.saveText) {
            els.saveText.textContent = text;
        }
    }

    function renderIssues() {
        if (!els.issues) {
            return;
        }

        const errors = issues.filter((issue) => issue.level === 'error').length;
        const total = issues.length;

        els.issues.hidden = total === 0;
        els.issuesCount.textContent = String(total);
        els.issuesLabel.textContent = total === 1 ? 'issue' : 'issues';
        els.issues.title = total === 0 ? '' : errors > 0 ? plural(errors, 'error') + ' to fix before this broadcast can play' : 'Warnings that do not block playing';
        els.issues.classList.toggle('border-state-fail/40', errors > 0);
        els.issues.classList.toggle('bg-state-fail/10', errors > 0);
        els.issues.classList.toggle('text-state-fail', errors > 0);
        els.issues.classList.toggle('border-state-warn/40', errors === 0);
        els.issues.classList.toggle('bg-state-warn/10', errors === 0);
        els.issues.classList.toggle('text-state-warn', errors === 0);

        if (panel.kind === 'issues') {
            renderPanel();
        }
    }

    function renderHistoryButtons() {
        if (els.undo) {
            els.undo.disabled = history.past.length === 0;
        }

        if (els.redo) {
            els.redo.disabled = history.future.length === 0;
        }
    }

    function csrfInput() {
        return '<input type="hidden" name="_token" value="' + esc(payload.csrf) + '">';
    }

    function renderProjectState() {
        if (els.status) {
            els.status.textContent = project.status_label || '';
        }

        if (els.name) {
            els.name.textContent = project.name || '';
            document.title = 'Pipeline · ' + (project.name || '') + titleSuffix;
        }

        if (!els.lifecycle) {
            return;
        }

        const ghost = 'inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-line-strong px-2.5 text-sm font-medium text-ink hover:border-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-50';
        const primary = 'cursor-pointer group inline-flex h-8 items-center justify-center gap-2 rounded-md bg-accent px-3 text-sm font-medium text-white hover:bg-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-accent';
        const paw = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" class="size-4 transition-transform group-hover:-rotate-12"><circle cx="7" cy="9" r="2.2" /><circle cx="12" cy="6.5" r="2.2" /><circle cx="17" cy="9" r="2.2" /><path d="M12 11c-3 0-6 2.6-6 5.2 0 1.6 1.2 2.8 2.8 2.8 1.2 0 1.9-.6 3.2-.6s2 .6 3.2.6c1.6 0 2.8-1.2 2.8-2.8C18 13.6 15 11 12 11Z" /></svg>';
        const busyAttr = (action) => (lifecycleBusy.has(action) ? ' disabled aria-busy="true"' : '');
        let html = '';

        if (permissions.mark_due && project.can_mark_due) {
            html +=
                '<form method="POST" action="' + esc(urls.run_now) + '" data-lifecycle-form="run-now">' + csrfInput() +
                '<button type="submit" class="' + ghost + '" title="Run now: fetch and deliver on the live routes"' + busyAttr('run-now') + '>' +
                (lifecycleBusy.has('run-now') ? SPINNER : '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.6" /><path d="M20 4v5h-5" /></svg>') +
                '<span class="hidden md:inline">Run now</span></button></form>';
        }

        if (permissions.change_status) {
            if (project.status === 'draft') {
                if (project.can_activate) {
                    html += '<form method="POST" action="' + esc(urls.activate) + '" data-lifecycle-form="activate">' + csrfInput() + '<button type="submit" class="' + primary + '"' + busyAttr('activate') + '>' + (lifecycleBusy.has('activate') ? SPINNER : paw) + 'Play</button></form>';
                } else {
                    html +=
                        '<span tabindex="0" aria-describedby="play-disabled-reason" data-tooltip class="group/tooltip relative inline-flex rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">' +
                        '<button type="button" class="' + primary + '" disabled aria-disabled="true" tabindex="-1">Play</button>' +
                        '<span id="play-disabled-reason" role="tooltip" class="pointer-events-none absolute top-full right-0 z-50 mt-2 hidden w-max max-w-64 rounded-md bg-ink px-3 py-2 text-left text-xs font-medium leading-snug text-ink-contrast shadow-lg group-hover/tooltip:block group-focus-within/tooltip:block group-focus/tooltip:block">' +
                        esc(project.activation_block_reason || 'This broadcast cannot play yet.') +
                        '</span></span>';
                }
            } else if (project.can_pause) {
                html += '<form method="POST" action="' + esc(urls.pause) + '" data-lifecycle-form="pause">' + csrfInput() + '<button type="submit" class="' + ghost + '"' + busyAttr('pause') + '>' + (lifecycleBusy.has('pause') ? SPINNER : '') + 'Pause</button></form>';
            } else if (project.can_resume) {
                html += '<form method="POST" action="' + esc(urls.resume) + '" data-lifecycle-form="resume">' + csrfInput() + '<input type="hidden" name="_method" value="DELETE"><button type="submit" class="' + primary + '"' + busyAttr('resume') + '>' + (lifecycleBusy.has('resume') ? SPINNER : paw) + 'Play</button></form>';
            }
        }

        els.lifecycle.innerHTML = html;
    }

    function renderHeaderToggles() {
        document.querySelectorAll('[data-open-panel]').forEach((button) => {
            const kind = button.getAttribute('data-open-panel');

            if (button.hasAttribute('aria-pressed')) {
                button.setAttribute('aria-pressed', panel.kind === kind ? 'true' : 'false');
            }
        });
    }

    function toast(message) {
        if (!els.toast || !els.toastText) {
            return;
        }

        els.toastText.textContent = message;
        els.toast.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            els.toast.hidden = true;
        }, 4500);
    }

    /* ------------------------------------------------------------------ */
    /* Lifecycle actions (run now, play, pause) without leaving the canvas  */
    /* ------------------------------------------------------------------ */

    async function submitLifecycle(form) {
        const action = form.getAttribute('data-lifecycle-form');

        if (!action || lifecycleBusy.has(action)) {
            return;
        }

        if (action === 'run-now' && !window.confirm('Run this broadcast now? Live routes will fetch and deliver for real.')) {
            return;
        }

        const methodInput = form.querySelector('input[name="_method"]');
        const method = methodInput ? methodInput.value.toUpperCase() : 'POST';

        lifecycleBusy.add(action);
        renderProjectState();

        try {
            const { ok, status, data } = await request(form.action, { method, body: {} });

            if (data && data.project) {
                project = data.project;
            }

            if (ok) {
                toast(data.message || 'Done.');

                if (action === 'run-now') {
                    runsCache = null;
                    summaryCache.clear();

                    if (data.run && data.run.url && panel.kind === 'activity') {
                        renderPanel();
                    }
                }
            } else if (status === 429) {
                toast('Too many runs in a row. Try again in a minute.');
            } else if (status === 403) {
                toast('You cannot do that on this broadcast.');
            } else {
                toast(firstError(data, 'That did not work. Try again.'));
            }
        } catch {
            toast('That did not work. Check your connection and try again.');
        } finally {
            lifecycleBusy.delete(action);
            renderProjectState();
        }
    }

    if (els.lifecycle) {
        els.lifecycle.addEventListener('submit', (event) => {
            const form = event.target.closest('[data-lifecycle-form]');

            if (form) {
                event.preventDefault();
                submitLifecycle(form);
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* History and mutation                                                 */
    /* ------------------------------------------------------------------ */

    function snapshot() {
        return JSON.stringify({
            nodes: Array.from(state.nodes.values()),
            edges: Array.from(state.edges.values()),
        });
    }

    function restore(serialized) {
        const data = JSON.parse(serialized);
        state.nodes = new Map(data.nodes.map((node) => [node.id, node]));
        state.edges = new Map(data.edges.map((edge) => [edge.id, edge]));
        selection.nodes.forEach((id) => {
            if (!state.nodes.has(id)) {
                selection.nodes.delete(id);
            }
        });

        if (selection.edge && !state.edges.has(selection.edge)) {
            selection.edge = null;
        }
    }

    function pushHistory() {
        history.past.push(snapshot());

        if (history.past.length > HISTORY_LIMIT) {
            history.past.shift();
        }

        history.future = [];
        renderHistoryButtons();
    }

    function undo() {
        if (history.past.length === 0) {
            return;
        }

        history.future.push(snapshot());
        restore(history.past.pop());
        liveEditKey = null;
        afterChange({ inspector: true });
        syncPanelToSelection();
        renderHistoryButtons();
    }

    function redo() {
        if (history.future.length === 0) {
            return;
        }

        history.past.push(snapshot());
        restore(history.future.pop());
        liveEditKey = null;
        afterChange({ inspector: true });
        syncPanelToSelection();
        renderHistoryButtons();
    }

    function commit(mutator, options = {}) {
        if (!canEdit) {
            return;
        }

        if (options.history !== false) {
            pushHistory();
        }

        mutator();
        afterChange(options);
    }

    function afterChange(options = {}) {
        renderNodes();
        renderEdges();

        if (options.inspector !== false) {
            renderPanel();
        }

        markDirty();
    }

    function markDirty() {
        if (!canEdit) {
            return;
        }

        dirty = true;
        setSaveStatus('dirty', 'Unsaved changes', 'Changes are saved automatically a moment after you stop');
        clearTimeout(saveTimer);
        saveTimer = setTimeout(save, SAVE_DELAY);
    }

    /* ------------------------------------------------------------------ */
    /* Saving                                                               */
    /* ------------------------------------------------------------------ */

    function graphDocument() {
        return {
            version: 1,
            nodes: Array.from(state.nodes.values()).map((node) => ({
                id: node.id,
                type: node.type,
                x: node.x,
                y: node.y,
                config: node.config,
            })),
            edges: Array.from(state.edges.values()),
            viewport: { x: view.x, y: view.y, zoom: view.zoom },
        };
    }

    async function save() {
        if (!canEdit) {
            return;
        }

        clearTimeout(saveTimer);

        if (saving) {
            pendingSave = true;

            return;
        }

        saving = true;
        dirty = false;
        setSaveStatus('saving', 'Saving…');

        try {
            const { ok, status, data } = await request(urls.update, { method: 'PATCH', body: { graph: graphDocument() } });

            if (status === 422) {
                dirty = true;
                setSaveStatus('error', 'Could not save', firstError(data, 'The pipeline could not be saved.'));
                toast(firstError(data, 'The pipeline could not be saved.'));

                return;
            }

            if (status === 419 || status === 401) {
                dirty = true;
                setSaveStatus('error', 'Session expired');

                return;
            }

            if (!ok) {
                throw new Error('Save failed with ' + status);
            }

            issues = Array.isArray(data.issues) ? data.issues : [];
            routes = Array.isArray(data.routes) ? data.routes : [];
            project = data.project || project;

            if (dirty) {
                setSaveStatus('dirty', 'Unsaved changes');
            } else {
                setSaveStatus('saved', 'Saved', 'All changes are saved');
            }

            renderIssues();
            renderNodes();
            renderEdges();
            renderProjectState();
            renderPanel({ passive: true });
        } catch {
            dirty = true;
            setSaveStatus('error', 'Could not save', 'Saving failed. Check your connection.');
            toast('Saving failed. Check your connection and try again.');
        } finally {
            saving = false;

            if (pendingSave || dirty) {
                pendingSave = false;

                if (dirty) {
                    saveTimer = setTimeout(save, SAVE_DELAY);
                }
            }
        }
    }

    async function flushSave() {
        clearTimeout(saveTimer);

        if (dirty && !saving) {
            await save();
        }

        while (saving || pendingSave) {
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
    }

    window.addEventListener('beforeunload', (event) => {
        if (dirty || saving || panel.formDirty) {
            event.preventDefault();
        }
    });

    /* ------------------------------------------------------------------ */
    /* Graph operations                                                     */
    /* ------------------------------------------------------------------ */

    /*
     * Where a new node goes: to the right of the selected node when there is
     * one (the usual "add the next step" gesture), otherwise the viewport
     * centre; then down in rows until it does not sit on another node.
     */
    function placementFor() {
        if (selection.nodes.size === 1) {
            const anchor = state.nodes.get(selection.nodes.values().next().value);

            if (anchor) {
                return { x: anchor.x + NODE_WIDTH + 96, y: anchor.y };
            }
        }

        const center = viewportCenterWorld();

        return { x: center.x - NODE_WIDTH / 2, y: center.y - NODE_HEIGHT / 2 };
    }

    function freePosition(origin) {
        const x = snap(origin.x);
        let y = snap(origin.y);
        let attempts = 0;

        const overlaps = () => Array.from(state.nodes.values()).some(
            (node) => Math.abs(node.x - x) < NODE_WIDTH && Math.abs(node.y - y) < NODE_HEIGHT,
        );

        while (overlaps() && attempts < 60) {
            y += NODE_HEIGHT + 24;
            attempts++;
        }

        return { x, y };
    }

    function defaultConfig(type) {
        switch (type) {
            case 'source': {
                const live = sources.filter((source) => !source.archived);

                return { source_id: live.length === 1 ? live[0].id : null };
            }
            case 'destination': {
                const live = destinations.filter((destination) => !destination.archived);

                return { destination_id: live.length === 1 ? live[0].id : null };
            }
            case 'filter':
                return { rules: [{ type: 'contains', field: 'title', value: '' }] };
            case 'transform':
                return { template: catalog.default_template || '{{title}}\n\n{{body}}', rules: [] };
            default:
                return {};
        }
    }

    function addNode(type, position, { exact = false } = {}) {
        if (!nodeTypes.has(type) || !canEdit) {
            return;
        }

        if (state.nodes.size >= (catalog.limits ? catalog.limits.nodes : 60)) {
            toast('This pipeline already has the maximum number of nodes.');

            return;
        }

        if (!leavePanel()) {
            return;
        }

        const origin = position || placementFor();
        const id = uid(type);

        commit(() => {
            const spot = exact ? { x: snap(origin.x), y: snap(origin.y) } : freePosition(origin);
            state.nodes.set(id, { id, type, x: spot.x, y: spot.y, config: defaultConfig(type) });
            selection.nodes.clear();
            selection.nodes.add(id);
            selection.edge = null;
        }, { inspector: false });

        syncPanelToSelection();

        const el = nodeEls.get(id);

        if (el) {
            el.focus({ preventScroll: true });

            if (!exact) {
                ensureVisible(id);
            }
        }
    }

    function addEdge(from, to) {
        if (!canConnect(from, to)) {
            return;
        }

        if (state.edges.size >= (catalog.limits ? catalog.limits.edges : 120)) {
            toast('This pipeline already has the maximum number of connections.');

            return;
        }

        const id = uid('edge');

        commit(() => {
            state.edges.set(id, { id, from, to });
        }, { inspector: false });
    }

    function deleteSelection() {
        if (selection.nodes.size === 0 && selection.edge === null) {
            return;
        }

        commit(() => {
            if (selection.edge !== null) {
                state.edges.delete(selection.edge);
                traces.delete(selection.edge);
                selection.edge = null;
            }

            selection.nodes.forEach((id) => {
                state.nodes.delete(id);
                state.edges.forEach((edge, edgeId) => {
                    if (edge.from === id || edge.to === id) {
                        state.edges.delete(edgeId);
                        traces.delete(edgeId);
                    }
                });
            });
            selection.nodes.clear();
        }, { inspector: false });

        syncPanelToSelection();
        els.canvas.focus({ preventScroll: true });
    }

    function selectNode(id, { additive = false } = {}) {
        if (!leavePanel()) {
            return false;
        }

        if (!additive) {
            selection.nodes.clear();
        }

        if (additive && selection.nodes.has(id)) {
            selection.nodes.delete(id);
        } else {
            selection.nodes.add(id);
        }

        selection.edge = null;
        renderSelection();
        syncPanelToSelection();

        return true;
    }

    function selectEdge(id) {
        if (!leavePanel()) {
            return false;
        }

        selection.nodes.clear();
        selection.edge = id;
        renderSelection();
        syncPanelToSelection();

        return true;
    }

    function clearSelection({ keepPanel = false } = {}) {
        if (selection.nodes.size === 0 && selection.edge === null) {
            if (!keepPanel && (panel.kind === 'node' || panel.kind === 'edge')) {
                closePanel();
            }

            return;
        }

        selection.nodes.clear();
        selection.edge = null;
        renderSelection();

        if (!keepPanel) {
            syncPanelToSelection();
        }
    }

    function selectAll() {
        if (!leavePanel()) {
            return;
        }

        selection.nodes = new Set(state.nodes.keys());
        selection.edge = null;
        renderSelection();
        syncPanelToSelection();
    }

    function renderSelection() {
        nodeEls.forEach((el, id) => el.classList.toggle('is-selected', selection.nodes.has(id)));
        renderEdges();
    }

    function setConfig(nodeId, path, value) {
        const node = state.nodes.get(nodeId);

        if (!node) {
            return;
        }

        const parts = path.split('.');
        let target = node.config;

        for (let index = 0; index < parts.length - 1; index++) {
            const key = parts[index];
            const nextIsIndex = /^\d+$/.test(parts[index + 1]);

            if (target[key] === undefined || target[key] === null || typeof target[key] !== 'object') {
                target[key] = nextIsIndex ? [] : {};
            }

            target = target[key];
        }

        target[parts[parts.length - 1]] = value;
    }

    /* ------------------------------------------------------------------ */
    /* Inspector: framework                                                 */
    /* ------------------------------------------------------------------ */

    /*
     * Ask before throwing away a half-filled form. Node settings are not
     * forms in this sense: they write into the graph and auto-save.
     */
    function leavePanel() {
        if (!panel.formDirty) {
            return true;
        }

        if (window.confirm('Discard the unsaved changes in the inspector?')) {
            panel.formDirty = false;

            return true;
        }

        return false;
    }

    function openPanel(kind, extra = {}) {
        if (!leavePanel()) {
            return false;
        }

        panel.kind = kind;
        panel.nodeId = extra.nodeId || null;
        panel.edgeId = extra.edgeId || null;
        panel.tab = extra.tab || 'configure';
        panel.mode = extra.mode || 'view';
        panel.formType = extra.formType || null;
        panel.formDirty = false;
        panel.busy = false;
        panel.errors = {};
        panel.notice = null;
        panel.draft = null;
        panel.settingsDraft = null;
        panel.frequency = null;
        panel.serial += 1;
        panel.focusField = extra.focusField || null;

        if (kind !== 'node' && kind !== 'edge' && (selection.nodes.size > 0 || selection.edge !== null)) {
            selection.nodes.clear();
            selection.edge = null;
            renderSelection();
        }

        renderPanel();
        renderHeaderToggles();

        return true;
    }

    function closePanel() {
        if (!leavePanel()) {
            return false;
        }

        panel.kind = null;
        panel.nodeId = null;
        panel.edgeId = null;
        panel.serial += 1;
        els.inspector.hidden = true;
        els.inspector.innerHTML = '';
        renderHeaderToggles();

        return true;
    }

    function togglePanel(kind, extra = {}) {
        if (panel.kind === kind && !extra.focusField) {
            closePanel();
        } else {
            openPanel(kind, extra);
        }
    }

    /* The selection owns the node and edge contexts. */
    function syncPanelToSelection() {
        if (selection.edge !== null) {
            if (panel.kind !== 'edge' || panel.edgeId !== selection.edge) {
                openPanel('edge', { edgeId: selection.edge });
            } else {
                renderPanel();
            }

            return;
        }

        if (selection.nodes.size > 0) {
            const nodeId = selection.nodes.size === 1 ? selection.nodes.values().next().value : null;

            if (panel.kind !== 'node' || panel.nodeId !== nodeId) {
                const previousTab = panel.kind === 'node' ? panel.tab : 'configure';
                openPanel('node', { nodeId, tab: nodeId && previousTab ? previousTab : 'configure' });
            } else {
                renderPanel();
            }

            return;
        }

        if (panel.kind === 'node' || panel.kind === 'edge') {
            closePanel();
        }
    }

    function isCurrent(serial) {
        return panel.serial === serial;
    }

    function panelHeader(title, kicker, category, extra = '') {
        return (
            '<header class="flex items-start justify-between gap-3 border-b border-line px-4 py-3"' + (category ? ' data-node-category="' + esc(category) + '"' : '') + '>' +
            '<div class="min-w-0">' +
            (kicker ? '<p class="pipeline-node-kicker ' + KICKER_CLASS + '">' + esc(kicker) + '</p>' : '') +
            '<h2 class="truncate font-display text-lg tracking-tight text-ink" data-panel-title>' + esc(title) + '</h2>' +
            '</div>' +
            '<div class="flex shrink-0 items-center gap-1">' + extra +
            '<button type="button" class="grid size-7 cursor-pointer place-items-center rounded-md text-muted hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-panel-close aria-label="Close">' + CLOSE_ICON + '</button>' +
            '</div>' +
            '</header>'
        );
    }

    function panelTabs(tabs, active) {
        return (
            '<div class="flex gap-1 border-b border-line px-2" role="tablist">' +
            tabs
                .map((tab) =>
                    '<button type="button" role="tab" aria-selected="' + (tab.id === active ? 'true' : 'false') + '" class="cursor-pointer border-b-2 px-2 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent ' +
                    (tab.id === active ? 'border-accent text-ink' : 'border-transparent text-muted hover:text-ink') + '" data-panel-tab="' + esc(tab.id) + '">' + esc(tab.label) + '</button>')
                .join('') +
            '</div>'
        );
    }

    function panelBody(html, extraClass = '') {
        return '<div class="flex flex-1 flex-col gap-4 overflow-y-auto px-4 py-4 ' + extraClass + '" data-panel-body>' + html + '</div>';
    }

    function emptyNote(text) {
        return '<p class="rounded-md border border-dashed border-line px-3 py-3 text-center text-xs text-muted">' + esc(text) + '</p>';
    }

    function loadingNote(text) {
        return '<p class="flex items-center justify-center gap-2 py-6 text-xs text-muted" aria-busy="true">' + SPINNER + esc(text) + '</p>';
    }

    function errorNote(text) {
        return '<p class="rounded-md border border-state-fail/30 bg-state-fail/5 px-3 py-2 text-xs text-state-fail" role="alert">' + esc(text) + '</p>';
    }

    function statusTone(status) {
        switch (status) {
            case 'completed':
            case 'delivered':
            case 'flowed':
            case 'passed':
                return 'ok';
            case 'failed':
                return 'fail';
            case 'running':
            case 'pending':
            case 'incomplete':
            case 'quota_exceeded':
                return 'warn';
            default:
                return 'skip';
        }
    }

    function statusChip(label, status) {
        const tone = statusTone(status);

        return '<span class="inline-flex shrink-0 items-center gap-1.5 font-mono text-[0.6875rem] text-state-' + tone + '"><span class="inline-block size-1.5 rounded-full bg-state-' + tone + '"></span>' + esc(label) + '</span>';
    }

    function jsonBlock(value) {
        return '<pre class="max-h-64 overflow-auto rounded-md border border-line/60 bg-paper px-2.5 py-2 font-mono text-[0.6875rem] leading-relaxed text-ink">' + esc(JSON.stringify(value, null, 2)) + '</pre>';
    }

    /*
     * Render the current context. A passive render (after a background
     * save) leaves a form the person is typing in alone.
     */
    function renderPanel(options = {}) {
        if (panel.kind === null) {
            els.inspector.hidden = true;
            els.inspector.innerHTML = '';

            return;
        }

        if (options.passive && (panel.formDirty || panel.mode !== 'view' || panel.busy)) {
            return;
        }

        const active = document.activeElement;
        const activeField = els.inspector.contains(active) ? active.getAttribute('data-field') || active.getAttribute('name') : null;
        const body = els.inspector.querySelector('[data-panel-body]');
        const scrollTop = body ? body.scrollTop : 0;
        let html = '';

        switch (panel.kind) {
            case 'node':
                html = renderNodePanel();
                break;
            case 'edge':
                html = renderEdgePanel();
                break;
            case 'test':
                html = renderTestPanel();
                break;
            case 'activity':
                html = renderActivityPanel();
                break;
            case 'settings':
                html = renderSettingsPanel();
                break;
            case 'issues':
                html = renderIssuesPanel();
                break;
            default:
                html = '';
        }

        if (html === '') {
            closePanel();

            return;
        }

        const wasHidden = els.inspector.hidden;
        els.inspector.hidden = false;
        els.inspector.innerHTML = html;
        els.inspector.dataset.panelKind = panel.kind;

        const newBody = els.inspector.querySelector('[data-panel-body]');

        if (newBody && !wasHidden) {
            newBody.scrollTop = scrollTop;
        }

        if (panel.focusField) {
            const field = els.inspector.querySelector('[name="' + panel.focusField + '"], [data-field="' + panel.focusField + '"]');
            panel.focusField = null;

            if (field) {
                field.focus({ preventScroll: true });

                if (typeof field.select === 'function') {
                    field.select();
                }
            }
        } else if (activeField) {
            const again = els.inspector.querySelector('[data-field="' + activeField + '"], [name="' + activeField + '"]');

            if (again) {
                again.focus({ preventScroll: true });
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Inspector: node context                                              */
    /* ------------------------------------------------------------------ */

    function issueList(nodeIssues) {
        if (nodeIssues.length === 0) {
            return '';
        }

        return (
            '<ul class="flex flex-col gap-1.5">' +
            nodeIssues
                .map((issue) => {
                    const tone = issue.level === 'error' ? 'fail' : 'warn';

                    return '<li class="rounded-md border border-state-' + tone + '/30 bg-state-' + tone + '/5 px-3 py-2 text-xs text-state-' + tone + '">' + esc(issue.message) + '</li>';
                })
                .join('') +
            '</ul>'
        );
    }

    function optionList(options, selected, placeholder) {
        let html = placeholder === null ? '' : '<option value=""' + (selected ? '' : ' selected') + '>' + esc(placeholder) + '</option>';

        options.forEach((option) => {
            html += '<option value="' + esc(option.value) + '"' + (String(option.value) === String(selected ?? '') ? ' selected' : '') + '>' + esc(option.label) + '</option>';
        });

        return html;
    }

    function renderNodePanel() {
        if (panel.nodeId === null) {
            if (selection.nodes.size <= 1) {
                return '';
            }

            return (
                panelHeader(selection.nodes.size + ' nodes selected', 'Selection', null) +
                panelBody('<p class="text-sm text-muted">Drag to move them together. Delete removes them and their connections.</p>') +
                (canEdit ? '<footer class="border-t border-line px-4 py-3"><button type="button" class="' + GHOST_BUTTON_CLASS + ' hover:border-state-fail hover:text-state-fail" data-delete-selection>Delete ' + selection.nodes.size + ' nodes</button></footer>' : '')
            );
        }

        const node = state.nodes.get(panel.nodeId);

        if (!node) {
            return '';
        }

        const def = typeDef(node.type);
        const isRecord = node.type === 'source' || node.type === 'destination';
        let body;
        let tabs = '';

        if (isRecord) {
            tabs = panelTabs(
                [
                    { id: 'configure', label: 'Configure' },
                    { id: 'fields', label: 'Fields' },
                    { id: 'activity', label: node.type === 'source' ? 'Activity' : 'Deliveries' },
                ],
                panel.tab,
            );

            if (panel.tab === 'fields') {
                body = renderFieldsTab(node);
            } else if (panel.tab === 'activity') {
                body = renderRecordActivityTab(node);
            } else {
                body = issueList(issuesFor(node.id)) + renderRecordConfigure(node);
            }
        } else if (node.type === 'filter') {
            body = issueList(issuesFor(node.id)) + labelField(node) + filterRulesEditor(node);
        } else {
            body = issueList(issuesFor(node.id)) + labelField(node) + transformEditor(node);
        }

        const footer = canEdit && panel.mode === 'view'
            ? '<footer class="flex items-center justify-between gap-2 border-t border-line px-4 py-3"><button type="button" class="' + GHOST_BUTTON_CLASS + ' hover:border-state-fail hover:text-state-fail" data-delete-selection>Delete node</button><span class="font-mono text-[0.625rem] text-muted">' + esc(node.id) + '</span></footer>'
            : '';

        return panelHeader(nodeTitle(node), def.label, def.category) + tabs + panelBody(body) + footer;
    }

    function labelField(node) {
        return (
            '<div class="flex flex-col gap-1.5">' +
            '<label class="' + LABEL_CLASS + '" for="pipeline-label">Label <span class="font-normal text-muted">(optional)</span></label>' +
            '<input id="pipeline-label" type="text" maxlength="80" class="' + INPUT_CLASS + '" data-field="label" value="' + esc(node.config.label || '') + '" placeholder="' + esc(typeDef(node.type).label) + '"' + (canEdit ? '' : ' disabled') + '>' +
            '</div>'
        );
    }

    function filterRulesEditor(node) {
        const rules = Array.isArray(node.config.rules) ? node.config.rules : [];
        const typeOptions = filterRuleTypes.map((type) => ({ value: type.value, label: type.label }));
        const fieldOptions = Object.entries(filterFields).map(([value, label]) => ({ value, label }));
        let html = '<div class="flex flex-col gap-2"><p class="' + LABEL_CLASS + '">Rules <span class="font-normal text-muted">— every rule must match</span></p>';

        rules.forEach((rule, index) => {
            const def = filterRuleTypes.find((type) => type.value === rule.type) || filterRuleTypes[0] || {};
            const prefix = 'rules.' + index + '.';
            html +=
                '<fieldset class="flex flex-col gap-2 rounded-md border border-line bg-paper p-2.5" data-rule="' + index + '">' +
                '<legend class="sr-only">Rule ' + (index + 1) + '</legend>' +
                '<div class="flex items-center gap-2">' +
                '<select class="' + INPUT_CLASS + '" data-field="' + prefix + 'type" aria-label="Rule type"' + (canEdit ? '' : ' disabled') + '>' + optionList(typeOptions, rule.type, 'Rule type') + '</select>' +
                (canEdit ? '<button type="button" class="grid size-8 shrink-0 cursor-pointer place-items-center rounded-md text-muted hover:bg-paper-raised hover:text-state-fail focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-rule-remove="' + index + '" aria-label="Remove rule ' + (index + 1) + '">' + CLOSE_ICON + '</button>' : '') +
                '</div>' +
                (def.needs_field ? '<select class="' + INPUT_CLASS + '" data-field="' + prefix + 'field" aria-label="Field"' + (canEdit ? '' : ' disabled') + '>' + optionList(fieldOptions, rule.field || 'title', 'Field') + '</select>' : '') +
                (def.needs_value ? '<input type="text" class="' + INPUT_CLASS + ' font-mono" data-field="' + prefix + 'value" value="' + esc(rule.value || '') + '" placeholder="' + (rule.type === 'title_matches' ? 'regular expression' : 'text to look for') + '" aria-label="Value" maxlength="500"' + (canEdit ? '' : ' disabled') + '>' : '') +
                (def.needs_hours ? '<div class="flex items-center gap-2"><input type="number" min="1" max="8760" class="' + INPUT_CLASS + ' font-mono" data-field="' + prefix + 'hours" data-kind="int" value="' + esc(rule.hours || '') + '" aria-label="Hours"' + (canEdit ? '' : ' disabled') + '><span class="text-xs text-muted">hours</span></div>' : '') +
                '</fieldset>';
        });

        if (canEdit) {
            html += '<button type="button" class="' + GHOST_BUTTON_CLASS + ' self-start" data-rule-add>+ Add rule</button>';
        }

        return html + '</div>';
    }

    function transformEditor(node) {
        const rules = Array.isArray(node.config.rules) ? node.config.rules : [];
        const typeOptions = transformRuleTypes.map((type) => ({ value: type.value, label: type.label }));
        let html =
            '<div class="flex flex-col gap-1.5">' +
            '<label class="' + LABEL_CLASS + '" for="pipeline-template">Message template</label>' +
            '<textarea id="pipeline-template" rows="6" maxlength="5000" class="' + INPUT_CLASS + ' font-mono leading-relaxed" data-field="template" placeholder="' + esc(catalog.default_template || '') + '"' + (canEdit ? '' : ' disabled') + '>' + esc(node.config.template || '') + '</textarea>' +
            '<div class="flex flex-wrap gap-1">' +
            placeholders
                .map((placeholder) => '<button type="button" class="cursor-pointer rounded-md border border-line bg-paper px-1.5 py-0.5 font-mono text-[0.6875rem] text-ink hover:border-line-strong focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-insert-placeholder="' + esc(placeholder.name) + '" title="' + esc(placeholder.description) + '"' + (canEdit ? '' : ' disabled') + '>{{' + esc(placeholder.name) + '}}</button>')
                .join('') +
            '</div>' +
            '</div>' +
            '<div class="flex flex-col gap-2"><p class="' + LABEL_CLASS + '">Formatting rules <span class="font-normal text-muted">— applied in order</span></p>';

        rules.forEach((rule, index) => {
            const def = transformRuleTypes.find((type) => type.value === rule.type) || transformRuleTypes[0] || {};
            const prefix = 'rules.' + index + '.';
            html +=
                '<fieldset class="flex flex-col gap-2 rounded-md border border-line bg-paper p-2.5" data-rule="' + index + '">' +
                '<legend class="sr-only">Formatting rule ' + (index + 1) + '</legend>' +
                '<div class="flex items-center gap-2">' +
                '<select class="' + INPUT_CLASS + '" data-field="' + prefix + 'type" aria-label="Rule type"' + (canEdit ? '' : ' disabled') + '>' + optionList(typeOptions, rule.type, 'Rule type') + '</select>' +
                (canEdit ? '<button type="button" class="grid size-8 shrink-0 cursor-pointer place-items-center rounded-md text-muted hover:bg-paper-raised hover:text-state-fail focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-rule-remove="' + index + '" aria-label="Remove rule ' + (index + 1) + '">' + CLOSE_ICON + '</button>' : '') +
                '</div>' +
                (def.needs_value ? '<input type="text" class="' + INPUT_CLASS + '" data-field="' + prefix + 'value" value="' + esc(rule.value || '') + '" placeholder="Text" aria-label="Text" maxlength="1000"' + (canEdit ? '' : ' disabled') + '>' : '') +
                (def.needs_length ? '<div class="flex items-center gap-2"><input type="number" min="1" max="10000" class="' + INPUT_CLASS + ' font-mono" data-field="' + prefix + 'length" data-kind="int" value="' + esc(rule.length || '') + '" aria-label="Length"' + (canEdit ? '' : ' disabled') + '><span class="text-xs text-muted">characters</span></div>' : '') +
                (def.needs_pattern
                    ? '<input type="text" class="' + INPUT_CLASS + ' font-mono" data-field="' + prefix + 'pattern" value="' + esc(rule.pattern || '') + '" placeholder="pattern" aria-label="Pattern" maxlength="500"' + (canEdit ? '' : ' disabled') + '>' +
                      '<input type="text" class="' + INPUT_CLASS + ' font-mono" data-field="' + prefix + 'replacement" value="' + esc(rule.replacement || '') + '" placeholder="replacement" aria-label="Replacement" maxlength="1000"' + (canEdit ? '' : ' disabled') + '>'
                    : '') +
                '</fieldset>';
        });

        if (canEdit) {
            html += '<button type="button" class="' + GHOST_BUTTON_CLASS + ' self-start" data-rule-add>+ Add rule</button>';
        }

        return html + '</div>';
    }

    function routeStatus(node) {
        const nodeRoutes = routesTo(node.id);

        if (nodeRoutes.length === 0) {
            return '';
        }

        let html = '<div class="flex flex-col gap-2"><p class="' + LABEL_CLASS + '">Routes into this destination</p><ul class="flex flex-col gap-1.5">';

        nodeRoutes.forEach((route) => {
            const sourceNode = state.nodes.get(route.source_node_id);
            const from = sourceNode ? nodeTitle(sourceNode) : 'Source';
            const label = route.paused_reason ? 'Paused' : route.enabled ? 'Live' : route.broadcast_id ? 'Disabled' : 'Saving…';
            const status = route.paused_reason ? 'pending' : route.enabled ? 'delivered' : 'skipped';

            html +=
                '<li class="flex flex-col gap-1.5 rounded-md border border-line bg-paper px-3 py-2 text-xs">' +
                '<div class="flex items-center justify-between gap-2"><span class="truncate text-ink">From ' + esc(from) + '</span>' + statusChip(label, status) + '</div>' +
                (route.paused_reason ? '<p class="text-state-warn">' + esc(route.paused_reason) + '</p>' : '') +
                '<div class="flex flex-wrap items-center gap-x-3 gap-y-1">' +
                (route.deliveries_url ? '<a class="' + LINK_CLASS + '" href="' + esc(route.deliveries_url) + '">Delivery history</a>' : '') +
                (!route.enabled && route.enable_url && permissions.change_status
                    ? '<button type="button" class="' + LINK_CLASS + '" data-enable-route="' + esc(route.enable_url) + '">Enable route</button>'
                    : '') +
                '</div>' +
                '</li>';
        });

        return html + '</ul></div>';
    }

    /* ------------------------------------------------------------------ */
    /* Inspector: source and destination records                            */
    /* ------------------------------------------------------------------ */

    function renderRecordConfigure(node) {
        const isSource = node.type === 'source';
        const kind = isSource ? 'source' : 'destination';
        const records = isSource ? sources : destinations;
        const key = isSource ? 'source_id' : 'destination_id';
        const current = node.config[key];
        const record = records.find((entry) => String(entry.id) === String(current)) || null;
        const noun = kind;

        if (panel.mode === 'create') {
            return recordForm(kind, 'create', null);
        }

        if (panel.mode === 'edit' && record) {
            return recordForm(kind, 'edit', record);
        }

        const options = records
            .filter((entry) => !entry.archived || String(entry.id) === String(current))
            .map((entry) => ({ value: entry.id, label: entry.name + ' · ' + entry.type_label + (entry.archived ? ' (archived)' : '') }));
        const canCreate = canEdit && (isSource ? permissions.create_source !== false : permissions.create_destination !== false);
        const users = record ? nodesUsing(kind, record.id).length : 0;
        let html =
            '<div class="flex flex-col gap-1.5">' +
            '<label class="' + LABEL_CLASS + '" for="pipeline-record">' + (isSource ? 'Source' : 'Destination') + '</label>' +
            '<div class="flex items-center gap-2">' +
            '<select id="pipeline-record" class="' + INPUT_CLASS + '" data-field="' + key + '" data-kind="int"' + (canEdit ? '' : ' disabled') + '>' +
            optionList(options, current, options.length === 0 ? 'No ' + noun + 's yet' : 'Choose a ' + noun) +
            '</select>' +
            (canCreate ? '<button type="button" class="' + GHOST_BUTTON_CLASS + ' shrink-0" data-record-create title="Create a new ' + noun + '">New</button>' : '') +
            '</div>' +
            '</div>';

        if (record) {
            const lines = recordConfigLines(kind, record);
            html +=
                '<section class="flex flex-col gap-2 rounded-md border border-line bg-paper p-3">' +
                '<div class="flex items-start justify-between gap-2">' +
                '<div class="min-w-0"><p class="truncate text-sm font-medium text-ink">' + esc(record.name) + '</p><p class="' + HINT_CLASS + '">' + esc(record.type_label) + (record.archived ? ' · archived' : '') + '</p></div>' +
                (canEdit ? '<button type="button" class="' + GHOST_BUTTON_CLASS + ' shrink-0" data-record-edit>Edit</button>' : '') +
                '</div>' +
                (lines.length > 0
                    ? '<dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">' + lines.map((line) => '<dt class="text-muted">' + esc(line.label) + '</dt><dd class="min-w-0 truncate font-mono text-ink" title="' + esc(line.value) + '">' + esc(line.value) + '</dd>').join('') + '</dl>'
                    : '') +
                recordTestLine(record) +
                '<p class="' + HINT_CLASS + '">' +
                (users > 1 ? 'Used by ' + users + ' nodes in this pipeline. Editing it changes all of them.' : 'Used only by this node. Sources and destinations belong to this broadcast.') +
                '</p>' +
                '<div class="flex flex-wrap items-center gap-x-3 gap-y-1">' +
                (record.supports_connection_test && canEdit ? '<button type="button" class="' + LINK_CLASS + '" data-record-test>Test connection</button>' : '') +
                '<a class="' + LINK_CLASS + '" href="' + esc(record.edit_url) + '">Open full settings</a>' +
                '</div>' +
                '</section>';
        } else if (options.length === 0 && canCreate) {
            html += emptyNote('This broadcast has no ' + noun + 's yet. Create one to give this node something to ' + (isSource ? 'read' : 'send to') + '.');
        }

        if (panel.notice) {
            html += panel.notice.ok ? '<p class="rounded-md border border-state-ok/30 bg-state-ok/5 px-3 py-2 text-xs text-state-ok" role="status">' + esc(panel.notice.text) + '</p>' : errorNote(panel.notice.text);
        }

        if (node.type === 'destination') {
            html += routeStatus(node);
        }

        return html;
    }

    function recordConfigLines(kind, record) {
        const type = providerType(kind, record.type);
        const lines = [];

        (type ? type.fields : []).forEach((field) => {
            const value = record.config ? record.config[field.name] : undefined;

            if (value === undefined || value === null || value === '') {
                return;
            }

            const shown = field.type === 'select' && field.options && field.options[value] ? field.options[value] : value;
            lines.push({ label: field.label, value: typeof shown === 'object' ? JSON.stringify(shown) : String(shown) });
        });

        if (kind === 'destination' && record.credential_masks) {
            (type ? type.credential_fields : []).forEach((field) => {
                const mask = record.credential_masks[field.name];
                lines.push({ label: field.label, value: mask ? 'Stored ' + mask : 'Not set' });
            });
        }

        return lines;
    }

    function recordTestLine(record) {
        if (!record.last_tested_at) {
            return '';
        }

        const ok = record.last_test_successful === true;

        return (
            '<p class="flex items-start gap-1.5 text-xs">' + statusChip(ok ? 'Connection OK' : 'Connection failed', ok ? 'delivered' : 'failed') +
            '<span class="text-muted">' + esc(formatTime(record.last_tested_at)) + (record.last_test_message ? ' · ' + esc(record.last_test_message) : '') + '</span></p>'
        );
    }

    function providerFieldInput(prefix, field, value, disabled) {
        const id = 'pf-' + prefix.replace(/\W/g, '-') + field.name;
        const name = prefix + '.' + field.name;
        const error = panel.errors[name];
        const common = ' id="' + id + '" name="' + esc(name) + '" class="' + INPUT_CLASS + (field.type === 'url' || field.type === 'password' ? ' font-mono' : '') + '"' + (disabled ? ' disabled' : '') + (error ? ' aria-invalid="true"' : '');
        let control;

        switch (field.type) {
            case 'textarea':
                control = '<textarea' + common + ' rows="3" placeholder="' + esc(field.placeholder || '') + '">' + esc(value ?? '') + '</textarea>';
                break;
            case 'select':
                control = '<select' + common + '>' + optionList(Object.entries(field.options || {}).map(([optionValue, label]) => ({ value: optionValue, label })), value, field.required ? 'Choose…' : '—') + '</select>';
                break;
            case 'number':
                control = '<input type="number"' + common + ' value="' + esc(value ?? '') + '" placeholder="' + esc(field.placeholder || '') + '">';
                break;
            case 'password':
                control = '<input type="password" autocomplete="new-password"' + common + ' value="" placeholder="' + esc(field.placeholder || '') + '">';
                break;
            case 'url':
                control = '<input type="url" inputmode="url"' + common + ' value="' + esc(value ?? '') + '" placeholder="' + esc(field.placeholder || '') + '">';
                break;
            default:
                control = '<input type="text"' + common + ' value="' + esc(value ?? '') + '" placeholder="' + esc(field.placeholder || '') + '">';
        }

        return (
            '<div class="flex flex-col gap-1">' +
            '<label class="' + LABEL_CLASS + '" for="' + id + '">' + esc(field.label) + (field.optional || !field.required ? ' <span class="font-normal text-muted">(optional)</span>' : '') + '</label>' +
            control +
            (error ? '<p class="' + ERROR_CLASS + '" data-error-for="' + esc(name) + '">' + esc(error) + '</p>' : '') +
            (field.hint ? '<p class="' + HINT_CLASS + '">' + esc(field.hint) + '</p>' : '') +
            '</div>'
        );
    }

    function recordForm(kind, mode, record) {
        const types = kind === 'source' ? sourceTypes : destinationTypes;
        const offered = types.filter((type) => type.offered);
        const typeValue = mode === 'edit' ? record.type : panel.formType || (offered[0] ? offered[0].type : null);
        const type = providerType(kind, typeValue);
        const disabled = panel.busy;
        const noun = kind;
        // What the person has typed so far survives a re-render (type switch, validation errors).
        const draft = panel.draft || null;
        const nameValue = draft && draft.name !== undefined ? draft.name : record ? record.name : '';
        const configValue = (name) => (draft && draft.config && draft.config[name] !== undefined ? draft.config[name] : record && record.config ? record.config[name] : '');
        let html = '<form class="flex flex-col gap-3" data-record-form data-kind="' + kind + '" data-mode="' + mode + '"' + (record ? ' data-record-id="' + esc(record.id) + '"' : '') + ' novalidate>';

        html += '<p class="' + KICKER_CLASS + ' text-muted">' + (mode === 'edit' ? 'Edit ' + noun : 'New ' + noun) + '</p>';

        if (mode === 'create') {
            if (offered.length === 0) {
                return emptyNote('No ' + noun + ' types are available to add.') + '<button type="button" class="' + GHOST_BUTTON_CLASS + ' self-start" data-record-cancel>Back</button>';
            }

            html += '<fieldset class="flex flex-col gap-1"><legend class="' + LABEL_CLASS + ' mb-1">Type</legend><div class="grid grid-cols-2 gap-1.5">';
            offered.forEach((entry) => {
                const checked = entry.type === typeValue;
                html +=
                    '<label class="flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-2 text-xs ' + (checked ? 'border-accent bg-accent/5 text-ink' : 'border-line text-muted hover:border-line-strong') + '">' +
                    '<input type="radio" name="type" value="' + esc(entry.type) + '" class="accent-accent"' + (checked ? ' checked' : '') + (disabled ? ' disabled' : '') + '>' +
                    '<span class="font-medium">' + esc(entry.label) + '</span></label>';
            });
            html += '</div>' + (panel.errors.type ? '<p class="' + ERROR_CLASS + '">' + esc(panel.errors.type) + '</p>' : '') + '</fieldset>';
        }

        html +=
            '<div class="flex flex-col gap-1">' +
            '<label class="' + LABEL_CLASS + '" for="pf-name">Name</label>' +
            '<input id="pf-name" type="text" name="name" maxlength="255" class="' + INPUT_CLASS + '" value="' + esc(nameValue) + '" placeholder="' + esc(type ? type.label : '') + '"' + (disabled ? ' disabled' : '') + (panel.errors.name ? ' aria-invalid="true"' : '') + ' required>' +
            (panel.errors.name ? '<p class="' + ERROR_CLASS + '">' + esc(panel.errors.name) + '</p>' : '') +
            '</div>';

        if (type) {
            type.fields.forEach((field) => {
                html += providerFieldInput('config', field, configValue(field.name), disabled);
            });

            if (kind === 'destination' && type.credential_fields.length > 0) {
                html += '<div class="flex flex-col gap-2 rounded-md border border-line bg-paper p-3"><p class="' + LABEL_CLASS + '">Credentials</p>';
                type.credential_fields.forEach((field) => {
                    const mask = record && record.credential_masks ? record.credential_masks[field.name] : null;
                    const copy = { ...field, placeholder: mask ? 'Stored ' + mask + ' — leave blank to keep' : field.placeholder, required: mode === 'create' ? field.required : false, optional: mode === 'edit' };
                    html += providerFieldInput('credentials', copy, '', disabled);
                });
                html += '<p class="' + HINT_CLASS + '">Secrets are encrypted at rest and never shown again.</p></div>';
            }
        } else {
            html += emptyNote('This ' + noun + ' type is no longer available. Its settings cannot be edited here.');
        }

        if (panel.notice) {
            html += panel.notice.ok ? '<p class="rounded-md border border-state-ok/30 bg-state-ok/5 px-3 py-2 text-xs text-state-ok" role="status">' + esc(panel.notice.text) + '</p>' : errorNote(panel.notice.text);
        }

        html +=
            '<div class="flex flex-wrap items-center gap-2 pt-1">' +
            '<button type="submit" class="' + PRIMARY_BUTTON_CLASS + '"' + (disabled || !type ? ' disabled' : '') + '>' + (panel.busy === 'save' ? SPINNER : '') + (mode === 'edit' ? 'Save ' + noun : 'Create ' + noun) + '</button>' +
            '<button type="button" class="' + GHOST_BUTTON_CLASS + '" data-record-cancel' + (disabled ? ' disabled' : '') + '>Cancel</button>' +
            (mode === 'edit' && record && record.supports_connection_test ? '<button type="button" class="' + GHOST_BUTTON_CLASS + ' ml-auto" data-record-test' + (disabled ? ' disabled' : '') + '>' + (panel.busy === 'test' ? SPINNER : '') + 'Test connection</button>' : '') +
            '</div>' +
            '</form>';

        return html;
    }

    function readRecordForm(form) {
        const body = { name: '', config: {}, credentials: {} };
        const typeInput = form.querySelector('input[name="type"]:checked');

        if (typeInput) {
            body.type = typeInput.value;
        }

        form.querySelectorAll('[name]').forEach((input) => {
            const name = input.getAttribute('name');

            if (name === 'name') {
                body.name = input.value;
            } else if (name.startsWith('config.')) {
                body.config[name.slice(7)] = input.value;
            } else if (name.startsWith('credentials.')) {
                if (input.value !== '') {
                    body.credentials[name.slice(12)] = input.value;
                }
            }
        });

        return body;
    }

    function applyErrors(data) {
        panel.errors = {};

        if (data && data.errors) {
            Object.entries(data.errors).forEach(([key, messages]) => {
                panel.errors[key] = Array.isArray(messages) ? messages[0] : String(messages);
            });
        }
    }

    async function submitRecordForm(form) {
        const kind = form.getAttribute('data-kind');
        const mode = form.getAttribute('data-mode');
        const node = state.nodes.get(panel.nodeId);

        if (!node || panel.busy) {
            return;
        }

        const body = readRecordForm(form);
        const record = mode === 'edit' ? (kind === 'source' ? sourceById(form.getAttribute('data-record-id')) : destinationById(form.getAttribute('data-record-id'))) : null;
        const url = mode === 'edit' ? record && record.update_url : kind === 'source' ? urls.source_store : urls.destination_store;

        if (!url) {
            return;
        }

        const serial = panel.serial;
        panel.busy = 'save';
        panel.notice = null;
        panel.errors = {};
        renderPanel();

        try {
            const { ok, status, data } = await request(url, { method: mode === 'edit' ? 'PATCH' : 'POST', body });

            if (!isCurrent(serial)) {
                return;
            }

            panel.busy = false;

            if (status === 422) {
                applyErrors(data);
                panel.draft = body;
                panel.notice = { ok: false, text: 'Check the highlighted fields.' };
                renderPanel();

                return;
            }

            if (!ok) {
                panel.notice = { ok: false, text: firstError(data, 'The ' + kind + ' could not be saved.') };
                renderPanel();

                return;
            }

            const saved = kind === 'source' ? data.source : data.destination;

            if (saved) {
                if (kind === 'source') {
                    const index = sources.findIndex((entry) => entry.id === saved.id);
                    index === -1 ? sources.push(saved) : sources.splice(index, 1, saved);
                } else {
                    const index = destinations.findIndex((entry) => entry.id === saved.id);
                    index === -1 ? destinations.push(saved) : destinations.splice(index, 1, saved);
                }

                summaryCache.delete(kind + ':' + saved.id);

                if (mode === 'create') {
                    commit(() => {
                        node.config[kind === 'source' ? 'source_id' : 'destination_id'] = saved.id;
                    }, { inspector: false });
                }
            }

            panel.formDirty = false;
            panel.mode = 'view';
            panel.notice = { ok: true, text: data.message || 'Saved.' };
            renderNodes();
            renderPanel();
            toast(data.message || 'Saved.');
        } catch {
            if (isCurrent(serial)) {
                panel.busy = false;
                panel.notice = { ok: false, text: 'Saving failed. Check your connection and try again.' };
                renderPanel();
            }
        }
    }

    async function testRecord(record, kind, form) {
        if (!record || !record.test_url || panel.busy) {
            return;
        }

        const body = form ? readRecordForm(form) : { name: record.name, config: record.config || {}, credentials: {} };
        const serial = panel.serial;
        panel.busy = 'test';
        panel.notice = null;
        renderPanel();

        try {
            const { ok, status, data } = await request(record.test_url, { method: 'POST', body });

            if (!isCurrent(serial)) {
                return;
            }

            panel.busy = false;

            if (status === 422) {
                applyErrors(data);
                panel.notice = { ok: false, text: 'Fix the highlighted fields before testing.' };
            } else if (status === 429) {
                panel.notice = { ok: false, text: 'Too many tests in a row. Try again in a minute.' };
            } else if (!ok) {
                panel.notice = { ok: false, text: firstError(data, 'The connection test could not run.') };
            } else {
                panel.notice = { ok: data.successful === true, text: data.message || (data.successful ? 'Connection OK.' : 'The connection failed.') };
                const fresh = kind === 'source' ? data.source : data.destination;

                if (fresh) {
                    const list = kind === 'source' ? sources : destinations;
                    const index = list.findIndex((entry) => entry.id === fresh.id);

                    if (index !== -1) {
                        list.splice(index, 1, fresh);
                    }

                    summaryCache.delete(kind + ':' + fresh.id);
                }
            }

            renderPanel();
        } catch {
            if (isCurrent(serial)) {
                panel.busy = false;
                panel.notice = { ok: false, text: 'The connection test could not run. Check your connection.' };
                renderPanel();
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Inspector: fields and activity tabs (summaries)                      */
    /* ------------------------------------------------------------------ */

    function summaryFor(kind, record) {
        if (!record || !record.summary_url) {
            return null;
        }

        const key = kind + ':' + record.id;

        if (summaryCache.has(key)) {
            return summaryCache.get(key);
        }

        if (!inflight.has(key)) {
            inflight.add(key);
            request(record.summary_url)
                .then(({ ok, data }) => {
                    summaryCache.set(key, ok ? { ok: true, data } : { ok: false, error: firstError(data, 'Could not load this ' + kind + '.') });
                })
                .catch(() => {
                    summaryCache.set(key, { ok: false, error: 'Could not load this ' + kind + '. Check your connection.' });
                })
                .finally(() => {
                    inflight.delete(key);

                    if (panel.kind === 'node') {
                        const node = state.nodes.get(panel.nodeId);

                        if (node && recordFor(node) && String(recordFor(node).id) === String(record.id)) {
                            renderPanel();
                        }
                    }
                });
        }

        return { loading: true };
    }

    function schemaFieldList(fields, options = {}) {
        if (!fields || fields.length === 0) {
            return emptyNote('No field documentation is available for this type.');
        }

        return (
            '<ul class="flex flex-col divide-y divide-line/60 rounded-md border border-line">' +
            fields
                .map((field) => {
                    const example = options.sample && options.sample[field.name] !== undefined ? options.sample[field.name] : undefined;

                    return (
                        '<li class="flex flex-col gap-0.5 px-3 py-2">' +
                        '<div class="flex items-center justify-between gap-2"><code class="font-mono text-xs text-ink">' + esc(field.name) + '</code>' +
                        '<span class="font-mono text-[0.625rem] text-muted">' + esc(field.type) + (field.required ? ' · required' : ' · optional') + '</span></div>' +
                        (field.description ? '<p class="' + HINT_CLASS + '">' + esc(field.description) + '</p>' : '') +
                        (example !== undefined ? '<p class="truncate font-mono text-[0.6875rem] text-node-source" title="' + esc(typeof example === 'object' ? JSON.stringify(example) : example) + '">e.g. ' + esc(typeof example === 'object' ? JSON.stringify(example) : example) + '</p>' : '') +
                        '</li>'
                    );
                })
                .join('') +
            '</ul>'
        );
    }

    function renderFieldsTab(node) {
        const kind = node.type;
        const record = recordFor(node);

        if (!record) {
            return emptyNote('Choose a ' + kind + ' first to see the fields it ' + (kind === 'source' ? 'provides' : 'accepts') + '.');
        }

        const type = providerType(kind, record.type);
        const summary = summaryFor(kind, record);
        let html = '';

        if (kind === 'source') {
            const sample = summary && summary.ok && summary.data.sample ? summary.data.sample : null;
            html += '<section class="flex flex-col gap-2"><p class="' + LABEL_CLASS + '">Fields this source provides</p>' +
                '<p class="' + HINT_CLASS + '">Documented by the ' + esc(record.type_label) + ' adapter. Example values come from the most recent item actually fetched.</p>' +
                schemaFieldList(type ? type.schema : (summary && summary.ok ? summary.data.fields : []), { sample: sample ? sample.fields : null }) + '</section>';

            html += '<section class="flex flex-col gap-2"><p class="' + LABEL_CLASS + '">Observed</p>';

            if (summary && summary.loading) {
                html += loadingNote('Looking for fetched items…');
            } else if (summary && !summary.ok) {
                html += errorNote(summary.error);
            } else if (sample) {
                html += '<p class="' + HINT_CLASS + '">Latest item, fetched ' + esc(formatTime(sample.observed_at)) + '. ' + plural(summary.data.item_count, 'item') + ' stored in total.</p>' + jsonBlock(sample.fields);
            } else {
                html += emptyNote('No items have been fetched from this source yet. Run a test to see real values.');
            }

            html += '</section>';
        } else {
            const capabilities = summary && summary.ok ? summary.data.capabilities : type ? { supports_media: type.supports_media, max_text_length: type.max_text_length } : null;
            html += '<section class="flex flex-col gap-2"><p class="' + LABEL_CLASS + '">Fields this destination accepts</p>' +
                '<p class="' + HINT_CLASS + '">From the ' + esc(record.type_label) + ' adapter. The transform renders the message text; the adapter formats and sends it.</p>' +
                schemaFieldList(type ? type.schema : (summary && summary.ok ? summary.data.fields : [])) + '</section>';

            if (capabilities) {
                html += '<section class="flex flex-col gap-2"><p class="' + LABEL_CLASS + '">Limits</p><dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">' +
                    '<dt class="text-muted">Text length</dt><dd class="font-mono text-ink">' + (capabilities.max_text_length ? 'up to ' + capabilities.max_text_length + ' characters' : 'no documented limit') + '</dd>' +
                    '<dt class="text-muted">Media</dt><dd class="font-mono text-ink">' + (capabilities.supports_media ? 'attached when the item has any' : 'not sent') + '</dd>' +
                    '</dl></section>';
            }
        }

        return html;
    }

    function renderRecordActivityTab(node) {
        const kind = node.type;
        const record = recordFor(node);

        if (!record) {
            return emptyNote('Choose a ' + kind + ' first.');
        }

        const summary = summaryFor(kind, record);
        let html = '';

        if (kind === 'source') {
            html += '<section class="flex flex-col gap-2"><div class="flex items-center justify-between gap-2"><p class="' + LABEL_CLASS + '">Recent fetches</p>' +
                (record.supports_connection_test && canEdit ? '<button type="button" class="' + GHOST_BUTTON_CLASS + '" data-record-test' + (panel.busy ? ' disabled' : '') + '>' + (panel.busy === 'test' ? SPINNER : '') + 'Test source</button>' : '') + '</div>';

            html += recordTestLine(record);

            if (panel.notice) {
                html += panel.notice.ok ? '<p class="rounded-md border border-state-ok/30 bg-state-ok/5 px-3 py-2 text-xs text-state-ok" role="status">' + esc(panel.notice.text) + '</p>' : errorNote(panel.notice.text);
            }

            if (summary && summary.loading) {
                html += loadingNote('Loading recent runs…');
            } else if (summary && !summary.ok) {
                html += errorNote(summary.error);
            } else if (summary && summary.data.activity.length === 0) {
                html += emptyNote('No run has read from this source yet. Test the pipeline to fetch without sending.');
            } else if (summary) {
                html += '<ul class="flex flex-col gap-1.5">' + summary.data.activity.map((run) =>
                    '<li class="flex flex-col gap-1 rounded-md border border-line bg-paper px-3 py-2 text-xs">' +
                    '<div class="flex items-center justify-between gap-2"><span class="font-mono text-muted">' + esc(formatTime(run.started_at)) + (run.is_dry ? ' · test' : '') + '</span>' + statusChip(run.status_label, run.status) + '</div>' +
                    '<p class="text-ink">Found ' + run.items_found + ', new ' + run.items_new + (run.route ? ' <span class="text-muted">· ' + esc(run.route) + '</span>' : '') + '</p>' +
                    (run.error ? '<p class="text-state-fail">' + esc(run.error) + '</p>' : '') +
                    '</li>').join('') + '</ul>';
            }

            html += '</section>';
        } else {
            html += '<section class="flex flex-col gap-2"><div class="flex items-center justify-between gap-2"><p class="' + LABEL_CLASS + '">Recent deliveries</p>' +
                (record.supports_connection_test && canEdit ? '<button type="button" class="' + GHOST_BUTTON_CLASS + '" data-record-test' + (panel.busy ? ' disabled' : '') + '>' + (panel.busy === 'test' ? SPINNER : '') + 'Test connection</button>' : '') + '</div>';

            html += recordTestLine(record);

            if (panel.notice) {
                html += panel.notice.ok ? '<p class="rounded-md border border-state-ok/30 bg-state-ok/5 px-3 py-2 text-xs text-state-ok" role="status">' + esc(panel.notice.text) + '</p>' : errorNote(panel.notice.text);
            }

            if (summary && summary.loading) {
                html += loadingNote('Loading deliveries…');
            } else if (summary && !summary.ok) {
                html += errorNote(summary.error);
            } else if (summary && summary.data.deliveries.length === 0) {
                html += emptyNote('Nothing has been delivered here yet. Tests never deliver; a live run will.');
            } else if (summary) {
                html += '<ul class="flex flex-col gap-1.5">' + summary.data.deliveries.map((delivery) => {
                    const label = delivery.status === 'pending' ? (delivery.retry_queued ? 'Retry queued' : 'Attempted') : delivery.status_label;

                    return (
                        '<li class="flex flex-col gap-1 rounded-md border border-line bg-paper px-3 py-2 text-xs">' +
                        '<div class="flex items-center justify-between gap-2"><span class="min-w-0 truncate text-ink">' + esc(delivery.title) + '</span>' + statusChip(label, delivery.status) + '</div>' +
                        '<p class="font-mono text-[0.6875rem] text-muted">' + esc(formatTime(delivery.attempted_at)) + (delivery.run_id ? ' · run #' + esc(delivery.run_id) : '') + (delivery.external_reference ? ' · ' + esc(delivery.external_reference) : '') + '</p>' +
                        (delivery.error ? '<p class="text-state-fail">' + esc(delivery.error) + '</p>' : '') +
                        '</li>'
                    );
                }).join('') + '</ul>' +
                '<p class="' + HINT_CLASS + '">' + plural(summary.data.delivery_count, 'delivery', 'deliveries') + ' recorded in total.</p>';
            }

            html += '</section>';
        }

        return html;
    }

    /* ------------------------------------------------------------------ */
    /* Inspector: edge context (traces)                                     */
    /* ------------------------------------------------------------------ */

    function traceDetail(edgeId, summary) {
        if (!summary || !urls.edge_trace) {
            return null;
        }

        const key = summary.run_id + ':' + edgeId;

        if (traceCache.has(key)) {
            return traceCache.get(key);
        }

        if (!inflight.has(key)) {
            inflight.add(key);
            const url = urls.edge_trace.replace('__EDGE__', encodeURIComponent(edgeId)) + '?run=' + encodeURIComponent(summary.run_id);
            request(url)
                .then(({ ok, data }) => {
                    traceCache.set(key, ok ? { ok: true, trace: data.trace } : { ok: false, error: firstError(data, 'Could not load the trace.') });
                })
                .catch(() => {
                    traceCache.set(key, { ok: false, error: 'Could not load the trace. Check your connection.' });
                })
                .finally(() => {
                    inflight.delete(key);

                    if (panel.kind === 'edge' && panel.edgeId === edgeId) {
                        renderPanel();
                    }
                });
        }

        return { loading: true };
    }

    function traceItem(item, index) {
        const stopped = item.status === 'stopped';
        const title = item.title || item.payload && item.payload.title || 'Item ' + (index + 1);

        return (
            '<li class="flex flex-col gap-1 rounded-md border border-line bg-paper px-3 py-2 text-xs">' +
            '<div class="flex items-start justify-between gap-2"><span class="min-w-0 truncate font-medium text-ink">' + esc(title) + '</span>' + statusChip(stopped ? 'Stopped here' : item.status === 'delivered' ? 'Would publish' : 'Passed', stopped ? 'skipped' : 'passed') + '</div>' +
            (item.reason ? '<p class="text-muted">' + esc(item.reason) + '</p>' : '') +
            '<details class="group"><summary class="cursor-pointer select-none text-[0.6875rem] text-accent underline-offset-2 hover:underline">Payload</summary>' + jsonBlock(item.payload) + '</details>' +
            '</li>'
        );
    }

    function renderEdgePanel() {
        const edge = state.edges.get(panel.edgeId);

        if (!edge) {
            return '';
        }

        const from = state.nodes.get(edge.from);
        const to = state.nodes.get(edge.to);
        const summary = traces.get(edge.id) || null;
        const onRoute = completeEdgeKeys().has(edge.from + '>' + edge.to);
        let body =
            '<div class="flex items-center gap-2 text-sm text-ink">' +
            '<span class="min-w-0 truncate">' + esc(from ? nodeTitle(from) : '?') + '</span><span class="shrink-0 text-muted" aria-hidden="true">→</span><span class="min-w-0 truncate">' + esc(to ? nodeTitle(to) : '?') + '</span>' +
            '</div>';

        if (!onRoute && routes.length > 0) {
            body += emptyNote('This connection is not part of a complete source-to-destination route yet, so no content flows along it.');
        } else if (!summary) {
            body += '<section class="flex flex-col gap-2"><p class="' + LABEL_CLASS + '">Latest test</p>' +
                emptyNote(routes.length === 0
                    ? (issues.some((issue) => issue.level === 'error')
                        ? 'This pipeline has errors, so no route is live yet. Fix the issues, save, then run a test to see what flows here.'
                        : 'Connect a source to a destination and save, then run a test to see what flows here.')
                    : 'No test has been run since this connection was saved.') + '</section>';
        } else {
            const detail = summary.status === 'flowed' || summary.status === 'failed' ? traceDetail(edge.id, summary) : null;
            body +=
                '<section class="flex flex-col gap-2"><div class="flex items-center justify-between gap-2"><p class="' + LABEL_CLASS + '">Latest test</p>' + statusChip(summary.status_label, summary.status) + '</div>' +
                '<p class="font-mono text-[0.6875rem] text-muted">Run #' + esc(summary.run_id) + ' · ' + esc(formatTime(summary.recorded_at)) + '</p>' +
                '<div class="grid grid-cols-2 gap-2">' +
                '<div class="flex flex-col rounded-md border border-line bg-paper px-3 py-2"><span class="' + KICKER_CLASS + ' text-muted">Arrived</span><span class="font-mono text-base text-ink">' + summary.items_in + '</span></div>' +
                '<div class="flex flex-col rounded-md border border-line bg-paper px-3 py-2"><span class="' + KICKER_CLASS + ' text-muted">Passed on</span><span class="font-mono text-base text-ink">' + summary.items_out + '</span></div>' +
                '</div>' +
                (summary.summary ? '<p class="' + (summary.status === 'failed' ? 'text-xs text-state-fail' : HINT_CLASS) + '">' + esc(summary.summary) + '</p>' : '') +
                '</section>';

            if (detail) {
                body += '<section class="flex flex-col gap-2"><p class="' + LABEL_CLASS + '">Items</p>';

                if (detail.loading) {
                    body += loadingNote('Loading items…');
                } else if (!detail.ok) {
                    body += errorNote(detail.error);
                } else if (!detail.trace || !Array.isArray(detail.trace.items) || detail.trace.items.length === 0) {
                    body += emptyNote('No item payloads were kept for this edge.');
                } else {
                    body += '<ul class="flex flex-col gap-1.5">' + detail.trace.items.map(traceItem).join('') + '</ul>';

                    if (detail.trace.items_in > detail.trace.items.length) {
                        body += '<p class="' + HINT_CLASS + '">Showing the first ' + detail.trace.items.length + ' of ' + detail.trace.items_in + ' items.</p>';
                    }
                }

                body += '</section>';
            }
        }

        if (permissions.dry_run && urls.dry_run) {
            body += '<button type="button" class="' + GHOST_BUTTON_CLASS + ' self-start" data-run-test' + (testing ? ' disabled aria-busy="true"' : '') + '>' + (testing ? SPINNER : '') + (summary ? 'Run a new test' : 'Run a test') + '</button>' +
                '<p class="' + HINT_CLASS + '">A test fetches the sources and renders every message without sending anything.</p>';
        }

        const footer = canEdit ? '<footer class="border-t border-line px-4 py-3"><button type="button" class="' + GHOST_BUTTON_CLASS + ' hover:border-state-fail hover:text-state-fail" data-delete-selection>Delete connection</button></footer>' : '';

        return panelHeader('Connection', 'Edge', null) + panelBody(body) + footer;
    }

    /* ------------------------------------------------------------------ */
    /* Inspector: test results                                              */
    /* ------------------------------------------------------------------ */

    function testSummaryChip(label, value, tone) {
        return '<div class="flex flex-col rounded-md border border-line bg-paper px-3 py-2"><span class="' + KICKER_CLASS + ' text-muted">' + label + '</span><span class="font-mono text-base text-' + (tone || 'ink') + '">' + value + '</span></div>';
    }

    function renderTestPanel() {
        if (testing) {
            return panelHeader('Testing…', 'Dry run · nothing is sent', null) + panelBody(loadingNote('Fetching the sources and rendering every message.'), 'items-center justify-center');
        }

        if (!lastTest) {
            return panelHeader('Test', 'Dry run · nothing is sent', null) +
                panelBody(
                    '<p class="text-sm text-muted">A test fetches every source, applies the filters and renders each message, then stops. Nothing is delivered. Afterwards, select a connection to see what flowed along it.</p>' +
                    (permissions.dry_run ? '<button type="button" class="' + PRIMARY_BUTTON_CLASS + ' self-start" data-run-test>Run a test</button>' : ''),
                );
        }

        const run = lastTest;
        const routesHtml = (run.routes || [])
            .map((route) => {
                const entries = (route.report || [])
                    .map((entry) => {
                        const publish = entry.outcome === 'publish';
                        const rendered = String(entry.rendered || '');
                        const shown = rendered.length > 320 ? rendered.slice(0, 318) + '…' : rendered;

                        return (
                            '<li class="flex flex-col gap-1 rounded-md border border-line bg-paper px-3 py-2">' +
                            '<div class="flex items-start justify-between gap-2">' +
                            '<span class="min-w-0 truncate text-xs font-medium text-ink">' + esc(entry.title || 'Untitled item') + '</span>' +
                            statusChip(publish ? 'Would publish' : 'Skipped', publish ? 'delivered' : 'skipped') +
                            '</div>' +
                            (publish && shown !== '' ? '<pre class="max-h-40 overflow-y-auto whitespace-pre-wrap rounded-md border border-line/60 bg-paper-raised px-2.5 py-2 font-sans text-xs leading-relaxed text-ink">' + esc(shown) + '</pre>' : '') +
                            (!publish && entry.skip_reason ? '<p class="text-xs text-muted">' + esc(entry.skip_reason) + '</p>' : '') +
                            '</li>'
                        );
                    })
                    .join('');

                return (
                    '<section class="flex flex-col gap-2" data-test-route="' + esc(route.broadcast_id) + '">' +
                    '<div class="flex items-center justify-between gap-2">' +
                    '<button type="button" class="min-w-0 cursor-pointer truncate text-left text-sm font-medium text-ink hover:text-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-test-focus-route="' + esc(route.broadcast_id) + '" title="Highlight this route on the canvas">' + esc(route.name || 'Route') + '</button>' +
                    statusChip(route.status_label, route.status) +
                    '</div>' +
                    (route.error_detail ? errorNote(route.error_detail) : '') +
                    (entries === '' && !route.error_detail ? '<p class="text-xs text-muted">The source returned nothing new.</p>' : '<ul class="flex flex-col gap-1.5">' + entries + '</ul>') +
                    '</section>'
                );
            })
            .join('');

        const body =
            '<div class="grid grid-cols-3 gap-2">' +
            testSummaryChip('Found', run.items_found, null) +
            testSummaryChip('Would publish', run.would_publish, 'state-ok') +
            testSummaryChip('Skipped', run.items_skipped, 'state-skip') +
            '</div>' +
            '<div class="flex items-center justify-between gap-2">' + statusChip(run.status_label, run.status) +
            (run.url ? '<a class="' + LINK_CLASS + '" href="' + esc(run.url) + '">Open run</a>' : '') + '</div>' +
            (routesHtml === '' ? '<p class="text-sm text-muted">No routes to test yet. Connect a source to a destination and save.</p>' : routesHtml) +
            '<p class="' + HINT_CLASS + '">Select a connection on the canvas to see the items that passed along it.</p>' +
            (permissions.dry_run ? '<button type="button" class="' + GHOST_BUTTON_CLASS + ' self-start" data-run-test>Run again</button>' : '');

        return panelHeader('Test results', 'Dry run · nothing was sent', null) + panelBody(body) +
            '<footer class="border-t border-line px-4 py-3 text-xs text-muted">A dry run fetches the sources and renders every message, but publishes nothing. It counts towards the fetch quota.</footer>';
    }

    async function runTest() {
        if (!urls.dry_run || testing) {
            return;
        }

        if (canEdit) {
            await flushSave();

            if (dirty) {
                toast('Save the pipeline before testing it.');

                return;
            }
        }

        const stayOnEdge = panel.kind === 'edge';

        if (!stayOnEdge && panel.kind !== 'test') {
            if (!openPanel('test')) {
                return;
            }
        }

        testing = true;

        if (els.test) {
            els.test.disabled = true;
            els.test.setAttribute('aria-busy', 'true');
        }

        renderPanel();

        try {
            const { ok, status, data } = await request(urls.dry_run, { method: 'POST', body: {} });

            if (status === 429) {
                toast('Too many tests in a row. Try again in a minute.');

                return;
            }

            if (status === 403) {
                toast('You cannot test this broadcast.');

                return;
            }

            if (!ok && !data.run) {
                toast(firstError(data, 'The test could not finish.'));

                return;
            }

            if (data.run) {
                lastTest = data.run;
            }

            if (Array.isArray(data.traces)) {
                traces.clear();
                data.traces.forEach((trace) => traces.set(trace.edge_id, trace));
                traceCache.clear();
                renderEdges();
            }

            runsCache = null;
            summaryCache.clear();

            if (data.ok === false && data.message) {
                toast(data.message);
            } else if (stayOnEdge) {
                toast('Test finished. Nothing was sent.');
            }
        } catch {
            toast('The test could not finish. Check your connection and try again.');
        } finally {
            testing = false;

            if (els.test) {
                els.test.disabled = false;
                els.test.removeAttribute('aria-busy');
            }

            renderPanel();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Inspector: activity                                                  */
    /* ------------------------------------------------------------------ */

    function loadRuns() {
        if (runsCache || !urls.runs) {
            return runsCache;
        }

        if (!inflight.has('runs')) {
            inflight.add('runs');
            request(urls.runs)
                .then(({ ok, data }) => {
                    runsCache = ok ? { ok: true, data } : { ok: false, error: firstError(data, 'Could not load the activity.') };
                })
                .catch(() => {
                    runsCache = { ok: false, error: 'Could not load the activity. Check your connection.' };
                })
                .finally(() => {
                    inflight.delete('runs');

                    if (panel.kind === 'activity') {
                        renderPanel();
                    }
                });
        }

        return { loading: true };
    }

    function renderActivityPanel() {
        const runs = loadRuns();
        let body = '';

        if (!runs || runs.loading) {
            body = loadingNote('Loading recent runs…');
        } else if (!runs.ok) {
            body = errorNote(runs.error);
        } else if (runs.data.runs.length === 0) {
            body = emptyNote('Nothing has run yet. Test to fetch without sending, or play the broadcast to run on schedule.');
        } else {
            body =
                '<ul class="flex flex-col gap-1.5">' +
                runs.data.runs.map((run) =>
                    '<li class="rounded-md border border-line bg-paper px-3 py-2 text-xs">' +
                    '<div class="flex items-center justify-between gap-2">' +
                    '<a class="min-w-0 truncate font-mono text-muted hover:text-accent hover:underline" href="' + esc(run.url) + '">' + esc(formatTime(run.started_at)) + (run.is_dry ? ' · test' : '') + '</a>' +
                    statusChip(run.status_label, run.status) +
                    '</div>' +
                    '<p class="mt-1 text-ink">Found ' + run.items_found + ' · new ' + run.items_new + ' · skipped ' + run.items_skipped + ' · ' + (run.is_dry ? 'would deliver ' : 'delivered ') + run.items_delivered + (run.items_failed > 0 ? ' · <span class="text-state-fail">failed ' + run.items_failed + '</span>' : '') + '</p>' +
                    '</li>').join('') +
                '</ul>' +
                '<p class="' + HINT_CLASS + '">' + plural(runs.data.total, 'run') + ' in total.</p>';
        }

        const refresh = '<button type="button" class="grid size-7 cursor-pointer place-items-center rounded-md text-muted hover:bg-paper hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-activity-refresh aria-label="Refresh"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.6" /><path d="M20 4v5h-5" /></svg></button>';

        return panelHeader('Activity', 'Recent runs', null, refresh) + panelBody(body) +
            '<footer class="flex items-center justify-between gap-2 border-t border-line px-4 py-3"><a class="' + LINK_CLASS + '" href="' + esc(urls.activity) + '">Open full activity</a>' +
            (project.next_run_at ? '<span class="font-mono text-[0.6875rem] text-muted">Next run ' + esc(formatTime(project.next_run_at)) + '</span>' : '') + '</footer>';
    }

    /* ------------------------------------------------------------------ */
    /* Inspector: settings                                                  */
    /* ------------------------------------------------------------------ */

    function renderSettingsPanel() {
        if (!urls.project_update) {
            return '';
        }

        const schedule = settingsData.schedule || { frequency: 'daily', time: '09:00' };
        const draft = panel.settingsDraft || null;
        const frequency = panel.frequency || (draft && draft.frequency) || schedule.frequency || 'daily';
        const disabled = panel.busy;
        const error = (key) => (panel.errors[key] ? '<p class="' + ERROR_CLASS + '">' + esc(panel.errors[key]) + '</p>' : '');
        const weekdays = Array.isArray(draft && draft.weekdays ? draft.weekdays : schedule.weekdays) ? (draft && draft.weekdays ? draft.weekdays : schedule.weekdays).map(Number) : [];
        const nameValue = draft && draft.name !== undefined ? draft.name : settingsData.name || project.name || '';
        const descriptionValue = draft && draft.description !== undefined ? draft.description : settingsData.description || '';
        let html = '<form class="flex flex-col gap-4" data-settings-form novalidate>';

        html +=
            '<div class="flex flex-col gap-1"><label class="' + LABEL_CLASS + '" for="ps-name">Name</label>' +
            '<input id="ps-name" type="text" name="name" maxlength="255" class="' + INPUT_CLASS + '" value="' + esc(nameValue) + '"' + (disabled ? ' disabled' : '') + ' required>' + error('name') + '</div>' +
            '<div class="flex flex-col gap-1"><label class="' + LABEL_CLASS + '" for="ps-description">Description <span class="font-normal text-muted">(optional)</span></label>' +
            '<textarea id="ps-description" name="description" rows="2" maxlength="2000" class="' + INPUT_CLASS + '"' + (disabled ? ' disabled' : '') + '>' + esc(descriptionValue) + '</textarea>' + error('description') + '</div>';

        html += '<fieldset class="flex flex-col gap-2"><legend class="' + LABEL_CLASS + ' mb-1">Schedule</legend>' +
            '<select name="frequency" class="' + INPUT_CLASS + '" aria-label="Frequency"' + (disabled ? ' disabled' : '') + '>' +
            optionList(Object.entries(settingsData.frequencies || {}).map(([value, label]) => ({ value, label })), frequency, null) + '</select>' + error('frequency');

        if (frequency === 'daily' || frequency === 'weekly') {
            html += '<div class="flex flex-col gap-1"><label class="' + LABEL_CLASS + '" for="ps-time">Time</label><input id="ps-time" type="time" name="time" class="' + INPUT_CLASS + ' font-mono" value="' + esc(schedule.time || '09:00') + '"' + (disabled ? ' disabled' : '') + '>' + error('time') + '</div>';
        }

        if (frequency === 'weekly') {
            html += '<div class="flex flex-col gap-1"><p class="' + LABEL_CLASS + '">Days</p><div class="flex flex-wrap gap-1">' +
                Object.entries(settingsData.weekdays || {}).map(([value, label]) =>
                    '<label class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-line px-2 py-1 text-xs text-ink has-[:checked]:border-accent has-[:checked]:bg-accent/5"><input type="checkbox" name="weekdays" value="' + esc(value) + '" class="accent-accent"' + (weekdays.includes(Number(value)) ? ' checked' : '') + (disabled ? ' disabled' : '') + '>' + esc(label.slice(0, 3)) + '</label>').join('') +
                '</div>' + error('weekdays') + '</div>';
        }

        if (frequency === 'interval') {
            html += '<div class="flex flex-col gap-1"><label class="' + LABEL_CLASS + '" for="ps-hours">Every</label><div class="flex items-center gap-2"><input id="ps-hours" type="number" min="1" max="168" name="hours" class="' + INPUT_CLASS + ' font-mono" value="' + esc(schedule.hours || 6) + '"' + (disabled ? ' disabled' : '') + '><span class="text-xs text-muted">hours</span></div>' + error('hours') + '</div>';
        }

        html += '<div class="flex flex-col gap-1"><label class="' + LABEL_CLASS + '" for="ps-timezone">Timezone</label>' +
            '<select id="ps-timezone" name="timezone" class="' + INPUT_CLASS + ' font-mono"' + (disabled ? ' disabled' : '') + '>' +
            optionList((settingsData.timezones || []).map((zone) => ({ value: zone, label: zone })), settingsData.timezone || '', 'Organization default (' + (settingsData.organization_timezone || 'UTC') + ')') +
            '</select>' + error('timezone') + '</div></fieldset>';

        if (project.schedule_summary) {
            html += '<p class="' + HINT_CLASS + '">Currently: ' + esc(project.schedule_summary) + (project.next_run_at ? ' · next ' + esc(formatTime(project.next_run_at)) : '') + '</p>';
        }

        if (panel.notice) {
            html += panel.notice.ok ? '<p class="rounded-md border border-state-ok/30 bg-state-ok/5 px-3 py-2 text-xs text-state-ok" role="status">' + esc(panel.notice.text) + '</p>' : errorNote(panel.notice.text);
        }

        html += '<div class="flex items-center gap-2"><button type="submit" class="' + PRIMARY_BUTTON_CLASS + '"' + (disabled ? ' disabled' : '') + '>' + (panel.busy ? SPINNER : '') + 'Save settings</button></div></form>';

        return panelHeader('Settings', project.status_label ? 'Broadcast · ' + project.status_label : 'Broadcast', null) + panelBody(html) +
            '<footer class="flex items-center justify-between gap-2 border-t border-line px-4 py-3"><a class="' + LINK_CLASS + '" href="' + esc(urls.settings) + '">Open full settings</a><span class="' + HINT_CLASS + '">Archive and delete live there.</span></footer>';
    }

    function readSettingsForm(form) {
        const body = { name: '', description: '', frequency: '', time: '', weekdays: [], hours: '', timezone: '' };

        form.querySelectorAll('[name]').forEach((input) => {
            const name = input.getAttribute('name');

            if (name === 'weekdays') {
                if (input.checked) {
                    body.weekdays.push(Number(input.value));
                }
            } else if (name in body) {
                body[name] = input.value;
            }
        });

        return body;
    }

    async function submitSettingsForm(form) {
        if (panel.busy) {
            return;
        }

        const body = readSettingsForm(form);
        const serial = panel.serial;
        panel.busy = true;
        panel.errors = {};
        panel.notice = null;
        renderPanel();

        try {
            const { ok, status, data } = await request(urls.project_update, { method: 'PATCH', body });

            if (!isCurrent(serial)) {
                return;
            }

            panel.busy = false;

            if (status === 422) {
                applyErrors(data);
                panel.notice = { ok: false, text: 'Check the highlighted fields.' };
                renderPanel();

                return;
            }

            if (!ok) {
                panel.notice = { ok: false, text: firstError(data, 'The settings could not be saved.') };
                renderPanel();

                return;
            }

            project = data.project || project;
            settingsData.name = body.name;
            settingsData.description = body.description;
            settingsData.timezone = body.timezone || null;
            settingsData.schedule = {
                frequency: body.frequency,
                time: body.time,
                weekdays: body.weekdays,
                hours: body.hours === '' ? undefined : Number(body.hours),
            };
            panel.frequency = null;
            panel.formDirty = false;
            panel.notice = { ok: true, text: data.message || 'Saved.' };
            renderProjectState();
            renderPanel();
            toast(data.message || 'Settings saved.');
        } catch {
            if (isCurrent(serial)) {
                panel.busy = false;
                panel.notice = { ok: false, text: 'Saving failed. Check your connection and try again.' };
                renderPanel();
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Inspector: issues                                                    */
    /* ------------------------------------------------------------------ */

    function renderIssuesPanel() {
        let body;

        if (issues.length === 0) {
            body = emptyNote('No issues. Every route compiles.');
        } else {
            body =
                '<ul class="flex flex-col gap-1.5">' +
                issues.map((issue, index) => {
                    const tone = issue.level === 'error' ? 'fail' : 'warn';
                    const node = issue.node_id ? state.nodes.get(issue.node_id) : null;
                    const target = node ? typeDef(node.type).label + ' · ' + nodeTitle(node) : issue.edge_id ? 'Connection' : 'Pipeline';

                    return (
                        '<li><button type="button" class="flex w-full cursor-pointer flex-col gap-0.5 rounded-md border border-state-' + tone + '/30 bg-state-' + tone + '/5 px-3 py-2 text-left hover:border-state-' + tone + ' focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent" data-issue-index="' + index + '">' +
                        '<span class="' + KICKER_CLASS + ' text-state-' + tone + '">' + esc(issue.level === 'error' ? 'Error' : 'Warning') + ' · ' + esc(target) + '</span>' +
                        '<span class="text-xs text-ink">' + esc(issue.message) + '</span>' +
                        '</button></li>'
                    );
                }).join('') +
                '</ul>';
        }

        return panelHeader(plural(issues.length, 'issue'), 'Validation', null) + panelBody(body);
    }

    function focusIssue(issue) {
        if (issue.node_id && state.nodes.has(issue.node_id)) {
            if (selectNode(issue.node_id)) {
                centerOn(issue.node_id);
            }
        } else if (issue.edge_id && state.edges.has(issue.edge_id)) {
            selectEdge(issue.edge_id);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Inspector: events                                                    */
    /* ------------------------------------------------------------------ */

    function currentNode() {
        return panel.kind === 'node' && panel.nodeId ? state.nodes.get(panel.nodeId) || null : null;
    }

    async function enableRoute(url) {
        if (!url) {
            return;
        }

        try {
            const { ok, data } = await request(url, { method: 'POST', body: {} });

            if (ok) {
                await flushSave();
                toast(data.message || 'Route enabled.');
                // Routes come back with the next save; ask for one by re-posting the graph.
                dirty = true;
                save();
            } else {
                toast(firstError(data, 'The route could not be enabled.'));
            }
        } catch {
            toast('The route could not be enabled. Check your connection.');
        }
    }

    els.inspector.addEventListener('click', (event) => {
        const target = event.target;

        if (target.closest('[data-panel-close]')) {
            if (panel.kind === 'node' || panel.kind === 'edge') {
                if (leavePanel()) {
                    selection.nodes.clear();
                    selection.edge = null;
                    renderSelection();
                    closePanel();
                }
            } else {
                closePanel();
            }

            els.canvas.focus({ preventScroll: true });

            return;
        }

        const tab = target.closest('[data-panel-tab]');

        if (tab) {
            if (leavePanel()) {
                panel.tab = tab.getAttribute('data-panel-tab');
                panel.mode = 'view';
                panel.notice = null;
                panel.errors = {};
                renderPanel();
            }

            return;
        }

        if (target.closest('[data-delete-selection]')) {
            if (leavePanel()) {
                deleteSelection();
            }

            return;
        }

        if (target.closest('[data-run-test]')) {
            runTest();

            return;
        }

        if (target.closest('[data-activity-refresh]')) {
            runsCache = null;
            renderPanel();

            return;
        }

        const issueButton = target.closest('[data-issue-index]');

        if (issueButton) {
            const issue = issues[Number(issueButton.getAttribute('data-issue-index'))];

            if (issue) {
                focusIssue(issue);
            }

            return;
        }

        const focus = target.closest('[data-test-focus-route]');

        if (focus) {
            const route = routes.find((entry) => String(entry.broadcast_id) === focus.getAttribute('data-test-focus-route'));

            if (route) {
                selection.nodes = new Set(route.node_ids || []);
                selection.edge = null;
                renderSelection();
                centerOn(route.destination_node_id);
            }

            return;
        }

        const enable = target.closest('[data-enable-route]');

        if (enable) {
            enableRoute(enable.getAttribute('data-enable-route'));

            return;
        }

        const node = currentNode();

        if (!node || !canEdit) {
            return;
        }

        if (target.closest('[data-record-create]')) {
            panel.mode = 'create';
            panel.formType = null;
            panel.errors = {};
            panel.notice = null;
            panel.focusField = 'name';
            renderPanel();

            return;
        }

        if (target.closest('[data-record-edit]')) {
            panel.mode = 'edit';
            panel.errors = {};
            panel.notice = null;
            panel.focusField = 'name';
            renderPanel();

            return;
        }

        if (target.closest('[data-record-cancel]')) {
            if (leavePanel()) {
                panel.mode = 'view';
                panel.errors = {};
                panel.notice = null;
                renderPanel();
            }

            return;
        }

        if (target.closest('[data-record-test]')) {
            const form = target.closest('[data-record-form]');
            testRecord(recordFor(node), node.type, form);

            return;
        }

        const insert = target.closest('[data-insert-placeholder]');

        if (insert) {
            const textarea = els.inspector.querySelector('[data-field="template"]');

            if (textarea) {
                const token = '{{' + insert.getAttribute('data-insert-placeholder') + '}}';
                const start = textarea.selectionStart ?? textarea.value.length;
                const end = textarea.selectionEnd ?? textarea.value.length;
                textarea.value = textarea.value.slice(0, start) + token + textarea.value.slice(end);
                textarea.focus({ preventScroll: true });
                textarea.setSelectionRange(start + token.length, start + token.length);
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }

            return;
        }

        if (target.closest('[data-rule-add]')) {
            commit(() => {
                const rules = Array.isArray(node.config.rules) ? node.config.rules : [];
                rules.push(node.type === 'filter' ? { type: 'contains', field: 'title', value: '' } : { type: 'strip_html' });
                node.config.rules = rules;
            });

            const added = els.inspector.querySelectorAll('[data-rule]');
            const last = added[added.length - 1];
            const focusable = last ? last.querySelector('select, input') : null;

            if (focusable) {
                focusable.focus({ preventScroll: true });
            }

            return;
        }

        const remove = target.closest('[data-rule-remove]');

        if (remove) {
            const index = Number(remove.getAttribute('data-rule-remove'));

            commit(() => {
                const rules = Array.isArray(node.config.rules) ? node.config.rules : [];
                rules.splice(index, 1);
                node.config.rules = rules;
            });
        }
    });

    els.inspector.addEventListener('submit', (event) => {
        const recordForm = event.target.closest('[data-record-form]');

        if (recordForm) {
            event.preventDefault();
            submitRecordForm(recordForm);

            return;
        }

        const settingsForm = event.target.closest('[data-settings-form]');

        if (settingsForm) {
            event.preventDefault();
            submitSettingsForm(settingsForm);
        }
    });

    function readFieldValue(input) {
        if (input.getAttribute('data-kind') === 'int') {
            return input.value === '' ? null : Number(input.value);
        }

        return input.value;
    }

    els.inspector.addEventListener('input', (event) => {
        if (event.target.closest('[data-record-form], [data-settings-form]')) {
            panel.formDirty = true;

            const errorFor = event.target.getAttribute('name');

            if (errorFor && panel.errors[errorFor]) {
                delete panel.errors[errorFor];
                const message = els.inspector.querySelector('[data-error-for="' + errorFor + '"]');

                if (message) {
                    message.remove();
                }
            }

            return;
        }

        const input = event.target.closest('[data-field]');
        const node = currentNode();

        if (!input || !node || !canEdit) {
            return;
        }

        const key = node.id + ':' + input.getAttribute('data-field');
        const record = liveEditKey !== key;
        liveEditKey = key;

        commit(() => {
            setConfig(node.id, input.getAttribute('data-field'), readFieldValue(input));
        }, { history: record, inspector: false });

        const heading = els.inspector.querySelector('[data-panel-title]');

        if (heading) {
            heading.textContent = nodeTitle(node);
        }
    });

    els.inspector.addEventListener('change', (event) => {
        const recordForm = event.target.closest('[data-record-form]');

        if (recordForm) {
            if (event.target.getAttribute('name') === 'type') {
                panel.draft = readRecordForm(recordForm);
                panel.formType = event.target.value;
                panel.errors = {};
                renderPanel();
            }

            return;
        }

        const settingsForm = event.target.closest('[data-settings-form]');

        if (settingsForm) {
            if (event.target.getAttribute('name') === 'frequency') {
                panel.settingsDraft = readSettingsForm(settingsForm);
                panel.frequency = event.target.value;
                panel.formDirty = true;
                renderPanel();
                const again = els.inspector.querySelector('[name="frequency"]');

                if (again) {
                    again.focus({ preventScroll: true });
                }
            }

            return;
        }

        const input = event.target.closest('[data-field]');
        const node = currentNode();

        if (!input || !node || !canEdit) {
            return;
        }

        liveEditKey = null;

        // Selects re-render the panel: a rule type decides which inputs it shows.
        if (input.tagName === 'SELECT') {
            const field = input.getAttribute('data-field');

            commit(() => {
                setConfig(node.id, field, readFieldValue(input));

                if (field.endsWith('.type')) {
                    const index = Number(field.split('.')[1]);
                    const rule = node.config.rules[index];
                    const def = (node.type === 'filter' ? filterRuleTypes : transformRuleTypes).find((type) => type.value === rule.type) || {};

                    if (def.needs_field && !rule.field) {
                        rule.field = 'title';
                    }
                }
            });

            const again = els.inspector.querySelector('[data-field="' + field + '"]');

            if (again) {
                again.focus({ preventScroll: true });
            }
        }
    });

    /* ------------------------------------------------------------------ */
    /* Pointer interaction                                                  */
    /* ------------------------------------------------------------------ */

    function isBackground(target) {
        return !target.closest('[data-node-id]') && !target.closest('.pipeline-edge-group');
    }

    els.canvas.addEventListener('pointerdown', (event) => {
        if (drag !== null) {
            return;
        }

        const target = event.target;
        const handle = target.closest('[data-handle]');
        const nodeEl = target.closest('[data-node-id]');
        const edgeEl = target.closest('.pipeline-edge-group');
        const pan = event.button === 1 || spaceHeld || (event.button === 0 && isBackground(target));

        if (pan) {
            event.preventDefault();
            drag = { mode: 'pan', startX: event.clientX, startY: event.clientY, viewX: view.x, viewY: view.y, moved: false, pointerId: event.pointerId };
            els.canvas.classList.add('is-panning');
            capturePointer(els.canvas, event.pointerId);

            return;
        }

        if (event.button !== 0) {
            return;
        }

        if (handle && nodeEl && canEdit) {
            event.preventDefault();
            const fromId = nodeEl.dataset.nodeId;
            const def = typeDef(state.nodes.get(fromId).type);
            const direction = def.has_output ? 'out' : 'in';
            const valid = new Set();

            state.nodes.forEach((candidate) => {
                if (direction === 'out' ? canConnect(fromId, candidate.id) : canConnect(candidate.id, fromId)) {
                    valid.add(candidate.id);
                }
            });

            drag = { mode: 'connect', fromId, direction, valid, target: null, invalid: null, pointerId: event.pointerId };
            els.canvas.classList.add('is-connecting');
            nodeEls.forEach((el, id) => el.classList.toggle('is-dimmed', id !== fromId && !valid.has(id)));
            capturePointer(els.canvas, event.pointerId);
            updateDraftEdge(event.clientX, event.clientY);

            return;
        }

        if (nodeEl) {
            const id = nodeEl.dataset.nodeId;

            if (event.shiftKey) {
                if (!selectNode(id, { additive: true })) {
                    return;
                }
            } else if (!selection.nodes.has(id)) {
                if (!selectNode(id)) {
                    return;
                }
            } else if (selection.edge !== null) {
                selection.edge = null;
                renderSelection();
                syncPanelToSelection();
            } else if (panel.kind !== 'node') {
                syncPanelToSelection();
            }

            if (!canEdit) {
                ensureVisible(id);

                return;
            }

            const origin = toWorld(event.clientX, event.clientY);
            const starts = new Map();
            selection.nodes.forEach((selectedId) => {
                const node = state.nodes.get(selectedId);
                starts.set(selectedId, { x: node.x, y: node.y });
            });

            drag = { mode: 'node', origin, starts, moved: false, pointerId: event.pointerId };
            capturePointer(els.canvas, event.pointerId);

            return;
        }

        if (edgeEl) {
            selectEdge(edgeEl.dataset.edgeId);
        }
    });

    /*
     * The preview line while connecting: from the handle the drag started
     * on towards the pointer, snapping onto a node under the pointer. Solid
     * accent over a valid target, red over one that cannot take the
     * connection, dashed in between.
     */
    function updateDraftEdge(clientX, clientY) {
        if (!drag || drag.mode !== 'connect') {
            return;
        }

        const origin = state.nodes.get(drag.fromId);
        const pointer = toWorld(clientX, clientY);
        const other = drag.target ? state.nodes.get(drag.target) : drag.invalid ? state.nodes.get(drag.invalid) : null;
        let d;

        if (other) {
            const pair = drag.direction === 'out' ? anchorSides(origin, other) : anchorSides(other, origin);

            d = drag.direction === 'out'
                ? edgePath(rimPoint(origin, pair.from), pair.from, rimPoint(other, pair.to), pair.to)
                : edgePath(rimPoint(other, pair.from), pair.from, rimPoint(origin, pair.to), pair.to);
        } else {
            const ghost = { x: pointer.x - NODE_WIDTH / 2, y: pointer.y - NODE_HEIGHT / 2 };
            const pair = drag.direction === 'out' ? anchorSides(origin, ghost) : anchorSides(ghost, origin);

            d = drag.direction === 'out'
                ? edgePath(rimPoint(origin, pair.from), pair.from, pointer, pair.to)
                : edgePath(pointer, pair.from, rimPoint(origin, pair.to), pair.to);
        }

        showDraftEdge(d, drag.target ? 'valid' : drag.invalid ? 'invalid' : null);
    }

    function nodeUnderPointer(clientX, clientY) {
        const under = document.elementFromPoint(clientX, clientY);
        const nodeEl = under ? under.closest('[data-node-id]') : null;

        return nodeEl && els.nodesLayer.contains(nodeEl) ? nodeEl.dataset.nodeId : null;
    }

    function canvasHit(clientX, clientY) {
        const under = document.elementFromPoint(clientX, clientY);

        if (!under || !els.canvas.contains(under)) {
            return false;
        }

        return !under.closest('[data-inspector], [data-palette], [data-zoom-controls]');
    }

    function updateGhost(clientX, clientY) {
        if (!els.ghost || !drag || drag.mode !== 'palette') {
            return;
        }

        const rect = root.getBoundingClientRect();
        const x = clientX - rect.left - (NODE_WIDTH / 2) * view.zoom;
        const y = clientY - rect.top - (NODE_HEIGHT / 2) * view.zoom;
        els.ghost.style.transform = 'translate(' + x + 'px, ' + y + 'px) scale(' + view.zoom + ')';
        const inside = canvasHit(clientX, clientY);
        els.ghost.classList.toggle('is-outside', !inside);
        els.canvas.classList.toggle('is-dropping', inside);
    }

    window.addEventListener('pointermove', (event) => {
        if (drag === null || event.pointerId !== drag.pointerId) {
            return;
        }

        if (drag.mode === 'pan') {
            const dx = event.clientX - drag.startX;
            const dy = event.clientY - drag.startY;

            if (!drag.moved && Math.hypot(dx, dy) > 3) {
                drag.moved = true;
            }

            if (drag.moved) {
                view.x = drag.viewX + dx;
                view.y = drag.viewY + dy;
                applyView();
            }

            return;
        }

        if (drag.mode === 'node') {
            const pointer = toWorld(event.clientX, event.clientY);
            const dx = pointer.x - drag.origin.x;
            const dy = pointer.y - drag.origin.y;

            if (!drag.moved && Math.hypot(dx, dy) * view.zoom > 3) {
                drag.moved = true;
                pushHistory();
                drag.starts.forEach((_, id) => nodeEls.get(id)?.classList.add('is-dragging'));
            }

            if (!drag.moved) {
                return;
            }

            drag.starts.forEach((start, id) => {
                const node = state.nodes.get(id);

                if (node) {
                    node.x = snap(start.x + dx);
                    node.y = snap(start.y + dy);
                    positionNodeEl(node);
                }
            });
            renderEdges();

            return;
        }

        if (drag.mode === 'connect') {
            const hovered = nodeUnderPointer(event.clientX, event.clientY);
            const candidate = hovered && drag.valid.has(hovered) ? hovered : null;
            const invalid = hovered && !candidate && hovered !== drag.fromId ? hovered : null;

            if (candidate !== drag.target) {
                if (drag.target) {
                    nodeEls.get(drag.target)?.classList.remove('is-drop-target');
                }

                drag.target = candidate;

                if (candidate) {
                    nodeEls.get(candidate)?.classList.add('is-drop-target');
                }
            }

            if (invalid !== drag.invalid) {
                if (drag.invalid) {
                    nodeEls.get(drag.invalid)?.classList.remove('is-drop-invalid');
                }

                drag.invalid = invalid;

                if (invalid) {
                    nodeEls.get(invalid)?.classList.add('is-drop-invalid');
                }
            }

            updateDraftEdge(event.clientX, event.clientY);

            return;
        }

        if (drag.mode === 'palette') {
            if (!drag.moved && Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) > DRAG_THRESHOLD) {
                drag.moved = true;

                if (els.ghost) {
                    const preview = createNodeEl({ id: 'ghost', type: drag.type, x: 0, y: 0, config: {} });
                    preview.removeAttribute('tabindex');
                    preview.querySelector('[data-node-title]').textContent = typeDef(drag.type).label;
                    preview.querySelector('[data-node-meta]').textContent = 'Drop on the canvas';
                    preview.querySelectorAll('[data-handle]').forEach((handle) => handle.remove());
                    els.ghost.replaceChildren(preview);
                    els.ghost.hidden = false;
                }
            }

            if (drag.moved) {
                updateGhost(event.clientX, event.clientY);
            }
        }
    });

    function endDrag(event) {
        if (drag === null || (event && event.pointerId !== drag.pointerId)) {
            return;
        }

        const current = drag;
        drag = null;

        try {
            (current.captureTarget || els.canvas).releasePointerCapture(current.pointerId);
        } catch {
            // The capture may already be gone.
        }

        if (current.mode === 'pan') {
            els.canvas.classList.remove('is-panning');

            if (!current.moved && event && event.type === 'pointerup') {
                if (leavePanel()) {
                    const hadSelection = selection.nodes.size > 0 || selection.edge !== null;
                    selection.nodes.clear();
                    selection.edge = null;
                    renderSelection();

                    if (hadSelection || panel.kind === 'node' || panel.kind === 'edge') {
                        closePanel();
                    }
                }

                els.canvas.focus({ preventScroll: true });
            }

            return;
        }

        if (current.mode === 'node') {
            current.starts.forEach((_, id) => nodeEls.get(id)?.classList.remove('is-dragging'));

            if (current.moved) {
                afterChange({ inspector: false });
            } else if (selection.nodes.size === 1) {
                ensureVisible(selection.nodes.values().next().value);
            }

            return;
        }

        if (current.mode === 'connect') {
            els.canvas.classList.remove('is-connecting');
            nodeEls.forEach((el) => {
                el.classList.remove('is-dimmed', 'is-drop-target', 'is-drop-invalid');
            });
            hideDraftEdge();

            if (event && event.type === 'pointerup') {
                if (current.target) {
                    if (current.direction === 'out') {
                        addEdge(current.fromId, current.target);
                    } else {
                        addEdge(current.target, current.fromId);
                    }
                } else if (current.invalid) {
                    const reason = current.direction === 'out' ? connectBlockReason(current.fromId, current.invalid) : connectBlockReason(current.invalid, current.fromId);

                    if (reason) {
                        toast(reason);
                    }
                }
            }

            return;
        }

        if (current.mode === 'palette') {
            els.canvas.classList.remove('is-dropping');

            if (els.ghost) {
                els.ghost.hidden = true;
                els.ghost.replaceChildren();
            }

            if (!event || event.type !== 'pointerup') {
                return;
            }

            if (!current.moved) {
                paletteDragHandled = true;
                addNode(current.type);

                return;
            }

            paletteDragHandled = true;

            if (canvasHit(event.clientX, event.clientY)) {
                const world = toWorld(event.clientX, event.clientY);
                addNode(current.type, { x: world.x - NODE_WIDTH / 2, y: world.y - NODE_HEIGHT / 2 }, { exact: true });
            }
        }
    }

    window.addEventListener('pointerup', endDrag);
    window.addEventListener('pointercancel', endDrag);

    if (els.palette) {
        els.palette.addEventListener('pointerdown', (event) => {
            const button = event.target.closest('[data-palette-add]');

            if (!button || event.button !== 0 || drag !== null || !canEdit) {
                return;
            }

            drag = { mode: 'palette', type: button.getAttribute('data-palette-add'), startX: event.clientX, startY: event.clientY, moved: false, pointerId: event.pointerId, captureTarget: button };
            capturePointer(button, event.pointerId);
        });
    }

    els.canvas.addEventListener('dblclick', (event) => {
        const nodeEl = event.target.closest('[data-node-id]');

        if (nodeEl && selection.nodes.has(nodeEl.dataset.nodeId)) {
            const first = els.inspector.querySelector('select, input, textarea');

            if (first && canEdit) {
                first.focus({ preventScroll: true });
            }
        }
    });

    els.canvas.addEventListener(
        'wheel',
        (event) => {
            event.preventDefault();

            if (event.ctrlKey || event.metaKey) {
                zoomAt(event.clientX, event.clientY, Math.exp(-event.deltaY * 0.0015));

                return;
            }

            const dx = event.shiftKey && event.deltaX === 0 ? event.deltaY : event.deltaX;
            const dy = event.shiftKey && event.deltaX === 0 ? 0 : event.deltaY;
            view.x -= dx;
            view.y -= dy;
            applyView();
        },
        { passive: false },
    );

    // Focus can scroll an overflow:hidden box; the viewport is ours to move.
    els.canvas.addEventListener('scroll', () => {
        els.canvas.scrollLeft = 0;
        els.canvas.scrollTop = 0;
    });

    /* ------------------------------------------------------------------ */
    /* Keyboard                                                             */
    /* ------------------------------------------------------------------ */

    function isTypingTarget(target) {
        if (!(target instanceof Element)) {
            return false;
        }

        return target.closest('input, textarea, select, [contenteditable="true"]') !== null;
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === ' ' && !isTypingTarget(event.target)) {
            spaceHeld = true;
        }

        const meta = event.ctrlKey || event.metaKey;

        if (meta && (event.key === 's' || event.key === 'S')) {
            event.preventDefault();

            if (canEdit) {
                flushSave();
            }

            return;
        }

        if (isTypingTarget(event.target)) {
            if (event.key === 'Escape') {
                event.target.blur();
                els.canvas.focus({ preventScroll: true });
            }

            return;
        }

        if (meta && (event.key === 'z' || event.key === 'Z')) {
            event.preventDefault();

            if (event.shiftKey) {
                redo();
            } else {
                undo();
            }

            return;
        }

        if (meta && (event.key === 'y' || event.key === 'Y')) {
            event.preventDefault();
            redo();

            return;
        }

        if (meta && (event.key === 'a' || event.key === 'A')) {
            event.preventDefault();
            selectAll();

            return;
        }

        if (event.key === 'Escape') {
            if (drag && (drag.mode === 'connect' || drag.mode === 'palette')) {
                endDrag({ pointerId: drag.pointerId, type: 'cancel' });
            } else if (panel.kind !== null && panel.kind !== 'node' && panel.kind !== 'edge') {
                closePanel();
            } else {
                if (leavePanel()) {
                    selection.nodes.clear();
                    selection.edge = null;
                    renderSelection();
                    closePanel();
                }
            }

            return;
        }

        if ((event.key === 'Delete' || event.key === 'Backspace') && canEdit) {
            if ((selection.nodes.size > 0 || selection.edge !== null) && !els.inspector.contains(event.target)) {
                event.preventDefault();
                deleteSelection();
            }

            return;
        }

        if ((event.key === 'Enter' || event.key === ' ') && event.target instanceof Element) {
            const nodeEl = event.target.closest('[data-node-id]');

            if (nodeEl) {
                event.preventDefault();
                selectNode(nodeEl.dataset.nodeId, { additive: event.shiftKey });
                ensureVisible(nodeEl.dataset.nodeId);
            }

            return;
        }

        const nudges = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };

        if (nudges[event.key] && selection.nodes.size > 0 && canEdit && !els.inspector.contains(event.target)) {
            event.preventDefault();
            const step = (event.shiftKey ? 5 : 1) * GRID;
            const [dx, dy] = nudges[event.key];

            commit(() => {
                selection.nodes.forEach((id) => {
                    const node = state.nodes.get(id);
                    node.x += dx * step;
                    node.y += dy * step;
                });
            }, { inspector: false });
        }
    });

    document.addEventListener('keyup', (event) => {
        if (event.key === ' ') {
            spaceHeld = false;
        }
    });

    /* ------------------------------------------------------------------ */
    /* Chrome controls                                                      */
    /* ------------------------------------------------------------------ */

    function setPaletteOpen(open) {
        if (!els.palette) {
            return;
        }

        els.palette.classList.toggle('is-open', open);
        document.querySelectorAll('[data-palette-toggle]').forEach((toggle) => toggle.setAttribute('aria-expanded', open ? 'true' : 'false'));
    }

    root.addEventListener('click', (event) => {
        const add = event.target.closest('[data-palette-add]');

        if (add) {
            if (paletteDragHandled) {
                paletteDragHandled = false;

                return;
            }

            addNode(add.getAttribute('data-palette-add'));

            return;
        }

        if (event.target.closest('[data-palette-toggle]')) {
            setPaletteOpen(!els.palette.classList.contains('is-open'));

            return;
        }

        if (event.target.closest('[data-zoom-in]')) {
            zoomCenter(1.2);
        } else if (event.target.closest('[data-zoom-out]')) {
            zoomCenter(1 / 1.2);
        } else if (event.target.closest('[data-zoom-reset]')) {
            zoomCenter(1 / view.zoom);
        } else if (event.target.closest('[data-zoom-fit]')) {
            fitToContent();
        } else if (event.target.closest('[data-undo]')) {
            undo();
        } else if (event.target.closest('[data-redo]')) {
            redo();
        }
    });

    document.querySelectorAll('[data-open-panel]').forEach((button) => {
        if (root.contains(button)) {
            return;
        }

        button.addEventListener('click', () => {
            const kind = button.getAttribute('data-open-panel');

            if (kind === 'test') {
                if (panel.kind === 'test' && !testing) {
                    closePanel();
                } else {
                    runTest();
                }

                return;
            }

            togglePanel(kind, { focusField: button.getAttribute('data-focus-field') || null });
        });
    });

    root.querySelectorAll('[data-palette-icon]').forEach((slot) => {
        const type = slot.getAttribute('data-palette-icon');
        slot.classList.add('pipeline-node-tile');
        slot.innerHTML = ICONS[type] || '';
    });

    const flash = document.querySelector('[data-editor-flash]');

    if (flash) {
        setTimeout(() => {
            flash.remove();
        }, 6000);
    }

    /* ------------------------------------------------------------------ */
    /* Boot                                                                 */
    /* ------------------------------------------------------------------ */

    if (!canEdit) {
        els.canvas.classList.add('is-readonly');
    }

    renderNodes();
    renderEdges();
    renderIssues();
    renderHistoryButtons();
    renderHeaderToggles();

    let restoredView = null;

    try {
        const stored = window.sessionStorage.getItem(viewStorageKey());
        restoredView = stored ? JSON.parse(stored) : null;
    } catch {
        restoredView = null;
    }

    const saved = payload.graph.viewport || restoredView;

    if (saved && Number.isFinite(saved.zoom) && Number.isFinite(saved.x) && Number.isFinite(saved.y)) {
        view.x = saved.x;
        view.y = saved.y;
        view.zoom = clamp(saved.zoom, ZOOM_MIN, ZOOM_MAX);
        applyView();
    } else {
        fitToContent();
    }

    window.addEventListener('resize', () => {
        if (state.nodes.size > 0 && !saved) {
            fitToContent();
        }
    });
})();
