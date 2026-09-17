/*
 * Reveals the organization rail on small screens. The rail is one element at
 * every width, so this only toggles the class that hides it below `lg`.
 */
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-sidebar-toggle]');

    if (toggle === null) {
        return;
    }

    const sidebar = document.getElementById(toggle.getAttribute('aria-controls'));

    if (sidebar === null) {
        return;
    }

    const hidden = sidebar.classList.toggle('max-lg:hidden');

    toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
});

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
