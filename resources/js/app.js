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

const accountCheckboxes = [...document.querySelectorAll('[data-account-checkbox]')];
const selectPageCheckbox = document.querySelector('[data-select-page]');
const bulkActionBar = document.querySelector('[data-bulk-action-bar]');
const selectAllMatchingLink = document.querySelector('[data-select-all-matching]');
const clearMatchingButton = document.querySelector('[data-clear-matching]');
const bulkActionValue = document.querySelector('[data-bulk-action-value]');
const selectAllMatchingInput = document.querySelector('[name="select_all_matching"]');
const selectionSummary = document.querySelector('[data-selection-summary]');
let selectingAllMatches = false;

function updateAccountSelection() {
    if (!bulkActionBar || !selectAllMatchingLink) return;
    const checkedCount = accountCheckboxes.filter((checkbox) => checkbox.checked).length;
    const allOnPage = accountCheckboxes.length > 0 && checkedCount === accountCheckboxes.length;
    const total = Number(selectAllMatchingLink.textContent.match(/\d+/)?.[0] ?? 0);

    bulkActionBar.hidden = checkedCount === 0 && !selectingAllMatches;
    selectAllMatchingLink.hidden = !allOnPage || total <= accountCheckboxes.length || selectingAllMatches;
    clearMatchingButton.hidden = !selectingAllMatches;
    if (selectionSummary) {
        selectionSummary.textContent = selectingAllMatches
            ? `${total} students matching this filter are selected.`
            : `${checkedCount} students selected on this page.`;
    }
}

selectPageCheckbox?.addEventListener('change', () => {
    accountCheckboxes.forEach((checkbox) => {
        checkbox.checked = selectPageCheckbox.checked;
    });
    updateAccountSelection();
});

accountCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', () => {
    if (selectPageCheckbox) {
        selectPageCheckbox.checked = accountCheckboxes.length > 0 && accountCheckboxes.every((item) => item.checked);
    }
    updateAccountSelection();
}));

selectAllMatchingLink?.addEventListener('click', (event) => {
    event.preventDefault();
    selectingAllMatches = true;
    if (selectAllMatchingInput) selectAllMatchingInput.value = '1';
    accountCheckboxes.forEach((checkbox) => {
        checkbox.checked = false;
        checkbox.disabled = true;
    });
    updateAccountSelection();
});

clearMatchingButton?.addEventListener('click', () => {
    selectingAllMatches = false;
    if (selectAllMatchingInput) selectAllMatchingInput.value = '';
    accountCheckboxes.forEach((checkbox) => {
        checkbox.disabled = false;
        checkbox.checked = false;
    });
    if (selectPageCheckbox) selectPageCheckbox.checked = false;
    updateAccountSelection();
});

document.querySelectorAll('[data-bulk-status-action]').forEach((button) => {
    button.addEventListener('click', () => {
        const action = button.dataset.bulkStatusAction;
        const count = selectingAllMatches
            ? Number(selectAllMatchingLink?.textContent.match(/\d+/)?.[0] ?? 0)
            : accountCheckboxes.filter((checkbox) => checkbox.checked).length;
        const verb = action === 'deactivate' ? 'deactivate' : 'activate';
        const title = document.querySelector('[data-bulk-confirm-title]');
        const message = document.querySelector('[data-bulk-confirm-message]');

        if (bulkActionValue) bulkActionValue.value = action;
        if (title) title.textContent = `${action === 'deactivate' ? 'Deactivate' : 'Activate'} ${count} students?`;
        if (message) {
            message.textContent = action === 'deactivate'
                ? `${count} students will be deactivated and logged out immediately. Their data will be kept and they can be reactivated later.`
                : `${count} students will be activated and able to sign in again. Their existing data will be kept.`;
        }
        button.dataset.bulkActionVerb = verb;
    });
});
updateAccountSelection();

['contextmenu', 'copy', 'cut', 'dragstart'].forEach((eventName) => {
    document.addEventListener(eventName, (event) => {
        if (event.target instanceof Element && event.target.closest('[data-no-copy]')) {
            event.preventDefault();
        }
    }, true);
});

const adviserGroups = [...document.querySelectorAll('details.adviser-group')];
const expandAdvisersButton = document.querySelector('[data-accordion-expand-all]');
const collapseAdvisersButton = document.querySelector('[data-accordion-collapse-all]');

if (adviserGroups.length) {
    if (expandAdvisersButton) expandAdvisersButton.hidden = false;
    if (collapseAdvisersButton) collapseAdvisersButton.hidden = false;
}

