/*
 * Reveals a floating rail on small screens. Each rail is one element at every
 * width, so this only toggles the class that hides it below `lg`.
 */
function setSidebarHidden(sidebar, hidden) {
    sidebar.classList.toggle('max-lg:hidden', hidden);

    document.querySelectorAll(`[data-sidebar-toggle][aria-controls="${sidebar.id}"]`).forEach((toggle) => {
        toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
    });
}

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-sidebar-toggle]');

    if (toggle === null) {
        return;
    }

    const sidebar = document.getElementById(toggle.getAttribute('aria-controls'));

    if (sidebar === null) {
        return;
    }

    setSidebarHidden(sidebar, sidebar.classList.contains('max-lg:hidden') === false);
});

/*
 * User settings: mark the rail item for the section in view, and close the
 * mobile drawer after choosing one so the card is not covered.
 */
(function () {
    const sidebar = document.getElementById('settings-sidebar');

    if (sidebar === null) {
        return;
    }

    function currentHash() {
        return window.location.hash || '#profile';
    }

    function syncSection() {
        const hash = currentHash();

        sidebar.querySelectorAll('a[href^="#"]').forEach((link) => {
            const current = link.getAttribute('href') === hash;

            link.classList.toggle('bg-paper', current);
            link.classList.toggle('text-ink', current);
            link.classList.toggle('text-muted', !current);

            if (current) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    sidebar.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');

        if (link === null) {
            return;
        }

        if (window.matchMedia('(max-width: 1023px)').matches) {
            setSidebarHidden(sidebar, true);
        }
    });

    window.addEventListener('hashchange', syncSection);
    syncSection();
})();

/*
 * Broadcast filter editor: add and remove rule rows, and show only the fields
 * each rule type needs. Indexes are rewritten before submit so gaps from
 * removals do not confuse validation.
 */
(function () {
    const fieldTypes = new Set(['contains', 'does_not_contain']);
    const hoursTypes = new Set(['published_after', 'published_before']);

    function syncRuleFields(rule) {
        const type = rule.querySelector('[data-filter-type]')?.value ?? '';
        const fieldWrap = rule.querySelector('[data-filter-field-wrap]');
        const valueWrap = rule.querySelector('[data-filter-value-wrap]');
        const hoursWrap = rule.querySelector('[data-filter-hours-wrap]');

        if (fieldWrap !== null) {
            fieldWrap.hidden = !fieldTypes.has(type);
        }

        if (valueWrap !== null) {
            valueWrap.hidden = hoursTypes.has(type);
        }

        if (hoursWrap !== null) {
            hoursWrap.hidden = !hoursTypes.has(type);
        }
    }

    function reindex(form) {
        form.querySelectorAll('[data-filter-rule]').forEach((rule, index) => {
            rule.querySelectorAll('[name]').forEach((input) => {
                input.name = input.name.replace(/rules\[(?:\d+|__INDEX__)\]/, `rules[${index}]`);
            });
        });
    }

    document.addEventListener('change', (event) => {
        const select = event.target.closest('[data-filter-type]');

        if (select === null) {
            return;
        }

        const rule = select.closest('[data-filter-rule]');

        if (rule !== null) {
            syncRuleFields(rule);
        }
    });

    document.addEventListener('click', (event) => {
        const add = event.target.closest('[data-filter-add]');

        if (add !== null) {
            const form = add.closest('[data-filter-form]');
            const list = form?.querySelector('[data-filter-rules]');
            const template = form?.querySelector('[data-filter-rule-template]');

            if (form === null || list === null || template === null) {
                return;
            }

            const fragment = template.content.cloneNode(true);
            const rule = fragment.querySelector('[data-filter-rule]');

            list.appendChild(fragment);
            reindex(form);

            if (rule !== null) {
                syncRuleFields(list.lastElementChild);
            }

            return;
        }

        const remove = event.target.closest('[data-filter-remove]');

        if (remove !== null) {
            const form = remove.closest('[data-filter-form]');
            const rule = remove.closest('[data-filter-rule]');

            rule?.remove();

            if (form !== null) {
                reindex(form);
            }
        }
    });

    document.querySelectorAll('[data-filter-rule]').forEach(syncRuleFields);
})();

/*
 * Broadcast transform editor: add and remove formatting rules, showing only
 * the fields each rule type needs.
 */
(function () {
    const lengthTypes = new Set(['truncate']);
    const valueTypes = new Set(['prepend', 'append']);
    const patternTypes = new Set(['replace']);

    function syncRuleFields(rule) {
        const type = rule.querySelector('[data-transform-type]')?.value ?? '';
        const lengthWrap = rule.querySelector('[data-transform-length-wrap]');
        const valueWrap = rule.querySelector('[data-transform-value-wrap]');
        const patternWrap = rule.querySelector('[data-transform-pattern-wrap]');
        const replacementWrap = rule.querySelector('[data-transform-replacement-wrap]');

        if (lengthWrap !== null) {
            lengthWrap.hidden = !lengthTypes.has(type);
        }

        if (valueWrap !== null) {
            valueWrap.hidden = !valueTypes.has(type);
        }

        if (patternWrap !== null) {
            patternWrap.hidden = !patternTypes.has(type);
        }

        if (replacementWrap !== null) {
            replacementWrap.hidden = !patternTypes.has(type);
        }
    }

    function reindex(form) {
        form.querySelectorAll('[data-transform-rule]').forEach((rule, index) => {
            rule.querySelectorAll('[name]').forEach((input) => {
                input.name = input.name.replace(/rules\[(?:\d+|__INDEX__)\]/, `rules[${index}]`);
            });
        });
    }

    document.addEventListener('change', (event) => {
        const select = event.target.closest('[data-transform-type]');

        if (select === null) {
            return;
        }

        const rule = select.closest('[data-transform-rule]');

        if (rule !== null) {
            syncRuleFields(rule);
        }
    });

    document.addEventListener('click', (event) => {
        const add = event.target.closest('[data-transform-add]');

        if (add !== null) {
            const form = add.closest('[data-transform-form]');
            const list = form?.querySelector('[data-transform-rules]');
            const template = form?.querySelector('[data-transform-rule-template]');

            if (form === null || list === null || template === null) {
                return;
            }

            list.appendChild(template.content.cloneNode(true));
            reindex(form);
            syncRuleFields(list.lastElementChild);

            return;
        }

        const remove = event.target.closest('[data-transform-remove]');

        if (remove !== null) {
            const form = remove.closest('[data-transform-form]');
            const rule = remove.closest('[data-transform-rule]');

            rule?.remove();

            if (form !== null) {
                reindex(form);
            }
        }
    });

    document.querySelectorAll('[data-transform-rule]').forEach(syncRuleFields);
})();

/*
 * Project card menus: only one open at a time, and close on outside click.
 */
document.addEventListener('click', (event) => {
    document.querySelectorAll('details[data-project-menu][open]').forEach((menu) => {
        if (!menu.contains(event.target)) {
            menu.removeAttribute('open');
        }
    });
});

/*
 * Project overview: show in-place progress while a run form posts.
 */
document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-run-form]');

    if (form === null) {
        return;
    }

    const progress = document.querySelector('[data-run-progress]');

    if (progress !== null) {
        progress.hidden = false;
    }

    form.querySelectorAll('[data-run-submit]').forEach((button) => {
        button.setAttribute('disabled', 'disabled');
        button.setAttribute('aria-busy', 'true');
    });
});

