// Every modal the app can open.

import { call, state, previewUrl, downloadUrl } from './api.js';
import { el, icon, field, modal, toast, notifyError, bytes, octal, rwx, fullDate } from './ui.js';
import { t } from './i18n.js';

const PROTOCOLS = [
    ['sftp', 'site.protocol_sftp', 22],
    ['ftp', 'site.protocol_ftp', 21],
    ['ftps', 'site.protocol_ftps', 21],
];

const COLOURS = ['#6366f1', '#8b5cf6', '#ec4899', '#ef4444', '#f59e0b', '#22c55e', '#14b8a6', '#38bdf8'];

export function confirm(title, message, options = {}) {
    return modal((close) => ({
        title,
        glyph: options.danger ? 'alert' : 'info',
        tone: options.danger ? 'danger' : '',
        body: [
            el('p', { text: message }),
            options.detail ? el('pre', { class: 'mono muted', style: { whiteSpace: 'pre-wrap', margin: 0 }, text: options.detail }) : null,
        ],
        foot: [
            el('button', { class: 'btn', onclick: () => close(false) }, t('common.cancel')),
            el('button', {
                class: options.danger ? 'btn btn-danger' : 'btn btn-primary',
                onclick: () => close(true),
            }, options.confirmLabel || t('common.confirm')),
        ],
    }));
}

export function prompt(title, label, value = '', options = {}) {
    return modal((close) => {
        const input = el('input', { class: 'input', value, id: 'prompt-input', autofocus: true, spellcheck: 'false' });
        const submit = () => {
            const text = input.value.trim();
            if (text) close(text);
        };
        input.addEventListener('keydown', (event) => { if (event.key === 'Enter') submit(); });

        return {
            title,
            glyph: options.glyph || 'pencil',
            body: field(label, input, options.hint),
            foot: [
                el('button', { class: 'btn', onclick: () => close(undefined) }, t('common.cancel')),
                el('button', { class: 'btn btn-primary', onclick: submit }, options.confirmLabel || t('common.save')),
            ],
        };
    });
}

// ── connection editor ────────────────────────────────────────────────────────

