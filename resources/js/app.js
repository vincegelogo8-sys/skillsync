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

// Keep native validation, confirmation prompts, CSRF fields and form submissions.
// A cancelled confirmation never enters the busy state.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post' || event.defaultPrevented) return;
    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
    const button = event.submitter;
    if (button) {
        button.dataset.originalText = button.textContent;
        button.textContent = form.enctype === 'multipart/form-data' ? 'Uploading…' : 'Please wait…';
    }
    // Wait until the browser has constructed form data, including named submitters.
    setTimeout(() => {
        [...form.elements].filter((el) => el instanceof HTMLButtonElement && el.type === 'submit' && !el.disabled).forEach((el) => {
            el.dataset.busyDisabled = 'true';
            el.disabled = true;
        });
    }, 0);
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitting]').forEach((form) => {
        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
    });
    document.querySelectorAll('[data-original-text]').forEach((button) => {
        button.textContent = button.dataset.originalText;
        delete button.dataset.originalText;
    });
    document.querySelectorAll('[data-busy-disabled]').forEach((button) => {
        button.disabled = false;
        delete button.dataset.busyDisabled;
    });
});

// Count selected answers only; no answer keys or score calculations are exposed.
document.querySelectorAll('[data-assessment-form]').forEach((form) => {
    const progress = form.querySelector('[data-assessment-progress]');
    const count = form.querySelector('[data-answered-count]');
    const update = () => {
        const answered = new Set([...form.querySelectorAll('input[type="radio"]:checked')].map((input) => input.name)).size;
        progress.value = answered;
        count.textContent = answered;
    };
    form.addEventListener('change', update);
    update();
});