expandAdvisersButton?.addEventListener('click', () => {
    adviserGroups.forEach((group) => { group.open = true; });
});

collapseAdvisersButton?.addEventListener('click', () => {
    adviserGroups.forEach((group) => { group.open = false; });
});

// Presentation only: links still navigate through the existing Laravel routes.
const sidebar = document.getElementById('workspace-sidebar');
const menuButton = document.querySelector('[data-sidebar-toggle]');
const overlay = document.querySelector('[data-sidebar-overlay]');
const appMain = document.querySelector('.app-main');
const mobileViewport = window.matchMedia('(max-width: 900px)');
const sidebarToggleLabel = menuButton?.querySelector('[data-sidebar-toggle-label]');
const sidebarPreferenceKey = 'skillsync.sidebar';

function readSidebarPreference() {
    try {
        return window.localStorage.getItem(sidebarPreferenceKey) === 'collapsed' ? 'collapsed' : 'expanded';
    } catch {
        return 'expanded';
    }
}

function writeSidebarPreference(preference) {
    try {
        window.localStorage.setItem(sidebarPreferenceKey, preference);
    } catch {
        // The visible state still works when storage is unavailable.
    }
}

function updateDesktopSidebarToggle() {
    const collapsed = document.documentElement.classList.contains('sidebar-collapsed');
    menuButton.setAttribute('aria-expanded', String(!collapsed));
    if (sidebarToggleLabel) sidebarToggleLabel.textContent = collapsed ? 'Expand menu' : 'Collapse menu';
    updateSidebarTitles(collapsed);
}

function updateSidebarTitles(collapsed) {
    if (!sidebar) return;

    sidebar.querySelectorAll('.nav-item').forEach((item) => {
        const label = item.querySelector('.nav-label')?.textContent.trim();
        if (collapsed && label) {
            item.title = label;
        } else {
            item.removeAttribute('title');
        }
    });
}

function syncSidebarState() {
    if (!sidebar || !menuButton) return;

    if (mobileViewport.matches) {
        menuButton.setAttribute('aria-expanded', String(sidebar.classList.contains('is-open')));
        if (sidebarToggleLabel) sidebarToggleLabel.textContent = 'Menu';
        updateSidebarTitles(false);
        return;
    }

    const collapsed = readSidebarPreference() === 'collapsed';
    document.documentElement.classList.toggle('sidebar-collapsed', collapsed);
    updateDesktopSidebarToggle();
}

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
    if (mobileViewport.matches) menuButton.setAttribute('aria-expanded', 'false');
    overlay.hidden = true;
    appMain.inert = false;
    if (wasOpen && restoreFocus) menuButton.focus();
    if (mobileViewport.matches) syncSidebarState();
}

menuButton?.addEventListener('click', () => {
    if (!mobileViewport.matches) {
        const collapsed = !document.documentElement.classList.contains('sidebar-collapsed');
        document.documentElement.classList.toggle('sidebar-collapsed', collapsed);
        writeSidebarPreference(collapsed ? 'collapsed' : 'expanded');
        updateDesktopSidebarToggle();
        menuButton.focus();
        return;
    }

    if (sidebar.classList.contains('is-open')) {
        closeMobileSidebar();
        return;
    }
    closeDropdowns();
    sidebar.classList.add('is-open');
    document.body.classList.add('sidebar-open');
    menuButton.setAttribute('aria-expanded', 'true');
    if (sidebarToggleLabel) sidebarToggleLabel.textContent = 'Menu';
    overlay.hidden = false;
    appMain.inert = true;
    sidebar.querySelector('[data-sidebar-close]').focus();
});

overlay?.addEventListener('click', () => closeMobileSidebar());
document.querySelector('[data-sidebar-close]')?.addEventListener('click', () => closeMobileSidebar());
mobileViewport.addEventListener('change', () => {
    closeMobileSidebar(false);
    syncSidebarState();
});
window.addEventListener('pageshow', () => {
    closeMobileSidebar(false);
    syncSidebarState();
});
syncSidebarState();

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        if (mobileViewport.matches) closeMobileSidebar();
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
        if (mobileViewport.matches) closeMobileSidebar();
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

const accountRoleSelect = document.querySelector('[data-account-role-select]');
accountRoleSelect?.addEventListener('change', () => {
    document.querySelectorAll('[data-account-role-group]').forEach((group) => {
        const visible = group.dataset.accountRoleGroup === accountRoleSelect.value;
        group.hidden = !visible;
        group.querySelectorAll('[data-role-required]').forEach((input) => {
            input.required = visible;
        });
    });
});
accountRoleSelect?.dispatchEvent(new Event('change'));

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
