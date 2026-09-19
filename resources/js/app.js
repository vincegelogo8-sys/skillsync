// Native dialogs handle keyboard focus, Escape, and focus restoration.
document.querySelectorAll('[data-open-modal]').forEach((button) => {
    button.addEventListener('click', () => {
        document.getElementById(button.dataset.openModal)?.showModal();
    });
});

document.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
});

document.querySelectorAll('dialog[data-show="true"]').forEach((dialog) => {
    dialog.showModal();
});

// Presentation only: links still navigate through the existing Laravel routes.
const sidebar = document.getElementById('workspace-sidebar');
const menuButton = document.querySelector('[data-sidebar-toggle]');
const overlay = document.querySelector('[data-sidebar-overlay]');
const appMain = document.querySelector('.app-main');
const mobileViewport = window.matchMedia('(max-width: 900px)');

function closeDropdowns(except = null) {
    document.querySelectorAll('[data-dropdown][open]').forEach((dropdown) => {
        if (dropdown !== except) dropdown.open = false;
    });
}

function closeMobileSidebar(restoreFocus = true) {
    if (!sidebar || !menuButton) return;
    const wasOpen = sidebar.classList.contains('is-open');
    sidebar.classList.remove('is-open');
    document.body.classList.remove('sidebar-open');
    menuButton.setAttribute('aria-expanded', 'false');
    overlay.hidden = true;
    appMain.inert = false;
    if (wasOpen && restoreFocus) menuButton.focus();
}

menuButton?.addEventListener('click', () => {
    if (sidebar.classList.contains('is-open')) {
        closeMobileSidebar();
        return;
    }
    closeDropdowns();
    sidebar.classList.add('is-open');
    document.body.classList.add('sidebar-open');
    menuButton.setAttribute('aria-expanded', 'true');
    overlay.hidden = false;
    appMain.inert = true;
    sidebar.querySelector('[data-sidebar-close]').focus();
});

overlay?.addEventListener('click', () => closeMobileSidebar());
document.querySelector('[data-sidebar-close]')?.addEventListener('click', () => closeMobileSidebar());
mobileViewport.addEventListener('change', () => closeMobileSidebar(false));
window.addEventListener('pageshow', () => closeMobileSidebar(false));

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeMobileSidebar();
        const dropdown = document.querySelector('[data-dropdown][open]');
        dropdown?.querySelector('summary')?.focus();
        closeDropdowns();
    }
    if (event.key === 'Tab' && sidebar?.classList.contains('is-open')) {
        const controls = [...sidebar.querySelectorAll('a[href], button:not([disabled])')];
        const first = controls[0];
        const last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
});

document.addEventListener('click', (event) => {
    closeDropdowns(event.target.closest('[data-dropdown]'));
    if (event.target.closest('.sidebar a, [data-dropdown] a')) {
        closeDropdowns();
        closeMobileSidebar();
    }
});
document.addEventListener('focusin', (event) => {
    closeDropdowns(event.target.closest('[data-dropdown]'));
});

document.querySelectorAll('[data-password-toggle]').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
        const input = document.getElementById(checkbox.dataset.passwordToggle);
        if (input) input.type = checkbox.checked ? 'text' : 'password';
    });
});

document.querySelectorAll('[data-close-breakdown]').forEach((button) => {
    button.addEventListener('click', () => {
        const details = button.closest('details');
        details.open = false;
        details.querySelector('summary').focus();
    });
});

// Make existing scrollable tables keyboard-accessible without altering tables.
document.querySelectorAll('.overflow-x-auto').forEach((region) => {
    if (!region.querySelector('table')) return;
    region.tabIndex = 0;
    region.setAttribute('role', 'region');
    region.setAttribute('aria-label', 'Scrollable table');
});

// Keep the public page's text navigation in sync with its selected anchor.
document.querySelectorAll('.landing-nav a[href^="#"]').forEach((link) => {
    link.addEventListener('click', () => {
        document.querySelectorAll('.landing-nav [aria-current]').forEach((item) => item.removeAttribute('aria-current'));
        link.setAttribute('aria-current', 'location');
    });
});