export function siteDialog(existing = null) {
    return modal((close) => {
        const data = existing
            ? { ...existing }
            : { protocol: 'sftp', port: 22, auth: 'password', passive: true, timeout: 20, workers: 0, colour: '#6366f1', backend: 'auto' };

        const inputs = {
            name: el('input', { class: 'input', value: data.name || '', placeholder: t('site.name_ph'), autofocus: true }),
            protocol: el('select', { class: 'select' }, PROTOCOLS.map(([value, label]) =>
                el('option', { value, selected: data.protocol === value }, t(label)))),
            host: el('input', { class: 'input', value: data.host || '', placeholder: t('site.host_ph'), spellcheck: 'false' }),
            port: el('input', { class: 'input', type: 'number', min: '1', max: '65535', value: data.port || 22 }),
            username: el('input', { class: 'input', value: data.username || '', autocomplete: 'off', spellcheck: 'false' }),
            password: el('input', { class: 'input', type: 'password', autocomplete: 'new-password', placeholder: data.hasPassword ? t('site.password_stored') : '' }),
            privateKey: el('textarea', { class: 'textarea', rows: 6, spellcheck: 'false', placeholder: data.hasKey ? t('site.key_stored') : '-----BEGIN OPENSSH PRIVATE KEY-----' }),
            passphrase: el('input', { class: 'input', type: 'password', autocomplete: 'new-password', placeholder: t('site.passphrase_ph') }),
            rootPath: el('input', { class: 'input', value: data.rootPath || '', placeholder: t('site.rootpath_ph'), spellcheck: 'false' }),
            timeout: el('input', { class: 'input', type: 'number', min: '5', max: '300', value: data.timeout || 20 }),
            workers: el('input', { class: 'input', type: 'number', min: '0', max: '16', value: data.workers ?? 0 }),
            passive: el('input', { type: 'checkbox', checked: data.passive !== false }),
            backend: el('select', { class: 'select' },
                el('option', { value: 'auto', selected: data.backend === 'auto' }, t('site.backend_auto')),
                el('option', { value: 'ssh2', selected: data.backend === 'ssh2' }, t('site.backend_ssh2')),
                el('option', { value: 'phpseclib', selected: data.backend === 'phpseclib' }, t('site.backend_seclib'))),
        };

        let colour = data.colour || '#6366f1';
        const swatches = el('div', { style: { display: 'flex', gap: '6px' } },
            COLOURS.map((value) => {
                const dot = el('button', {
                    type: 'button',
                    title: value,
                    style: {
                        width: '24px', height: '24px', borderRadius: '7px', cursor: 'pointer',
                        background: value, border: value === colour ? '2px solid var(--text)' : '2px solid transparent',
                    },
                    onclick: () => {
                        colour = value;
                        Array.from(swatches.children).forEach((child) => {
                            child.style.border = child.title === value ? '2px solid var(--text)' : '2px solid transparent';
                        });
                    },
                });
                return dot;
            }));

        const authTabs = el('div', { class: 'tabs' },
            el('button', { type: 'button', 'aria-selected': data.auth !== 'key', onclick: () => setAuth('password') }, t('site.tab_password')),
            el('button', { type: 'button', 'aria-selected': data.auth === 'key', onclick: () => setAuth('key') }, t('site.tab_key')));

        const passwordBlock = field(t('site.password'), inputs.password, existing ? t('site.password_hint') : '');
        const keyBlock = el('div', { class: 'field' },
            el('label', { text: t('site.key_label') }),
            inputs.privateKey,
            inputs.passphrase);

        let auth = data.auth === 'key' ? 'key' : 'password';
        function setAuth(next) {
            auth = next;
            Array.from(authTabs.children).forEach((tab, index) => {
                tab.setAttribute('aria-selected', String((index === 1) === (next === 'key')));
            });
            passwordBlock.hidden = next === 'key';
            keyBlock.hidden = next !== 'key';
        }

        const ftpRow = el('label', { class: 'switch' }, inputs.passive, el('span', { class: 'track' }), t('site.passive'));
        const sftpRows = el('div', { class: 'field' }, el('label', { text: t('site.backend') }), inputs.backend,
            el('span', { class: 'hint' }, t('site.backend_hint')));

        const defaultPort = (protocol) => PROTOCOLS.find(([value]) => value === protocol)?.[2] ?? 22;
        let lastProtocol = data.protocol || 'sftp';

        /**
         * Switching protocol offers the new default port, but only when the
         * field still holds the old protocol's default. A port the operator
         * chose (2222, 2121, …) is never overwritten - and `initial` keeps a
         * saved connection's port intact when the dialog opens.
         */
        function syncProtocol(initial = false) {
            const protocol = inputs.protocol.value;
            const isFtp = protocol === 'ftp' || protocol === 'ftps';
            if (!initial) {
                const current = Number(inputs.port.value);
                if (!current || current === defaultPort(lastProtocol)) {
                    inputs.port.value = defaultPort(protocol);
                }
            }
            lastProtocol = protocol;
            authTabs.hidden = isFtp;
            keyBlock.hidden = isFtp || auth !== 'key';
            passwordBlock.hidden = !isFtp && auth === 'key';
            ftpRow.hidden = !isFtp;
            sftpRows.hidden = isFtp;
        }
        inputs.protocol.addEventListener('change', () => syncProtocol());

        const result = el('div', { class: 'hint' });

        function collect() {
            return {
                id: data.id || '',
                name: inputs.name.value.trim(),
                protocol: inputs.protocol.value,
                host: inputs.host.value.trim(),
                port: Number(inputs.port.value) || 22,
                username: inputs.username.value.trim(),
                auth,
                password: inputs.password.value,
                privateKey: inputs.privateKey.value.trim(),
                passphrase: inputs.passphrase.value,
                rootPath: inputs.rootPath.value.trim(),
                passive: inputs.passive.checked,
                timeout: Number(inputs.timeout.value) || 20,
                workers: Math.max(0, Math.min(16, Number(inputs.workers.value) || 0)),
                colour,
                backend: inputs.backend.value,
            };
        }

        const testButton = el('button', { class: 'btn', onclick: test }, icon('link', 'icon icon-sm'), t('site.test'));
        async function test() {
            testButton.disabled = true;
            result.textContent = t('site.testing');
            result.style.color = 'var(--muted)';
            try {
                const info = await call('site.test', { site: collect() });
                result.textContent = t('site.test_ok', {
                    message: info.message, home: info.home, items: info.items, ms: info.ms,
                });
                result.style.color = 'var(--ok)';
            } catch (error) {
                result.textContent = `✗ ${error.message}`;
                result.style.color = 'var(--danger)';
            } finally {
                testButton.disabled = false;
            }
        }

        const saveButton = el('button', { class: 'btn btn-primary', onclick: save }, icon('save', 'icon icon-sm'), t('site.save'));
        async function save() {
            saveButton.disabled = true;
            try {
                const response = await call('site.save', { site: collect() });
                state.sites = response.sites;
                toast(t('toast.site_saved'), response.site.name, 'ok');
                close(response.site);
            } catch (error) {
                notifyError(error);
                saveButton.disabled = false;
            }
        }

        setAuth(auth);
        setTimeout(() => syncProtocol(true), 0);

        return {
            title: existing ? t('site.edit') : t('site.new'),
            sub: existing ? existing.label : t('site.sub'),
            glyph: 'server',
            wide: true,
            body: [
                el('div', { class: 'grid-2' },
                    field(t('site.name'), inputs.name),
                    field(t('site.protocol'), inputs.protocol)),
                el('div', { class: 'grid-2' },
                    field(t('site.host'), inputs.host),
                    field(t('site.port'), inputs.port)),
                field(t('site.username'), inputs.username),
                authTabs,
                passwordBlock,
                keyBlock,
                el('div', { class: 'grid-2' },
                    field(t('site.rootpath'), inputs.rootPath),
                    field(t('site.timeout'), inputs.timeout)),
                // Per connection, because servers differ: one allows ten
                // sessions, the next drops the third. 0 keeps following the
                // config default, which is what every existing profile does.
                field(t('site.workers'), inputs.workers,
                    t('site.workers_hint', { default: Number(state.settings.transferWorkers) || 1 })),
                ftpRow,
                sftpRows,
                el('div', { class: 'field' }, el('label', { text: t('site.colour') }), swatches),
                result,
            ],
            foot: [
                el('span', { class: 'spacer' }, testButton),
                el('button', { class: 'btn', onclick: () => close(undefined) }, t('common.cancel')),
                saveButton,
            ],
        };
    });
}

