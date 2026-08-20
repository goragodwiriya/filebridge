// Bootstrap: authentication, sidebar, the two panels, the rail and shortcuts.

import { call, state, site as findSite } from './api.js';
import { el, icon, $, $$, toast, notifyError, contextMenu, modalsOpen, closeMenu } from './ui.js';
import { siteDialog, confirm, helpDialog, prompt } from './dialogs.js';
import { Panel } from './panel.js';
import { Queue } from './transfer.js';

const app = {
    panels: {},
    focused: null,
    queue: null,
    mobileSide: 'left',
};

// ── boot ─────────────────────────────────────────────────────────────────────

async function boot() {
    let info;
    try {
        info = await call('app.state');
    } catch (error) {
        document.body.append(el('div', { class: 'auth' },
            el('div', { class: 'auth-card' },
                el('div', { class: 'auth-error' }, icon('alert', 'icon icon-sm'), el('span', { text: error.message })))));
        return;
    }

    state.csrf = info.csrf;
    state.settings = info.settings;
    state.backends = info.backends;

    if (!info.signedIn) {
        showAuth(info);
        return;
    }

    state.user = info.user;
    state.sites = info.sites || [];
    startApp();
}

// ── sign in / first run ──────────────────────────────────────────────────────

function showAuth(info) {
    const screen = $('#auth');
    const form = $('#auth-form');
    const error = $('#auth-error');
    const setup = info.needsSetup;

    screen.hidden = false;
    $('#app').hidden = true;
    $('#auth-lead').textContent = setup
        ? 'Create the administrator account to get started.'
        : 'Sign in to manage your connections.';
    $('#auth-submit').textContent = setup ? 'Create account' : 'Sign in';
    $('#auth-confirm-field').hidden = !setup;
    $('#auth-pass').autocomplete = setup ? 'new-password' : 'current-password';

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        error.hidden = true;
        const username = $('#auth-user').value.trim();
        const password = $('#auth-pass').value;

        if (setup && password !== $('#auth-confirm').value) {
            error.hidden = false;
            error.querySelector('span').textContent = 'The two passwords do not match.';
            return;
        }

        const button = $('#auth-submit');
        button.disabled = true;
        try {
            const data = await call(setup ? 'auth.setup' : 'auth.login', { username, password });
            state.csrf = data.csrf;
            state.user = data.user;
            state.sites = data.sites || (await call('site.list'));
            screen.hidden = true;
            startApp();
        } catch (failure) {
            error.hidden = false;
            error.querySelector('span').textContent = failure.message;
            button.disabled = false;
            $('#auth-pass').select();
        }
    });
}

// ── application ──────────────────────────────────────────────────────────────

function startApp() {
    $('#app').hidden = false;
    $('#user-name').textContent = state.user;
    $('#user-initial').textContent = state.user.slice(0, 1).toUpperCase();

    renderBackendInfo();
    renderSites();

    const ctx = {
        onFocus: setFocused,
        onSelection: syncRail,
        onConnected: () => { renderSites(); syncRail(); },
        getOther: (panel) => (panel === app.panels.left ? app.panels.right : app.panels.left),
        copyToOther: (panel, mode) => transferBetween(panel, mode),
        transfer: (payload) => startTransfer(payload),
    };

    app.panels.left = new Panel('left', ctx);
    app.panels.right = new Panel('right', ctx);

    const rail = $('#rail');
    $('#panels').insertBefore(app.panels.left.root, rail);
    $('#panels').append(app.panels.right.root);

    app.queue = new Queue(onJobFinished);
    app.queue.poll();

    wireChrome();
    wireRail();
    wireKeyboard();

    const saved = readLayout();
    app.panels.left.fillSites();
    app.panels.right.fillSites();
    app.panels.left.connect(saved.left.site, saved.left.path);
    app.panels.right.connect(saved.right.site, saved.right.path);
    setFocused(app.panels.left);
}

function readLayout() {
    try {
        const raw = JSON.parse(localStorage.getItem('fb.layout') || '{}');
        const valid = (id) => (findSite(id) ? id : 'local');
        return {
            left: { site: valid(raw.left?.site), path: raw.left?.path || '' },
            right: { site: valid(raw.right?.site), path: raw.right?.path || '' },
        };
    } catch {
        return { left: { site: 'local', path: '' }, right: { site: 'local', path: '' } };
    }
}

