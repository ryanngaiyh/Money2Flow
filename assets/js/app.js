document.querySelectorAll('[data-confirm]').forEach(el => el.addEventListener('click', e => { if (!confirm(el.dataset.confirm)) e.preventDefault(); }));

const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('appSidebar');
const sidebarClose = document.getElementById('sidebarClose');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const mobileSidebar = window.matchMedia('(max-width: 768px)');

function sidebarIsOpen() {
    return mobileSidebar.matches ? document.body.classList.contains('sidebar-open') : !document.body.classList.contains('sidebar-collapsed');
}

function setSidebarOpen(open, moveFocus = false) {
    document.body.classList.toggle('sidebar-open', mobileSidebar.matches && open);
    document.body.classList.toggle('sidebar-collapsed', !mobileSidebar.matches && !open);
    if (sidebar) sidebar.inert = !open;
    sidebarToggle?.setAttribute('aria-expanded', String(open));
    sidebarToggle?.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    if (moveFocus) {
        if (open) (sidebar?.querySelector('[aria-current="page"]') || sidebar?.querySelector('a'))?.focus();
        else sidebarToggle?.focus();
    }
}

setSidebarOpen(!mobileSidebar.matches);
sidebarToggle?.addEventListener('click', () => setSidebarOpen(!sidebarIsOpen(), true));
sidebarClose?.addEventListener('click', () => setSidebarOpen(false, true));
sidebarOverlay?.addEventListener('click', () => setSidebarOpen(false, true));
mobileSidebar.addEventListener('change', () => setSidebarOpen(!mobileSidebar.matches));

document.addEventListener('keydown', event => {
    if (!mobileSidebar.matches || !sidebarIsOpen()) return;
    if (event.key === 'Escape') {
        setSidebarOpen(false, true);
        return;
    }
    if (event.key !== 'Tab' || !sidebar) return;
    const focusable = [...sidebar.querySelectorAll('a, button')].filter(element => !element.hidden);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
});

if (window.location.hash) {
    const target = document.querySelector(window.location.hash);
    if (target?.classList.contains('collapse') && window.bootstrap) {
        bootstrap.Collapse.getOrCreateInstance(target, { toggle: false }).show();
    }
}
