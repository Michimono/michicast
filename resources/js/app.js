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
 * Source create: enable Test once a feed URL is present.
 */
(function () {
    const root = document.querySelector('[data-source-create]');

    if (root === null) {
        return;
    }

    const form = root.querySelector('[data-source-create-form]');
    const testButton = root.querySelector('[data-source-test]');
    const feedInput = form?.querySelector('[name="config[feed_url]"]');

    if (form === null || testButton === null || feedInput === null) {
        return;
    }

    function syncTestButton() {
        testButton.disabled = feedInput.value.trim() === '';
    }

    form.addEventListener('input', syncTestButton);
    form.addEventListener('change', syncTestButton);
    syncTestButton();
})();
/*
 * Schedule fields: show only the inputs the selected frequency needs.
 */
(function () {
    function syncScheduleFields(root) {
        const frequency = root.querySelector('[data-schedule-frequency]')?.value ?? '';

        root.querySelectorAll('[data-schedule-panel]').forEach((panel) => {
            const modes = (panel.getAttribute('data-schedule-panel') ?? '').split(/\s+/).filter(Boolean);
            panel.hidden = !modes.includes(frequency);
        });
    }

    document.addEventListener('change', (event) => {
        const select = event.target.closest('[data-schedule-frequency]');

        if (select === null) {
            return;
        }

        const root = select.closest('[data-schedule-fields]');

        if (root !== null) {
            syncScheduleFields(root);
        }
    });

    document.querySelectorAll('[data-schedule-fields]').forEach(syncScheduleFields);
})();

import './mapping-editor';

/*
 * Timezone picker (first organization): a native <select> sits invisibly over a
 * styled face. Keep the face's name and subtitle in step with the selection.
 */
document.querySelectorAll('[data-timezone-picker]').forEach((picker) => {
    const select = picker.querySelector('select');
    const name = picker.querySelector('[data-timezone-name]');
    const subtitle = picker.querySelector('[data-timezone-subtitle]');

    if (!select || !name || !subtitle) {
        return;
    }

    const sync = () => {
        const option = select.selectedOptions[0];
        name.textContent = option?.value ?? '';
        subtitle.textContent = option?.dataset.subtitle ?? '';
    };

    select.addEventListener('change', sync);
    sync();
});