function saveLayout() {
    const snapshot = (panel) => ({ site: panel.siteId, path: panel.path });
    try {
        localStorage.setItem('fb.layout', JSON.stringify({
            left: snapshot(app.panels.left),
            right: snapshot(app.panels.right),
        }));
    } catch { /* private mode - not important */ }
}

function setFocused(panel) {
    if (app.focused === panel) return;
    app.focused = panel;
    Object.values(app.panels).forEach((item) => item.root.classList.toggle('is-focused', item === panel));
    syncRail();
}

function other(panel) {
    return panel === app.panels.left ? app.panels.right : app.panels.left;
}

// ── sidebar ──────────────────────────────────────────────────────────────────

function renderBackendInfo() {
    const rows = [
        ['SFTP', state.backends.ssh2 ? 'ext-ssh2 (fast)' : (state.backends.phpseclib ? 'phpseclib' : 'unavailable'), state.backends.ssh2 || state.backends.phpseclib],
        ['FTP / FTPS', state.backends.ftp ? 'ext-ftp' : 'unavailable', state.backends.ftp],
    ];
    $('#backend-info').replaceChildren(...rows.map(([label, value, ok]) =>
        el('div', { class: 'row' },
            el('span', { class: `dot ${ok ? 'dot-ok' : 'dot-danger'}` }),
            el('span', { text: `${label}: ${value}` }))));
}

function renderSites() {
    const list = $('#site-list');
    list.replaceChildren(...state.sites.map((entry) => {
        const mounted = Object.entries(app.panels)
            .filter(([, panel]) => panel.siteId === entry.id)
            .map(([side]) => side[0].toUpperCase());

        const node = el('li', {
            class: `site${mounted.length ? ' is-active' : ''}`,
            style: { '--site-colour': entry.colour },
            title: entry.label,
            onclick: () => openSite(entry.id),
            oncontextmenu: (event) => {
                event.preventDefault();
                siteMenu(event, entry);
            },
        },
        el('span', { class: 'swatch' }, icon(entry.protocol === 'local' ? 'hard-drive' : 'server', 'icon icon-sm')),
        el('span', { class: 'meta' },
            el('span', { class: 'name', text: entry.name }),
            el('span', { class: 'where', text: entry.label })),
        mounted.length ? el('span', { class: 'mount', text: mounted.join(' ') }) : null,
        el('span', { class: 'side' },
            entry.id === 'local' ? null : el('button', {
                class: 'icon-btn', title: 'Edit',
                onclick: (event) => { event.stopPropagation(); editSite(entry); },
            }, icon('settings', 'icon icon-sm'))));

        return node;
    }));
}

function openSite(id) {
    const panel = app.focused || app.panels.left;
    panel.connect(id).then(saveLayout);
}

function siteMenu(event, entry) {
    contextMenu(event.clientX, event.clientY, [
        { label: entry.name, header: true },
        { label: 'Open in the left panel', icon: 'arrow-left', onSelect: () => app.panels.left.connect(entry.id).then(saveLayout) },
        { label: 'Open in the right panel', icon: 'arrow-right', onSelect: () => app.panels.right.connect(entry.id).then(saveLayout) },
        'sep',
        { label: 'Edit', icon: 'settings', disabled: entry.id === 'local', onSelect: () => editSite(entry) },
        { label: 'Duplicate', icon: 'copy', disabled: entry.id === 'local', onSelect: () => duplicateSite(entry) },
        { label: 'Delete', icon: 'trash', danger: true, disabled: entry.id === 'local', onSelect: () => deleteSite(entry) },
    ]);
}

async function editSite(entry) {
    const saved = await siteDialog(entry);
    if (saved) {
        renderSites();
        Object.values(app.panels).forEach((panel) => panel.fillSites());
    }
}

async function duplicateSite(entry) {
    const copy = { ...entry, id: '', name: `${entry.name} (copy)` };
    const saved = await siteDialog(copy);
    if (saved) {
        renderSites();
        Object.values(app.panels).forEach((panel) => panel.fillSites());
    }
}

async function deleteSite(entry) {
    const ok = await confirm('Remove connection?', `“${entry.name}” will be deleted from this tool. The server itself is untouched.`, {
        danger: true, confirmLabel: 'Remove',
    });
    if (!ok) return;
    try {
        const data = await call('site.delete', { id: entry.id });
        state.sites = data.sites;
        renderSites();
        Object.values(app.panels).forEach((panel) => {
            panel.fillSites();
            if (panel.siteId === entry.id) panel.connect('local');
        });
        toast('Connection removed', entry.name, 'ok');
    } catch (error) { notifyError(error); }
}

