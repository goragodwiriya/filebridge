// DOM helpers, formatting, toasts, modals and the context menu.

import { t } from './i18n.js';

export function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') node.className = value;
        else if (key === 'html') node.innerHTML = value;
        else if (key === 'text') node.textContent = value;
        else if (key === 'dataset') Object.assign(node.dataset, value);
        else if (key === 'style') style(node, value);
        else if (key.startsWith('on')) node.addEventListener(key.slice(2).toLowerCase(), value);
        else node.setAttribute(key, value === true ? '' : String(value));
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        node.append(child.nodeType ? child : document.createTextNode(String(child)));
    }
    return node;
}

/**
 * Inline styles from a plain object. Custom properties have to go through
 * setProperty - assigning `style['--x']` is silently dropped by the browser,
 * which is how the per-connection colour used to disappear on its way to CSS.
 */
export function style(node, styles) {
    for (const [name, value] of Object.entries(styles)) {
        if (name.startsWith('--')) node.style.setProperty(name, value);
        else node.style[name] = value;
    }
    return node;
}

export function icon(name, cls = 'icon') {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', cls);
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `#i-${name}`);
    svg.append(use);
    return svg;
}

export const $ = (selector, scope = document) => scope.querySelector(selector);
export const $$ = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));

// ── formatting ───────────────────────────────────────────────────────────────

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

export function bytes(value, precision = 1) {
    if (!value || value < 0) return '0 B';
    const power = Math.min(Math.floor(Math.log(value) / Math.log(1024)), UNITS.length - 1);
    const size = value / 1024 ** power;
    return `${power === 0 ? Math.round(size) : size.toFixed(precision)} ${UNITS[power]}`;
}

export function speed(bytesPerSecond) {
    return bytesPerSecond > 0 ? `${bytes(bytesPerSecond)}/s` : '—';
}