// ── permissions ──────────────────────────────────────────────────────────────

export function chmodDialog(entries) {
    return modal((close) => {
        const start = entries[0]?.perms ?? 0o644;
        const boxes = [];
        const octalInput = el('input', { class: 'input perm-octal mono', value: (start & 0o777).toString(8).padStart(3, '0'), maxlength: '4' });

        const table = el('table', { class: 'perm-grid' },
            el('thead', {}, el('tr', {}, el('th', { text: '' }), el('th', { text: t('perm.read') }), el('th', { text: t('perm.write') }), el('th', { text: t('perm.execute') }))),
            el('tbody', {}, [t('perm.owner'), t('perm.group'), t('perm.others')].map((label, group) =>
                el('tr', {}, el('td', { text: label }), [4, 2, 1].map((bit) => {
                    const box = el('input', { type: 'checkbox', onchange: fromBoxes });
                    box.dataset.group = group;
                    box.dataset.bit = bit;
                    boxes.push(box);
                    return el('td', {}, box);
                })))));

        function toBoxes() {
            const value = parseInt(octalInput.value || '0', 8) & 0o777;
            boxes.forEach((box) => {
                const shift = (2 - Number(box.dataset.group)) * 3;
                box.checked = Boolean((value >> shift) & Number(box.dataset.bit));
            });
        }
        function fromBoxes() {
            let value = 0;
            boxes.forEach((box) => {
                if (box.checked) value |= Number(box.dataset.bit) << ((2 - Number(box.dataset.group)) * 3);
            });
            octalInput.value = value.toString(8).padStart(3, '0');
        }
        octalInput.addEventListener('input', toBoxes);
        toBoxes();

        const presets = el('div', { style: { display: 'flex', gap: '6px', flexWrap: 'wrap' } },
            [['644', t('perm.files')], ['755', t('perm.folders')], ['600', t('perm.private')], ['777', t('perm.everyone')]].map(([value, label]) =>
                el('button', {
                    class: 'btn btn-sm', type: 'button',
                    onclick: () => { octalInput.value = value; toBoxes(); },
                }, el('span', { class: 'mono', text: value }), label)));

        return {
            title: t('perm.title'),
            sub: entries.length === 1 ? entries[0].name : t('count.items', { count: entries.length }),
            glyph: 'lock',
            body: [
                octalInput,
                table,
                presets,
                el('p', { class: 'hint', text: t('perm.note') }),
            ],
            foot: [
                el('button', { class: 'btn', onclick: () => close(undefined) }, t('common.cancel')),
                el('button', { class: 'btn btn-primary', onclick: () => close(octalInput.value) }, t('common.apply')),
            ],
        };
    });
}