// ── transfers ────────────────────────────────────────────────────────────────

function transferBetween(sourcePanel, mode) {
    const targetPanel = other(sourcePanel);
    const paths = sourcePanel.selectedPaths();
    if (paths.length === 0) {
        toast('Nothing selected', 'Pick files or folders first.', 'warn', 2600);
        return;
    }
    startTransfer({
        sourceSite: sourcePanel.siteId,
        sourceBase: sourcePanel.path,
        paths,
        targetSite: targetPanel.siteId,
        targetPath: targetPanel.path,
        mode,
    });
}

function startTransfer(payload) {
    const from = findSite(payload.sourceSite)?.name || payload.sourceSite;
    const to = findSite(payload.targetSite)?.name || payload.targetSite;
    app.queue.start(payload, { from, to });
}

function onJobFinished(job) {
    const kind = job.status === 'done' ? 'ok' : job.status === 'error' ? 'error' : 'warn';
    toast(`Transfer ${job.status}`, `${job.title} · ${job.filesDone} file(s)`, kind, job.status === 'error' ? 9000 : 4000);
    Object.values(app.panels).forEach((panel) => {
        if (panel.siteId === job.targetSite || panel.siteId === job.sourceSite) {
            panel.load(panel.path, { keepSelection: true });
        }
    });
}

// ── rail ─────────────────────────────────────────────────────────────────────

function wireRail() {
    $('#rail').addEventListener('click', (event) => {
        const button = event.target.closest('[data-act]');
        if (!button) return;
        const act = button.dataset.act;
        if (act === 'to-right') transferBetween(app.panels.left, 'copy');
        if (act === 'to-left') transferBetween(app.panels.right, 'copy');
        if (act === 'move') transferBetween(app.focused || app.panels.left, 'move');
        if (act === 'sync-path') {
            const source = app.focused || app.panels.left;
            other(source).load(source.path).then(saveLayout);
        }
    });
}

function syncRail() {
    const left = app.panels.left?.selected.size || 0;
    const right = app.panels.right?.selected.size || 0;
    const focused = app.focused?.selected.size || 0;
    $('#rail [data-act="to-right"]').disabled = left === 0;
    $('#rail [data-act="to-left"]').disabled = right === 0;
    $('#rail [data-act="move"]').disabled = focused === 0;
    saveLayout();
}

// ── chrome ───────────────────────────────────────────────────────────────────

function wireChrome() {
    const themeButton = $('#btn-theme');
    const applyTheme = (theme) => {
        document.documentElement.dataset.theme = theme;
        $('#theme-icon use').setAttribute('href', theme === 'dark' ? '#i-moon' : '#i-sun');
        try { localStorage.setItem('fb.theme', theme); } catch { /* ignore */ }
    };
    applyTheme(document.documentElement.dataset.theme || state.settings.theme || 'dark');
    themeButton.addEventListener('click', () => {
        applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
    });

    $('#btn-add-site').addEventListener('click', async () => {
        const saved = await siteDialog(null);
        if (saved) {
            renderSites();
            Object.values(app.panels).forEach((panel) => panel.fillSites());
            (app.focused || app.panels.left).connect(saved.id);
        }
    });

    $('#btn-help').addEventListener('click', () => helpDialog());

    $('#btn-user').addEventListener('click', (event) => {
        const box = event.currentTarget.getBoundingClientRect();
        contextMenu(box.right - 214, box.bottom + 6, [
            { label: state.user, header: true },
            { label: 'Change password', icon: 'key', onSelect: changePassword },
            { label: 'Keyboard shortcuts', icon: 'info', onSelect: () => helpDialog() },
            'sep',
            { label: 'Sign out', icon: 'logout', danger: true, onSelect: signOut },
        ]);
    });

    const sidebarButton = $('#btn-sidebar');
    const syncMobile = () => {
        const narrow = window.innerWidth <= 860;
        sidebarButton.hidden = !narrow;
        $('#mobile-tabs').style.display = narrow ? 'flex' : 'none';
        if (narrow) applyMobileSide(app.mobileSide);
        else Object.values(app.panels).forEach((panel) => panel.root.classList.remove('is-hidden-mobile'));
    };
    sidebarButton.addEventListener('click', () => $('#sidebar').classList.toggle('is-open'));
    $('#mobile-tabs').addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (button) applyMobileSide(button.dataset.side);
    });
    window.addEventListener('resize', syncMobile);
    syncMobile();

    window.addEventListener('beforeunload', saveLayout);
}