/*
 * Forms that create something once (first organization): swap to the pending
 * label, announce it, and ignore a second submit while the first is in
 * flight. Fields stay enabled so their values are posted. The server still
 * refuses duplicates on its own; this only spares the extra request.
 */
function setFormPending(form, pending) {
    form.toggleAttribute('data-pending', pending);

    form.querySelectorAll('[data-pending-submit]').forEach((button) => {
        button.disabled = pending;
        button.toggleAttribute('aria-busy', pending);
    });

    form.querySelectorAll('[data-pending-idle]').forEach((label) => label.classList.toggle('invisible', pending));
    form.querySelectorAll('[data-pending-busy]').forEach((label) => label.classList.toggle('invisible', !pending));

    form.querySelectorAll('[data-pending-status]').forEach((status) => {
        status.textContent = pending ? status.getAttribute('data-pending-status') : '';
    });
}

document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-pending-form]');

    if (form === null) {
        return;
    }

    if (form.hasAttribute('data-pending')) {
        event.preventDefault();

        return;
    }

    setFormPending(form, true);
});

// Coming back from history restores the page as it was left, mid-submit.
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-pending-form][data-pending]').forEach((form) => setFormPending(form, false));
});

// A form-level error after a failed submit takes focus so it is announced first.
document.querySelector('[data-focus-on-load]')?.focus();