// ── text editor ──────────────────────────────────────────────────────────────

export function editorDialog(file, content, onSave) {
    return modal((close) => {
        const gutter = el('div', { class: 'gutter' });
        const area = el('textarea', { spellcheck: 'false', wrap: 'off' });
        area.value = content;

        const renderGutter = () => {
            const lines = area.value.split('\n').length;
            gutter.textContent = Array.from({ length: lines }, (_, i) => i + 1).join('\n');
        };
        area.addEventListener('input', renderGutter);
        area.addEventListener('scroll', () => { gutter.scrollTop = area.scrollTop; });
        area.addEventListener('keydown', (event) => {
            if (event.key === 'Tab') {
                event.preventDefault();
                const { selectionStart: s, selectionEnd: e } = area;
                area.value = `${area.value.slice(0, s)}    ${area.value.slice(e)}`;
                area.selectionStart = area.selectionEnd = s + 4;
            }
            if ((event.ctrlKey || event.metaKey) && event.key === 's') {
                event.preventDefault();
                save();
            }
        });
        renderGutter();

        const status = el('span', { class: 'hint' });
        const saveButton = el('button', { class: 'btn btn-primary', onclick: save }, icon('save', 'icon icon-sm'), t('common.save'));

        async function save() {
            saveButton.disabled = true;
            status.textContent = t('editor.saving');
            try {
                await onSave(area.value);
                status.textContent = t('editor.saved', { size: bytes(new Blob([area.value]).size) });
                status.style.color = 'var(--ok)';
            } catch (error) {
                status.textContent = error.message;
                status.style.color = 'var(--danger)';
            } finally {
                saveButton.disabled = false;
            }
        }

        return {
            title: file.name,
            sub: file.path,
            glyph: 'file-code',
            full: true,
            dismissable: false,
            body: el('div', { class: 'editor' }, gutter, area),
            foot: [
                el('span', { class: 'spacer' }, status),
                el('button', { class: 'btn', onclick: () => close(undefined) }, t('common.close')),
                saveButton,
            ],
        };
    });
}

// ── image viewer ─────────────────────────────────────────────────────────────

const ZOOM_STEPS = [0.1, 0.2, 0.33, 0.5, 0.75, 1, 1.5, 2, 3, 4, 6, 8];

/**
 * Shows one image, with the rest of the listing reachable through ← / →.
 * Navigation only ever stops on images: a text file or an archive in between is
 * stepped over, and a non-image opened here says so instead of handing the file
 * to some other action, which would drop the viewer out from under the reader.
 *
 * The bytes come from download.php?inline=1 and are only ever painted into an
 * <img>, never framed - the note in download.php explains why that matters.
 */
