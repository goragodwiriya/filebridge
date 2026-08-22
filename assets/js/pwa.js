// Installable app: service worker registration, the install button and the
// browser chrome colour.

import { $, toast } from './ui.js';
import { t } from './i18n.js';

// The import map stamps every module with the release number - reuse it so the
// worker registration changes URL whenever the assets do.
const VERSION = new URL(import.meta.url).searchParams.get('v') || '';

let deferred = null;

export function initPwa() {
    registerWorker();

    // Chromium fires this instead of installing straight away, which is what
    // lets the button below exist at all. Other browsers install from their own
    // menu and never fire it, so the button simply stays hidden there.
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event;
        showButton();
    });

    window.addEventListener('appinstalled', () => {
        deferred = null;
        hideButton();
        const name = document.querySelector('meta[name="application-name"]')?.content || 'FileBridge';
        toast(t('app.installed', { name }), t('app.installed_text'), 'ok');
    });
}

/**
 * Keeps the browser and OS window chrome in step with the theme toggle. The
 * colour is read from the stylesheet rather than repeated here, so the title
 * bar of the installed app always matches the top bar below it.
 */
export function applyThemeColour() {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    const surface = getComputedStyle(document.documentElement).getPropertyValue('--surface').trim();
    if (surface) meta.content = surface;
}

function registerWorker() {
    if (!('serviceWorker' in navigator)) return;
    // A worker needs a secure context; localhost counts as one.
    if (!window.isSecureContext) return;

    // Registering competes with the first listing for bandwidth, so it waits
    // for the page to settle.
    const register = () => navigator.serviceWorker
        .register(VERSION ? `sw.php?v=${VERSION}` : 'sw.php')
        .catch(() => { /* not fatal - the app works without it */ });

    if (document.readyState === 'complete') register();
    else window.addEventListener('load', register);
}

function showButton() {
    const button = $('#btn-install');
    if (!button) return;
    button.hidden = false;

    if (button.dataset.wired) return;
    button.dataset.wired = '1';
    button.addEventListener('click', async () => {
        if (!deferred) return;
        const pending = deferred;
        // The event is good for one prompt only. Dismissing it hides the
        // button; the browser offers a fresh one on a later visit.
        deferred = null;
        hideButton();
        pending.prompt();
        await pending.userChoice;
    });
}

function hideButton() {
    const button = $('#btn-install');
    if (button) button.hidden = true;
}