/*
 * Native dialogs opened from overview actions (schedule drawer).
 */
document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-open-dialog]');

    if (opener !== null) {
        const dialog = document.getElementById(opener.getAttribute('data-open-dialog'));

        if (dialog instanceof HTMLDialogElement) {
            dialog.showModal();
        }

        return;
    }

    if (event.target.closest('[data-close-dialog]')) {
        const dialog = event.target.closest('dialog');

        if (dialog instanceof HTMLDialogElement) {
            dialog.close();
        }
    }
});

/*
 * Sidebar org/project menus: one open at a time, close on outside click.
 */
document.addEventListener('click', (event) => {
    document.querySelectorAll('details[data-sidebar-menu][open]').forEach((menu) => {
        if (!menu.contains(event.target)) {
            menu.removeAttribute('open');
        }
    });
});

/*
 * Schedule fields: the checked frequency tile reveals only the inputs it needs.
 * CSS does this with :has() (see app.css), so it works without JS; this only
 * sets `hidden` for browsers without :has() support.
 */
(function () {
    if (window.CSS?.supports?.('selector(:has(*))')) {
        return;
    }

    function syncScheduleFields(root) {
        const frequency = root.querySelector('[data-schedule-frequency]:checked')?.value ?? '';

        root.querySelectorAll('[data-schedule-panel]').forEach((panel) => {
            const modes = (panel.getAttribute('data-schedule-panel') ?? '').split(/\s+/).filter(Boolean);
            panel.hidden = !modes.includes(frequency);
        });
    }

    document.addEventListener('change', (event) => {
        const input = event.target.closest('[data-schedule-frequency]');
        const root = input?.closest('[data-schedule-fields]');

        if (root) {
            syncScheduleFields(root);
        }
    });

    document.querySelectorAll('[data-schedule-fields]').forEach(syncScheduleFields);
})();

import './mapping-editor';

/*
 * Timezone combobox (<x-timezone-picker>: first organization and broadcast
 * schedules). Progressive enhancement over the
 * native <select>: without JS the select works as-is; with JS a searchable
 * ARIA combobox drives the same select, so the posted `timezone` field and its
 * server-side validation do not change. Matching ignores case and treats "/"
 * and "_" as spaces, so "new york" finds America/New_York.
 */