export function imageDialog(siteId, files, index = 0, { onEdit } = {}) {
    const images = files.filter((file) => file.image);
    let at = Math.max(0, Math.min(index, files.length - 1));
    let scale = 0; // 0 means "whatever fits the stage"
    let head = null;
    let box = null;
    let detach = () => {};

    const opened = modal((close) => {
        const img = el('img', { class: 'viewer-img', alt: '', draggable: 'false' });
        const note = el('p', { class: 'viewer-note' });
        const stage = el('div', { class: 'viewer-stage' }, img, note);
        const meta = el('span', { class: 'hint' });
        const current = () => files[at];

        const zoomLabel = el('button', {
            class: 'btn btn-sm mono',
            title: t('viewer.actual_size'),
            style: { minWidth: '64px', justifyContent: 'center' },
            onclick: () => apply(scale === 1 ? 0 : 1),
        });
        const zoomOut = el('button', { class: 'icon-btn', title: t('viewer.zoom_out'), onclick: () => step(-1) }, icon('minus'));
        const zoomIn = el('button', { class: 'icon-btn', title: t('viewer.zoom_in'), onclick: () => step(1) }, icon('plus'));
        const zoomFit = el('button', { class: 'icon-btn', title: t('viewer.fit_window'), onclick: () => apply(0) }, icon('fit'));
        const saveLink = el('a', { class: 'btn' }, icon('download', 'icon icon-sm'), t('viewer.download'));
        const editButton = el('button', {
            class: 'btn',
            onclick: () => { const file = current(); close(undefined); onEdit?.(file); },
        }, icon('pencil', 'icon icon-sm'), t('viewer.edit_text'));

        // An SVG with only a viewBox reports no intrinsic size; fall back to the
        // stage so zooming still means something for those.
        const natural = () => img.naturalWidth || stage.clientWidth;

        /** The scale the image is actually drawn at while the stage is fitting it. */
        const fitted = () => (img.naturalWidth
            ? Math.min(1, stage.clientWidth / img.naturalWidth, stage.clientHeight / img.naturalHeight)
            : 1);

        function apply(next) {
            scale = next;
            stage.classList.toggle('is-zoomed', next !== 0);
            img.style.width = next === 0 ? '' : `${Math.round(natural() * next)}px`;
            zoomLabel.textContent = next === 0 ? t('viewer.fit') : `${Math.round(next * 100)}%`;
        }

        function step(direction) {
            if (!current().image) return;
            const from = scale || fitted();
            const next = direction > 0
                ? ZOOM_STEPS.find((value) => value > from + 0.005)
                : [...ZOOM_STEPS].reverse().find((value) => value < from - 0.005);
            if (next) apply(next);
        }

        function describe() {
            const file = current();
            const spot = images.indexOf(file);
            meta.textContent = [
                file.image ? (img.naturalWidth ? `${img.naturalWidth} × ${img.naturalHeight}` : '…') : t('viewer.not_image'),
                bytes(file.size),
                images.length > 1 && spot >= 0 ? t('viewer.position', { index: spot + 1, total: images.length }) : '',
            ].filter(Boolean).join('  ·  ');
        }

        function show() {
            const file = current();
            stage.classList.remove('is-broken');
            apply(0);

            if (file.image) {
                stage.classList.remove('is-blocked');
                stage.classList.add('is-loading');
                img.src = previewUrl(siteId, file.path, file.mtime);
            } else {
                // Nothing is fetched for a file the viewer cannot draw.
                stage.classList.remove('is-loading');
                stage.classList.add('is-blocked');
                note.textContent = t('viewer.not_image_text', { name: file.name });
            }
            [zoomOut, zoomIn, zoomFit, zoomLabel].forEach((button) => { button.disabled = !file.image; });

            saveLink.href = downloadUrl(siteId, [file.path]);
            editButton.hidden = !onEdit || !file.editable;
            if (head) {
                head.title.textContent = file.name;
                head.sub.textContent = file.path;
            }
            describe();
        }

        /** Steps to the next image in listing order, skipping everything else. */
        function go(delta) {
            if (images.length === 0) return;
            let index = at;
            for (let hop = 0; hop < files.length; hop++) {
                index = (index + delta + files.length) % files.length;
                if (files[index].image) break;
            }
            if (index === at) return;
            at = index;
            show();
        }

        img.addEventListener('load', () => {
            if (!current().image) return;
            stage.classList.remove('is-loading');
            apply(0);
            describe();
        });
        img.addEventListener('error', () => {
            if (!current().image) return;
            stage.classList.remove('is-loading');
            stage.classList.add('is-broken');
            note.textContent = t('viewer.broken', { name: current().name });
        });
        img.addEventListener('dblclick', () => apply(scale === 0 ? 1 : 0));

        stage.addEventListener('wheel', (event) => {
            if (!event.ctrlKey) return;
            event.preventDefault();
            step(event.deltaY < 0 ? 1 : -1);
        }, { passive: false });

        // Drag to pan, once the image is larger than the stage.
        stage.addEventListener('pointerdown', (event) => {
            if (scale === 0 || event.button !== 0) return;
            event.preventDefault();
            const from = { x: event.clientX, y: event.clientY, left: stage.scrollLeft, top: stage.scrollTop };
            const move = (moved) => {
                stage.scrollLeft = from.left - (moved.clientX - from.x);
                stage.scrollTop = from.top - (moved.clientY - from.y);
            };
            const drop = () => {
                stage.classList.remove('is-panning');
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', drop);
            };
            stage.classList.add('is-panning');
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', drop);
        });

        const KEYS = {
            ArrowLeft: () => go(-1),
            ArrowRight: () => go(1),
            '+': () => step(1),
            '=': () => step(1),
            '-': () => step(-1),
            _: () => step(-1),
            0: () => apply(1),
            f: () => apply(0),
        };
        const onKey = (event) => {
            // A dialog stacked on top of this one owns the keyboard.
            const scrims = document.querySelectorAll('.scrim');
            if (!box || scrims[scrims.length - 1] !== box.parentElement) return;
            const handler = KEYS[event.key];
            if (!handler) return;
            event.preventDefault();
            handler();
        };
        document.addEventListener('keydown', onKey, true);
        detach = () => document.removeEventListener('keydown', onKey, true);

        return {
            title: files[at].name,
            sub: files[at].path,
            glyph: 'file-image',
            full: true,
            body: stage,
            foot: [
                el('span', { class: 'spacer' }, meta),
                zoomOut,
                zoomLabel,
                zoomIn,
                zoomFit,
                images.length > 1 ? el('button', { class: 'icon-btn', title: t('viewer.prev'), onclick: () => go(-1) }, icon('chevron-left')) : null,
                images.length > 1 ? el('button', { class: 'icon-btn', title: t('viewer.next'), onclick: () => go(1) }, icon('chevron-right')) : null,
                editButton,
                saveLink,
                el('button', { class: 'btn btn-primary', onclick: () => close(undefined) }, t('common.close')),
            ],
            onMount: (mounted) => {
                box = mounted;
                head = {
                    title: mounted.querySelector('.modal-head h2'),
                    sub: mounted.querySelector('.modal-head .sub'),
                };
                show();
            },
        };
    });

    return opened.finally(() => detach());
}

