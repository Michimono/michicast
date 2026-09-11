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