const normalizeZone = (value) =>
    value
        .toLowerCase()
        .replace(/[/_]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

function enhanceTimezonePicker(picker) {
    const select = picker.querySelector('select');
    const name = picker.querySelector('[data-timezone-name]');
    const subtitle = picker.querySelector('[data-timezone-subtitle]');

    if (!select || !name || !subtitle) {
        return;
    }

    const options = Array.from(select.options).map((option) => ({
        value: option.value,
        // Options may carry a label (e.g. "Use organization timezone (…)"); search both.
        label: option.textContent.trim() || option.value,
        subtitle: option.dataset.subtitle ?? '',
        key: normalizeZone(`${option.value} ${option.textContent}`),
    }));
    const fieldId = select.id;
    const listId = `${fieldId}-listbox`;
    const selectedLabel = () => select.selectedOptions[0]?.textContent.trim() ?? select.value;
    const hadFocus = select.hasAttribute('autofocus') || document.activeElement === select;

    const input = document.createElement('input');
    input.type = 'text';
    input.id = fieldId;
    input.value = selectedLabel();
    input.placeholder = 'Search timezones';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.className = 'w-full min-w-0 truncate bg-transparent text-base text-ink outline-none placeholder:text-muted/70';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);
    ['aria-invalid', 'aria-describedby'].forEach((attribute) => {
        if (select.hasAttribute(attribute)) {
            input.setAttribute(attribute, select.getAttribute(attribute));
        }
    });

    // The select stays in the form as the value holder, out of reach of focus and AT.
    select.id = `${fieldId}-native`;
    select.removeAttribute('autofocus');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    select.classList.add('hidden');

    name.parentElement.removeAttribute('aria-hidden');
    subtitle.setAttribute('aria-hidden', 'true');
    name.replaceWith(input);

    const list = document.createElement('ul');
    list.id = listId;
    list.hidden = true;
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', 'Timezones');
    list.className =
        'absolute top-full right-0 left-0 z-20 mt-2 max-h-72 overflow-y-auto rounded-lg border border-line bg-paper-raised py-1 shadow-lg';
    picker.append(list);

    let matches = options;
    let active = -1;

    const isOpen = () => !list.hidden;

    const highlight = (index) => {
        list.querySelector('[aria-selected="true"]')?.setAttribute('aria-selected', 'false');
        list.querySelector('.bg-accent\\/10')?.classList.remove('bg-accent/10');
        active = index;

        const item = index >= 0 ? list.children[index] : null;

        if (item && item.getAttribute('role') === 'option') {
            item.setAttribute('aria-selected', 'true');
            item.classList.add('bg-accent/10');
            item.scrollIntoView({ block: 'nearest' });
            input.setAttribute('aria-activedescendant', item.id);
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    const render = (query) => {
        const needle = normalizeZone(query);
        matches = needle ? options.filter((option) => option.key.includes(needle)) : options;

        const fragment = document.createDocumentFragment();

        matches.forEach((option, index) => {
            const item = document.createElement('li');
            item.id = `${listId}-${index}`;
            item.dataset.index = String(index);
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', 'false');
            item.className = 'flex cursor-pointer flex-col px-4 py-2 leading-tight';

            const label = document.createElement('span');
            label.className = option.value === select.value ? 'text-sm font-semibold text-ink' : 'text-sm text-ink';
            label.textContent = option.label;

            const hint = document.createElement('span');
            hint.className = 'text-xs text-muted';
            hint.textContent = option.subtitle;

            item.append(label, hint);
            fragment.append(item);
        });

        if (matches.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'px-4 py-2 text-sm text-muted';
            empty.textContent = 'No matching timezones';
            fragment.append(empty);
        }

        list.replaceChildren(fragment);

        const current = needle ? 0 : matches.findIndex((option) => option.value === select.value);
        highlight(matches.length ? Math.max(current, 0) : -1);
    };

    const open = (query) => {
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        render(query);
    };

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        input.value = selectedLabel();
    };

    const commit = (option) => {
        select.value = option.value;
        subtitle.textContent = option.subtitle;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    };

    input.addEventListener('focus', () => input.select());
    input.addEventListener('click', () => {
        if (!isOpen()) {
            open('');
        }
    });
    input.addEventListener('input', () => open(input.value));
    input.addEventListener('blur', close);

    input.addEventListener('keydown', (event) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                isOpen() ? highlight(Math.min(active + 1, matches.length - 1)) : open('');
                break;
            case 'ArrowUp':
                event.preventDefault();
                isOpen() ? highlight(Math.max(active - 1, 0)) : open('');
                break;
            case 'Home':
            case 'End':
                if (isOpen() && matches.length) {
                    event.preventDefault();
                    highlight(event.key === 'Home' ? 0 : matches.length - 1);
                }
                break;
            case 'Enter':
                // Closed, Enter submits the form as usual; open, it picks the highlighted zone.
                if (isOpen()) {
                    event.preventDefault();

                    if (matches[active]) {
                        commit(matches[active]);
                    }
                }
                break;
            case 'Escape':
                if (isOpen()) {
                    event.preventDefault();
                    close();
                    input.select();
                }
                break;
        }
    });

    // Keep focus in the input while choosing with the pointer.
    list.addEventListener('mousedown', (event) => event.preventDefault());
    list.addEventListener('click', (event) => {
        const item = event.target.closest('[role="option"]');

        if (item) {
            commit(matches[Number(item.dataset.index)]);
        }
    });

    // The whole face (globe, chevron) opens the list.
    picker.addEventListener('mousedown', (event) => {
        if (event.target === picker || !list.contains(event.target)) {
            if (event.target !== input) {
                event.preventDefault();
                input.focus();
                open('');
            }
        }
    });

    if (hadFocus) {
        input.focus();
    }
}