// ── transfer options ─────────────────────────────────────────────────────────

export function transferDialog({ mode, count, from, to, targetPath }) {
    return modal((close) => {
        const options = [
            ['overwrite', t('transfer.overwrite'), t('transfer.overwrite_hint')],
            ['newer', t('transfer.newer'), t('transfer.newer_hint')],
            ['rename', t('transfer.rename'), t('transfer.rename_hint')],
            ['skip', t('transfer.skip'), t('transfer.skip_hint')],
        ];
        let conflict = 'overwrite';

        const list = el('div', { style: { display: 'flex', flexDirection: 'column', gap: '6px' } },
            options.map(([value, label, hint]) => {
                const radio = el('input', { type: 'radio', name: 'conflict', value, checked: value === 'overwrite' });
                radio.addEventListener('change', () => { conflict = value; });
                return el('label', {
                    style: {
                        display: 'flex', gap: '10px', alignItems: 'flex-start', cursor: 'pointer',
                        padding: '9px 11px', border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)',
                    },
                }, radio, el('div', {}, el('div', { style: { fontWeight: '600' }, text: label }), el('div', { class: 'hint', text: hint })));
            }));

        return {
            title: mode === 'move' ? t('transfer.move_title') : t('transfer.copy_title'),
            sub: t('transfer.sub', { items: t('count.items', { count }), from, to }),
            glyph: mode === 'move' ? 'swap' : 'arrow-right',
            body: [
                el('div', { class: 'field' },
                    el('label', { text: t('transfer.destination') }),
                    el('div', { class: 'mono', style: { padding: '9px 11px', background: 'var(--surface-2)', borderRadius: 'var(--radius-sm)', wordBreak: 'break-all' }, text: targetPath })),
                el('div', { class: 'field' }, el('label', { text: t('transfer.if_exists') }), list),
            ],
            foot: [
                el('button', { class: 'btn', onclick: () => close(undefined) }, t('common.cancel')),
                el('button', { class: 'btn btn-primary', onclick: () => close({ conflict }) },
                    icon(mode === 'move' ? 'swap' : 'arrow-right', 'icon icon-sm'),
                    mode === 'move' ? t('common.move') : t('common.copy')),
            ],
        };
    });
}