function applyMobileSide(side) {
    app.mobileSide = side;
    $$('#mobile-tabs button').forEach((button) => button.setAttribute('aria-selected', String(button.dataset.side === side)));
    Object.entries(app.panels).forEach(([name, panel]) => panel.root.classList.toggle('is-hidden-mobile', name !== side));
    setFocused(app.panels[side]);
}

async function changePassword() {
    const current = await prompt('Change password', 'Current password', '', { glyph: 'key', confirmLabel: 'Next' });
    if (!current) return;
    const next = await prompt('Change password', 'New password (8+ characters)', '', { glyph: 'key', confirmLabel: 'Update' });
    if (!next) return;
    try {
        await call('auth.password', { current, new: next });
        toast('Password updated', 'Use it the next time you sign in.', 'ok');
    } catch (error) { notifyError(error); }
}

async function signOut() {
    try { await call('auth.logout'); } catch { /* leaving anyway */ }
    window.location.reload();
}

// ── keyboard ─────────────────────────────────────────────────────────────────

function wireKeyboard() {
    document.addEventListener('keydown', (event) => {
        const target = event.target;
        const typing = target.matches?.('input, textarea, select');
        const panel = app.focused;
        if (!panel) return;

        if (event.key === 'Escape') { closeMenu(); return; }
        if (modalsOpen()) return;

        if (typing) {
            if (event.key === 'Escape') target.blur();
            return;
        }

        const ctrl = event.ctrlKey || event.metaKey;
        const cursorEntry = panel.view[panel.cursor];

        switch (true) {
            case event.key === 'Tab':
                event.preventDefault();
                setFocused(other(panel));
                panelFocus(other(panel));
                break;
            case event.key === 'F5':
                event.preventDefault();
                transferBetween(panel, 'copy');
                break;
            case event.key === 'F6':
                event.preventDefault();
                transferBetween(panel, 'move');
                break;
            case event.key === 'F7':
                event.preventDefault();
                panel.mkdir();
                break;
            case event.key === 'F2':
                if (cursorEntry) { event.preventDefault(); panel.rename(cursorEntry); }
                break;
            case event.key === 'F3':
                if (cursorEntry) {
                    event.preventDefault();
                    // View stays view: a file the viewer cannot draw says so
                    // rather than starting a download and closing everything.
                    if (cursorEntry.isDir) panel.load(cursorEntry.path);
                    else if (cursorEntry.editable && !cursorEntry.image) panel.edit(cursorEntry);
                    else panel.preview(cursorEntry);
                }
                break;
            case event.key === 'F4':
                if (cursorEntry?.editable) { event.preventDefault(); panel.edit(cursorEntry); }
                break;
            case event.key === 'F8' || event.key === 'Delete':
                event.preventDefault();
                panel.remove();
                break;
            case event.key === 'Backspace':
                event.preventDefault();
                panel.action('up');
                break;
            case event.key === 'Enter':
                if (cursorEntry) { event.preventDefault(); panel.open(cursorEntry); }
                break;
            case event.key === 'ArrowDown':
                event.preventDefault();
                panel.moveCursor(panel.cursor < 0 ? 0 : 1);
                if (event.shiftKey) panel.setSelected(panel.cursor, true), panel.updateFoot();
                break;
            case event.key === 'ArrowUp':
                event.preventDefault();
                panel.moveCursor(-1);
                if (event.shiftKey) panel.setSelected(panel.cursor, true), panel.updateFoot();
                break;
            case event.key === ' ':
                if (cursorEntry) {
                    event.preventDefault();
                    panel.setSelected(panel.cursor, !panel.selected.has(cursorEntry.path));
                    panel.updateFoot();
                }
                break;
            case ctrl && event.key.toLowerCase() === 'a':
                event.preventDefault();
                panel.toggleAll();
                break;
            case ctrl && event.key.toLowerCase() === 'r':
                event.preventDefault();
                panel.action('refresh');
                break;
            case ctrl && event.key.toLowerCase() === 'f':
                event.preventDefault();
                panel.el.filter.focus();
                break;
            case ctrl && event.key.toLowerCase() === 'd':
                event.preventDefault();
                panel.download();
                break;
            case ctrl && event.key.toLowerCase() === 'h':
                event.preventDefault();
                panel.toggleHidden();
                break;
            case event.key === '?':
                event.preventDefault();
                helpDialog();
                break;
            default:
                break;
        }
    });
}

function panelFocus(panel) {
    panel.root.focus({ preventScroll: true });
}

boot();