document.querySelectorAll('[data-timezone-picker]').forEach(enhanceTimezonePicker);

/*
 * Destination type switch (#202). The create form renders every offered type's
 * fields in its own disabled fieldset; picking a type enables just that group,
 * so its fields show (and post) at once. Without JS the "Show settings" GET
 * reload keeps working.
 */
document.querySelectorAll('[data-destination-type-switch]').forEach((root) => {
    const select = root.querySelector('[data-destination-type-select]');
    const input = root.querySelector('[data-destination-type-input]');
    const groups = root.querySelectorAll('[data-destination-type-fields]');

    if (!select || !input || groups.length === 0) {
        return;
    }

    root.querySelector('[data-destination-type-reload]')?.setAttribute('hidden', '');

    select.addEventListener('change', () => {
        groups.forEach((group) => {
            const active = group.dataset.destinationTypeFields === select.value;
            group.hidden = !active;
            group.disabled = !active;
        });
        input.value = select.value;

        // Keep a reload or a shared link on the same type.
        const url = new URL(window.location.href);
        url.searchParams.set('type', select.value);
        window.history.replaceState(null, '', url);
    });
});

/*
 * Selects marked data-auto-submit (e.g. the source type) submit their GET form
 * on change. Replaces an inline onchange handler, which the CSP blocks.
 */
document.addEventListener('change', (event) => {
    const target = event.target;

    if (target instanceof HTMLSelectElement && target.matches('[data-auto-submit]')) {
        target.form?.requestSubmit();
    }
});

/*
 * Source type tiles (#206). Every offered type's fields render in their own
 * disabled fieldset; picking a tile enables just that group, so its fields show
 * (and post) at once. Without JS a <noscript> "Show settings" GET reload is offered.
 */
document.querySelectorAll('[data-source-form]').forEach((form) => {
    const options = form.querySelectorAll('[data-source-type-option]');
    const groups = form.querySelectorAll('[data-source-type-fields]');
    const heading = form.querySelector('[data-source-settings-heading]');
    const icon = form.querySelector('[data-source-settings-icon]');
    const test = form.querySelector('[data-source-test]');

    if (options.length === 0 || groups.length === 0) {
        return;
    }

    options.forEach((option) => {
        option.addEventListener('change', () => {
            if (!option.checked) {
                return;
            }

            groups.forEach((group) => {
                const active = group.dataset.sourceTypeFields === option.value;
                group.hidden = !active;
                group.disabled = !active;

                if (active) {
                    if (heading) heading.textContent = group.dataset.sourceTypeHeading ?? '';
                    if (icon) icon.textContent = group.dataset.sourceTypeIcon ?? '';
                    if (test) test.hidden = group.dataset.sourceTypePreview !== 'yes';
                }
            });

            // Keep a reload or a shared link on the same type.
            const url = new URL(window.location.href);
            url.searchParams.set('type', option.value);
            window.history.replaceState(null, '', url);
        });
    });
});

/*
 * Forms marked data-confirm ask before submitting. Replaces inline onsubmit
 * handlers, which the CSP blocks.
 */
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});