export function duration(seconds) {
    if (!seconds || seconds < 0) return '—';
    if (seconds < 60) return `${Math.round(seconds)}s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${Math.round(seconds % 60)}s`;
    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

const pad = (n) => String(n).padStart(2, '0');

export function when(timestamp) {
    if (!timestamp) return '—';
    const date = new Date(timestamp * 1000);
    const diff = (Date.now() - date.getTime()) / 1000;
    if (diff < 60) return t('time.just_now');
    if (diff < 3600) return t('time.minutes', { count: Math.floor(diff / 60) });
    if (diff < 86400) return t('time.hours', { count: Math.floor(diff / 3600) });
    if (diff < 86400 * 6) return t('time.days', { count: Math.floor(diff / 86400) });
    const sameYear = date.getFullYear() === new Date().getFullYear();
    return sameYear
        ? `${pad(date.getDate())}/${pad(date.getMonth() + 1)} ${pad(date.getHours())}:${pad(date.getMinutes())}`
        : `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function fullDate(timestamp) {
    if (!timestamp) return '—';
    const d = new Date(timestamp * 1000);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

export function rwx(perms) {
    if (perms === null || perms === undefined) return '—';
    const map = ['---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx'];
    const octal = (perms & 0o777).toString(8).padStart(3, '0');
    return [...octal].map((digit) => map[Number(digit)]).join('');
}

export function octal(perms) {
    return perms === null || perms === undefined ? '—' : (perms & 0o777).toString(8).padStart(3, '0');
}

export function parentOf(path) {
    const clean = (path || '/').replace(/\/+$/, '');
    const cut = clean.lastIndexOf('/');
    return cut <= 0 ? '/' : clean.slice(0, cut);
}

export function joinPath(base, name) {
    return `${(base || '/').replace(/\/+$/, '')}/${name}`.replace(/\/{2,}/g, '/');
}

export function baseName(path) {
    const parts = (path || '/').replace(/\/+$/, '').split('/');
    return parts[parts.length - 1] || '/';
}

// ── toasts ───────────────────────────────────────────────────────────────────

const GLYPH = { info: 'info', ok: 'check', warn: 'alert', error: 'alert' };

export function toast(title, text = '', kind = 'info', timeout = 4200) {
    const host = $('#toasts');
    const node = el('div', { class: `toast toast-${kind}` },
        icon(GLYPH[kind] || 'info', 'icon icon-sm'),
        el('div', { class: 'body' },
            el('div', { class: 'title', text: title }),
            text ? el('div', { class: 'text', text }) : null),
        el('button', { class: 'icon-btn', title: t('common.dismiss'), onclick: () => close() }, icon('x', 'icon icon-sm')));

    let timer = null;
    const close = () => {
        clearTimeout(timer);
        node.classList.add('is-out');
        setTimeout(() => node.remove(), 200);
    };
    host.append(node);
    if (timeout) timer = setTimeout(close, timeout);
    return close;
}

export const notifyError = (error) =>
    toast(
        error?.code === 'csrf' ? t('toast.session_expired') : t('toast.error'),
        error?.message || String(error),
        'error',
        7000
    );

// ── modal ────────────────────────────────────────────────────────────────────

let openModals = 0;

/**
 * Build a modal. `render(close)` returns
 * { title, sub, glyph, tone, body, foot, wide, full, dismissable, onMount }.
 * Resolves with whatever close(value) was called with.
 */
export function modal(build) {
    return new Promise((resolve) => {
        const scrim = el('div', { class: 'scrim' });
        const close = (value) => {
            scrim.remove();
            openModals--;
            document.removeEventListener('keydown', onKey, true);
            resolve(value);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                close(undefined);
            }
        };

        const spec = build(close);
        const box = el('section', {
            class: `modal${spec.wide ? ' modal-wide' : ''}${spec.full ? ' modal-full' : ''}`,
            role: 'dialog',
            'aria-modal': 'true',
        });

        box.append(
            el('header', { class: 'modal-head' },
                spec.glyph ? el('span', { class: `glyph ${spec.tone || ''}` }, icon(spec.glyph)) : null,
                el('div', {},
                    el('h2', { text: spec.title }),
                    spec.sub ? el('div', { class: 'sub', text: spec.sub }) : null),
                el('button', { class: 'icon-btn', title: t('common.close'), onclick: () => close(undefined) }, icon('x'))),
            el('div', { class: 'modal-body' }, spec.body),
        );
        if (spec.foot) box.append(el('footer', { class: 'modal-foot' }, spec.foot));

        scrim.append(box);
        scrim.addEventListener('mousedown', (event) => {
            if (event.target === scrim && spec.dismissable !== false) close(undefined);
        });
        document.body.append(scrim);
        openModals++;
        document.addEventListener('keydown', onKey, true);

        // Dialogs that keep changing their own header - the viewer swapping
        // images, for one - need the built nodes back.
        spec.onMount?.(box, close);

        const focus = box.querySelector('[autofocus], input, textarea, button.btn-primary');
        if (focus) setTimeout(() => focus.focus(), 40);
    });
}

export const modalsOpen = () => openModals > 0;

// ── context menu ─────────────────────────────────────────────────────────────

let currentMenu = null;

export function closeMenu() {
    currentMenu?.remove();
    currentMenu = null;
}

/** items: [{label, icon, key, danger, disabled, onSelect}] or 'sep' or {label:'…', header:true} */
export function contextMenu(x, y, items) {
    closeMenu();
    const menu = el('div', { class: 'menu', role: 'menu' });

    for (const item of items) {
        if (item === 'sep') { menu.append(el('hr')); continue; }
        if (item.header) { menu.append(el('div', { class: 'menu-label', text: item.label })); continue; }
        menu.append(el('button', {
            class: item.danger ? 'danger' : '',
            disabled: item.disabled,
            onclick: () => { closeMenu(); item.onSelect?.(); },
        },
        icon(item.icon || 'chevron-right', 'icon icon-sm'),
        el('span', { text: item.label }),
        item.key ? el('span', { class: 'key', text: item.key }) : null));
    }

    document.body.append(menu);
    const box = menu.getBoundingClientRect();
    menu.style.left = `${Math.min(x, window.innerWidth - box.width - 8)}px`;
    menu.style.top = `${Math.min(y, window.innerHeight - box.height - 8)}px`;
    currentMenu = menu;

    const dismiss = (event) => {
        if (!menu.contains(event.target)) { closeMenu(); document.removeEventListener('mousedown', dismiss); }
    };
    setTimeout(() => document.addEventListener('mousedown', dismiss), 0);
    return menu;
}

// ── small building blocks ────────────────────────────────────────────────────

export function field(label, input, hint) {
    return el('div', { class: 'field' },
        el('label', { text: label, for: input.id || null }),
        input,
        hint ? el('span', { class: 'hint', text: hint }) : null);
}

export function checkbox(checked = false) {
    const box = el('span', { class: `checkbox${checked ? ' is-on' : ''}` }, icon('check'));
    box.toggle = (on) => box.classList.toggle('is-on', on);
    return box;
}

export function emptyState(glyph, title, text, action) {
    return el('div', { class: `state${glyph === 'alert' ? ' is-error' : ''}` },
        el('span', { class: 'glyph' }, icon(glyph, 'icon icon-lg')),
        el('h3', { text: title }),
        text ? el('p', { text }) : null,
        action || null);
}

export function skeleton(rows = 9) {
    return el('div', { class: 'skeleton' }, Array.from({ length: rows }, () => el('i')));
}