// ── properties ───────────────────────────────────────────────────────────────

export function propertiesDialog(entry, siteName) {
    const row = (label, value) => el('div', { style: { display: 'flex', gap: '12px', padding: '6px 0', borderBottom: '1px solid var(--border)' } },
        el('span', { class: 'muted', style: { width: '110px', flex: 'none', fontSize: '12px' }, text: label }),
        el('span', { class: 'mono', style: { wordBreak: 'break-all' }, text: value }));

    return modal((close) => ({
        title: entry.name,
        sub: siteName,
        glyph: entry.isDir ? 'folder' : 'file',
        body: el('div', {},
            row(t('prop.path'), entry.path),
            row(t('prop.type'), entry.isDir
                ? t('prop.directory')
                : t('prop.file', { ext: entry.ext || t('prop.no_extension') })),
            row(t('prop.size'), entry.isDir
                ? '—'
                : t('prop.bytes', { size: bytes(entry.size), exact: entry.size.toLocaleString() })),
            row(t('prop.modified'), fullDate(entry.mtime)),
            row(t('prop.permissions'), `${octal(entry.perms)}  ${rwx(entry.perms)}`),
            row(t('prop.owner'), `${entry.owner || '—'} : ${entry.group || '—'}`),
            entry.target ? row(t('prop.links_to'), entry.target) : null),
        foot: [el('button', { class: 'btn btn-primary', onclick: () => close(undefined) }, t('common.close'))],
    }));
}

// ── help ─────────────────────────────────────────────────────────────────────

const SHORTCUTS = [
    ['Tab', 'keys.tab'],
    ['Enter', 'keys.enter'],
    ['Backspace', 'keys.backspace'],
    ['F2', 'keys.f2'],
    ['F3', 'keys.f3'],
    ['F4', 'keys.f4'],
    ['F5', 'keys.f5'],
    ['F6', 'keys.f6'],
    ['F7', 'keys.f7'],
    ['F8 / Delete', 'keys.f8'],
    ['Ctrl+A', 'keys.select_all'],
    ['Ctrl+R', 'keys.refresh'],
    ['Ctrl+F', 'keys.filter'],
    ['Ctrl+D', 'keys.download'],
    ['Ctrl+H', 'keys.hidden'],
    ['Space', 'keys.space'],
    ['↑ ↓', 'keys.arrows'],
    ['?', 'keys.help'],
];

export function helpDialog() {
    return modal((close) => ({
        title: t('keys.title'),
        glyph: 'info',
        wide: true,
        body: el('div', { class: 'grid-2' },
            SHORTCUTS.map(([key, what]) => el('div', {
                style: { display: 'flex', gap: '10px', alignItems: 'center', padding: '5px 0' },
            },
            el('span', { class: 'badge mono', style: { minWidth: '92px', justifyContent: 'center' }, text: key }),
            el('span', { text: t(what) })))),
        foot: [el('button', { class: 'btn btn-primary', onclick: () => close(undefined) }, t('common.got_it'))],
    }));
}
