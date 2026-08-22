// Translation lookup for the browser half of the app.
//
// index.php inlines the whole table for the active language into window.FB_I18N
// before any module runs, so t() never has to wait for a request and there is
// no flash of untranslated text. Keys and placeholders are exactly the ones
// lang/*.php uses - see Lang.php for the format.

const bundle = window.FB_I18N || {};
const STRINGS = bundle.strings || {};

/** Active language code, e.g. "th". */
export const lang = bundle.code || 'en';

/** { code: label } for the switcher, in the order the server listed them. */
export const LANGUAGES = bundle.languages || { en: 'English' };

/**
 * Look a key up and fill its {placeholders}.
 *
 * A `count` placeholder also picks the "<key>_plural" entry when it is not 1,
 * which is how English gets "1 file" / "2 files" while Thai keeps one form.
 */
export function t(key, vars) {
    let text = STRINGS[key];
    if (text === undefined) return key;

    if (vars && vars.count !== undefined && Number(vars.count) !== 1 && STRINGS[`${key}_plural`]) {
        text = STRINGS[`${key}_plural`];
    }
    if (!vars) return text;

    return text.replace(/\{(\w+)\}/g, (match, name) => (vars[name] === undefined ? match : String(vars[name])));
}

/**
 * Switch language and reload.
 *
 * The cookie is what PHP reads on the next request - it has to be, because the
 * page shell and every server message are rendered before any script runs. The
 * localStorage copy is only for offline.html, which has no server to ask.
 */
export function setLanguage(code) {
    if (code === lang || !LANGUAGES[code]) return;
    const path = bundle.path || '/';
    document.cookie = `fb_lang=${encodeURIComponent(code)}; path=${path}; max-age=31536000; samesite=Strict`;
    try { localStorage.setItem('fb.lang', code); } catch { /* private mode */ }
    window.location.reload();
}
