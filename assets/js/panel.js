// One side of the file manager: connection, navigation, selection, file actions.

import { call, upload, downloadUrl, state, site as findSite } from './api.js';
import {
    el, icon, $, $$, bytes, when, octal, rwx, toast, notifyError,
    contextMenu, emptyState, skeleton, parentOf,
} from './ui.js';
import { confirm, prompt, chmodDialog, editorDialog, propertiesDialog } from './dialogs.js';

const CHUNK = 400; // rows rendered per batch

/** Slice uploads just under whatever php.ini accepts in one request. */
function uploadChunk() {
    const limit = Number(state.settings.maxUploadSize) || 0;
    return limit > 0 ? Math.max(262144, Math.floor(limit * 0.9)) : 4 << 20;
}

export class Panel {
    constructor(side, ctx) {
        this.side = side;
        this.ctx = ctx;
        this.siteId = 'local';
        this.path = '';
        this.entries = [];
        this.view = [];
        this.selected = new Set();
        this.cursor = -1;
        this.sort = { key: 'name', dir: 1 };
        this.filter = '';
        this.showHidden = Boolean(state.settings.showHidden);
        this.capabilities = {};
        this.rendered = 0;
        this.ticket = 0;

        this.build();
    }

    // ── construction ─────────────────────────────────────────────────────────

    build() {
        const fragment = $('#tpl-panel').content.cloneNode(true);
        this.root = fragment.querySelector('.panel');
        this.root.dataset.side = this.side;
        this.el = {};
        $$('[data-el]', this.root).forEach((node) => { this.el[node.dataset.el] = node; });

        this.root.addEventListener('mousedown', () => this.focus(), true);
        this.root.addEventListener('focusin', () => this.focus());

        this.el.site.addEventListener('change', () => this.connect(this.el.site.value));
        this.el.filter.addEventListener('input', () => {
            this.filter = this.el.filter.value.trim().toLowerCase();
            this.refreshView();
        });
        this.el.filter.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { this.el.filter.value = ''; this.filter = ''; this.refreshView(); }
        });

        $$('[data-act]', this.root).forEach((button) => {
            button.addEventListener('click', () => this.action(button.dataset.act));
        });
        $$('th[data-sort]', this.root).forEach((th) => {
            th.addEventListener('click', () => this.setSort(th.dataset.sort));
        });
        this.el.checkAll.addEventListener('click', () => this.toggleAll());
        this.el.listWrap.addEventListener('scroll', () => this.onScroll());
        this.el.rows.addEventListener('click', (event) => this.onRowClick(event));
        this.el.rows.addEventListener('dblclick', (event) => this.onRowDouble(event));
        this.el.rows.addEventListener('contextmenu', (event) => this.onRowMenu(event));
        this.el.fileInput.addEventListener('change', () => this.uploadFiles(Array.from(this.el.fileInput.files)));

        this.setupDragAndDrop();
    }

    focus() {
        this.ctx.onFocus(this);
    }

    // ── connection ───────────────────────────────────────────────────────────

    fillSites() {
        const current = this.siteId;
        this.el.site.replaceChildren(...state.sites.map((entry) =>
            el('option', { value: entry.id, selected: entry.id === current }, `${entry.name}  ·  ${entry.label}`)));
    }

    async connect(siteId, path = '') {
        this.siteId = siteId;
        this.el.site.value = siteId;
        this.selected.clear();
        await this.load(path);
        this.ctx.onConnected?.(this);
    }

    async load(path = '', options = {}) {
        const requested = path;
        // A slow listing must never paint over a folder opened after it.
        const ticket = ++this.ticket;
        this.setBusy(true);
        if (!options.keepSelection) this.selected.clear();
        this.el.state.replaceChildren(skeleton());
        this.el.rows.replaceChildren();

        try {
            const data = await call('fs.list', { site: this.siteId, path: requested });
            if (ticket !== this.ticket) return;
            this.path = data.path;
            this.entries = data.entries;
            this.capabilities = data.capabilities;
            this.totals = data.totals;
            this.el.desc.textContent = data.description;
            this.el.desc.title = data.description;
            this.el.dot.className = 'dot dot-ok';
            this.home = data.home;
            this.roots = data.roots || [];
            this.el.state.replaceChildren();
            this.renderCrumbs();
            this.refreshView();
        } catch (error) {
            if (ticket !== this.ticket) return;
            this.el.dot.className = 'dot dot-danger';
            this.entries = [];
            this.view = [];
            this.el.state.replaceChildren(emptyState('alert', 'Cannot open this folder', error.message,
                el('button', { class: 'btn btn-sm', onclick: () => this.load(this.path || '') }, icon('refresh', 'icon icon-sm'), 'Try again')));
            this.renderCrumbs();
            this.updateFoot();
        } finally {
            if (ticket === this.ticket) this.setBusy(false);
        }
    }

    setBusy(busy) {
        if (busy) this.el.dot.className = 'dot dot-busy';
        this.root.dataset.busy = busy ? '1' : '';
    }

    // ── rendering ────────────────────────────────────────────────────────────

    renderCrumbs() {
        const crumbs = this.el.crumbs;
        crumbs.replaceChildren();

        const parts = (this.path || '/').split('/').filter(Boolean);
        crumbs.append(el('button', { title: 'Root', onclick: () => this.load('/') }, '/'));

        let walk = '';
        parts.forEach((part) => {
            walk += `/${part}`;
            const target = walk;
            crumbs.append(icon('chevron-right', 'icon sep'), el('button', { onclick: () => this.load(target), text: part }));
        });

        crumbs.ondblclick = (event) => {
            if (event.target.tagName === 'BUTTON') return;
            this.editPath();
        };
        crumbs.scrollLeft = crumbs.scrollWidth;
    }

    editPath() {
        const input = el('input', { value: this.path, spellcheck: 'false' });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') this.load(input.value.trim() || '/');
            if (event.key === 'Escape') this.renderCrumbs();
        });
        input.addEventListener('blur', () => this.renderCrumbs());
        this.el.crumbs.replaceChildren(input);
        input.focus();
        input.select();
    }

    refreshView() {
        const dirs = [];
        const files = [];
        for (const entry of this.entries) {
            if (!this.showHidden && entry.hidden) continue;
            if (this.filter && !entry.name.toLowerCase().includes(this.filter)) continue;
            (entry.isDir ? dirs : files).push(entry);
        }

        const { key, dir } = this.sort;
        const compare = (a, b) => {
            let result;
            if (key === 'name') result = a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
            else if (key === 'size') result = a.size - b.size;
            else if (key === 'mtime') result = a.mtime - b.mtime;
            else result = (a.perms || 0) - (b.perms || 0);
            return result * dir;
        };
        dirs.sort(compare);
        files.sort(compare);

        this.view = [...dirs, ...files];
        this.rendered = 0;
        this.el.rows.replaceChildren();
        this.el.listWrap.scrollTop = 0;

        if (this.path !== '/' && this.path) {
            this.el.rows.append(this.parentRow());
        }
        this.renderMore();
        this.updateFoot();
        this.syncSortHeaders();

        if (this.view.length === 0 && this.entries.length > 0) {
            this.el.state.replaceChildren(emptyState('search', 'No match', `Nothing here matches “${this.filter}”.`));
        } else if (this.entries.length === 0) {
            this.el.state.replaceChildren(emptyState('folder-open', 'Empty folder', 'Drop files here or use the upload button.'));
        } else {
            this.el.state.replaceChildren();
        }
    }

    parentRow() {
        const row = el('tr', { class: 'is-parent' },
            el('td', { class: 'col-check' }),
            el('td', { colspan: '4' }, el('span', { class: 'cell-name' },
                icon('arrow-up', 'icon icon-sm k-folder'), el('span', { class: 'name', text: '..' }))));
        row.addEventListener('dblclick', () => this.load(parentOf(this.path)));
        row.addEventListener('click', () => this.load(parentOf(this.path)));
        return row;
    }

    renderMore() {
        const slice = this.view.slice(this.rendered, this.rendered + CHUNK);
        if (slice.length === 0) return;
        const fragment = document.createDocumentFragment();
        slice.forEach((entry, offset) => fragment.append(this.row(entry, this.rendered + offset)));
        this.el.rows.append(fragment);
        this.rendered += slice.length;
    }

    onScroll() {
        const wrap = this.el.listWrap;
        if (this.rendered < this.view.length && wrap.scrollTop + wrap.clientHeight > wrap.scrollHeight - 300) {
            this.renderMore();
        }
    }

    row(entry, index) {
        const selected = this.selected.has(entry.path);
        const tr = el('tr', {
            class: [selected ? 'is-selected' : '', entry.hidden ? 'is-hidden-file' : ''].filter(Boolean).join(' '),
            draggable: 'true',
            title: entry.path,
        });
        tr.dataset.index = index;
        tr.dataset.path = entry.path;

        const check = el('span', { class: `checkbox${selected ? ' is-on' : ''}` }, icon('check'));

        tr.append(
            el('td', { class: 'col-check' }, check),
            el('td', { class: 'col-name' }, el('span', { class: 'cell-name' },
                icon(entry.icon, `icon icon-sm k-${entry.kind}`),
                el('span', { class: 'name', text: entry.name }),
                entry.target ? el('span', { class: 'link-to', text: `→ ${entry.target}` }) : null)),
            el('td', { class: 'col-size mono', text: entry.isDir ? '—' : bytes(entry.size) }),
            el('td', { class: 'col-date muted', text: when(entry.mtime) }),
            el('td', { class: 'col-perm mono muted', text: octal(entry.perms), title: rwx(entry.perms) }),
        );
        return tr;
    }

    syncSortHeaders() {
        $$('th[data-sort]', this.root).forEach((th) => {
            const active = th.dataset.sort === this.sort.key;
            th.classList.toggle('is-sorted', active);
            th.classList.toggle('desc', active && this.sort.dir === -1);
        });
    }

    setSort(key) {
        this.sort = this.sort.key === key ? { key, dir: -this.sort.dir } : { key, dir: 1 };
        this.refreshView();
    }

    updateFoot() {
        const folders = this.view.filter((entry) => entry.isDir).length;
        const files = this.view.length - folders;
        const size = this.view.reduce((sum, entry) => sum + (entry.isDir ? 0 : entry.size), 0);
        this.el.stats.textContent = `${folders} folder${folders === 1 ? '' : 's'} · ${files} file${files === 1 ? '' : 's'} · ${bytes(size)}`;

        const chosen = this.selection();
        const chosenSize = chosen.reduce((sum, entry) => sum + (entry.isDir ? 0 : entry.size), 0);
        this.el.selstats.className = chosen.length ? 'sel' : 'muted';
        this.el.selstats.textContent = chosen.length ? `${chosen.length} selected · ${bytes(chosenSize)}` : '';
        this.el.checkAll.classList.toggle('is-on', chosen.length > 0 && chosen.length === this.view.length);
        this.ctx.onSelection?.(this);
    }

    // ── selection ────────────────────────────────────────────────────────────

    selection() {
        return this.view.filter((entry) => this.selected.has(entry.path));
    }

    selectedPaths() {
        return this.selection().map((entry) => entry.path);
    }

    setSelected(index, on) {
        const entry = this.view[index];
        if (!entry) return;
        if (on) this.selected.add(entry.path); else this.selected.delete(entry.path);
        const row = this.el.rows.querySelector(`tr[data-index="${index}"]`);
        if (row) {
            row.classList.toggle('is-selected', on);
            row.querySelector('.checkbox')?.classList.toggle('is-on', on);
        }
    }

    clearSelection() {
        this.selected.clear();
        $$('tr.is-selected', this.el.rows).forEach((row) => {
            row.classList.remove('is-selected');
            row.querySelector('.checkbox')?.classList.remove('is-on');
        });
    }

    toggleAll() {
        const all = this.selected.size === this.view.length && this.view.length > 0;
        this.clearSelection();
        if (!all) this.view.forEach((_, index) => this.setSelected(index, true));
        this.updateFoot();
    }

    moveCursor(delta) {
        const next = Math.max(0, Math.min(this.view.length - 1, this.cursor + delta));
        this.setCursor(next);
    }

    setCursor(index) {
        $$('tr.is-cursor', this.el.rows).forEach((row) => row.classList.remove('is-cursor'));
        this.cursor = index;
        while (this.rendered <= index && this.rendered < this.view.length) this.renderMore();
        const row = this.el.rows.querySelector(`tr[data-index="${index}"]`);
        if (row) {
            row.classList.add('is-cursor');
            row.scrollIntoView({ block: 'nearest' });
        }
    }

    onRowClick(event) {
        const row = event.target.closest('tr[data-index]');
        if (!row) return;
        const index = Number(row.dataset.index);
        const entry = this.view[index];
        if (!entry) return;

        const onCheckbox = Boolean(event.target.closest('.checkbox'));

        if (event.shiftKey && this.cursor >= 0) {
            const [from, to] = [this.cursor, index].sort((a, b) => a - b);
            if (!event.ctrlKey && !event.metaKey) this.clearSelection();
            for (let i = from; i <= to; i++) this.setSelected(i, true);
        } else if (event.ctrlKey || event.metaKey || onCheckbox) {
            this.setSelected(index, !this.selected.has(entry.path));
        } else {
            this.clearSelection();
            this.setSelected(index, true);
        }
        this.setCursor(index);
        this.updateFoot();
    }

    onRowDouble(event) {
        const row = event.target.closest('tr[data-index]');
        if (!row) return;
        this.open(this.view[Number(row.dataset.index)]);
    }

    open(entry) {
        if (!entry) return;
        if (entry.isDir) { this.load(entry.path); return; }
        if (entry.editable) { this.edit(entry); return; }
        window.open(downloadUrl(this.siteId, [entry.path]), '_blank');
    }

    // ── actions ──────────────────────────────────────────────────────────────

    action(name) {
        const map = {
            home: () => this.load(''),
            up: () => this.load(parentOf(this.path)),
            refresh: () => this.load(this.path, { keepSelection: true }),
            mkdir: () => this.mkdir(),
            upload: () => this.el.fileInput.click(),
            download: () => this.download(),
            delete: () => this.remove(),
            hidden: () => this.toggleHidden(),
        };
        map[name]?.();
    }

    toggleHidden() {
        this.showHidden = !this.showHidden;
        const button = this.root.querySelector('[data-act="hidden"]');
        button.classList.toggle('is-on', this.showHidden);
        button.querySelector('use').setAttribute('href', this.showHidden ? '#i-eye' : '#i-eye-off');
        this.refreshView();
    }

    async mkdir() {
        const name = await prompt('New folder', 'Folder name', '', { glyph: 'folder-plus', confirmLabel: 'Create' });
        if (!name) return;
        try {
            await call('fs.mkdir', { site: this.siteId, path: this.path, name });
            toast('Folder created', name, 'ok');
            this.load(this.path);
        } catch (error) { notifyError(error); }
    }

    async rename(entry) {
        const name = await prompt('Rename', 'New name', entry.name, { confirmLabel: 'Rename' });
        if (!name || name === entry.name) return;
        try {
            await call('fs.rename', { site: this.siteId, path: entry.path, name });
            toast('Renamed', `${entry.name} → ${name}`, 'ok');
            this.load(this.path);
        } catch (error) { notifyError(error); }
    }

    async remove(entries = this.selection()) {
        if (entries.length === 0) return;
        const size = entries.reduce((sum, entry) => sum + entry.size, 0);
        const label = entries.length === 1 ? `“${entries[0].name}”` : `${entries.length} items`;
        const ok = await confirm('Delete permanently?',
            `${label} will be removed from ${findSite(this.siteId)?.name}. This cannot be undone.`,
            { danger: true, confirmLabel: 'Delete', detail: `${bytes(size)}\n${entries.slice(0, 8).map((e) => e.path).join('\n')}${entries.length > 8 ? `\n… ${entries.length - 8} more` : ''}` });
        if (!ok) return;

        try {
            const result = await call('fs.delete', { site: this.siteId, paths: entries.map((entry) => entry.path) });
            if (result.failed.length) {
                toast(`Deleted ${result.deleted}, failed ${result.failed.length}`, result.failed[0].error, 'warn', 8000);
            } else {
                toast('Deleted', `${result.deleted} item(s)`, 'ok');
            }
            this.load(this.path);
        } catch (error) { notifyError(error); }
    }

    async chmod(entries = this.selection()) {
        if (entries.length === 0) return;
        const mode = await chmodDialog(entries);
        if (!mode) return;
        try {
            const result = await call('fs.chmod', { site: this.siteId, paths: entries.map((entry) => entry.path), mode });
            toast('Permissions updated', `${result.applied} item(s) → ${mode}`, 'ok');
            this.load(this.path, { keepSelection: true });
        } catch (error) { notifyError(error); }
    }

    download(entries = this.selection()) {
        if (entries.length === 0) return;
        window.location.href = downloadUrl(this.siteId, entries.map((entry) => entry.path));
    }

    async edit(entry) {
        try {
            const file = await call('fs.read', { site: this.siteId, path: entry.path });
            if (file.binary) {
                const ok = await confirm('Binary content', 'This file does not look like text. Open it anyway?', { confirmLabel: 'Open' });
                if (!ok) return;
            }
            await editorDialog(entry, file.content, async (content) => {
                await call('fs.write', { site: this.siteId, path: entry.path, content });
                this.load(this.path, { keepSelection: true });
            });
        } catch (error) { notifyError(error); }
    }

    // ── uploads ──────────────────────────────────────────────────────────────

    async uploadFiles(files) {
        if (!files.length) return;
        const dismiss = toast('Uploading…', `${files.length} file(s)`, 'info', 0);

        for (const file of files) {
            try {
                const slice_size = uploadChunk();
                if (file.size <= slice_size) {
                    await upload({ site: this.siteId, path: this.path, name: file.name }, file);
                } else {
                    const total = Math.ceil(file.size / slice_size);
                    const uploadId = Math.random().toString(16).slice(2) + Date.now().toString(16);
                    for (let index = 0; index < total; index++) {
                        const slice = file.slice(index * slice_size, (index + 1) * slice_size);
                        await upload({
                            site: this.siteId, path: this.path, name: file.name,
                            uploadId, chunkIndex: index, chunkTotal: total,
                        }, slice);
                    }
                }
            } catch (error) {
                dismiss();
                notifyError(error);
                this.load(this.path);
                return;
            }
        }

        dismiss();
        toast('Upload complete', `${files.length} file(s) → ${this.path}`, 'ok');
        this.el.fileInput.value = '';
        this.load(this.path);
    }

    // ── drag and drop ────────────────────────────────────────────────────────

    setupDragAndDrop() {
        this.el.rows.addEventListener('dragstart', (event) => {
            const row = event.target.closest('tr[data-index]');
            if (!row) return;
            const entry = this.view[Number(row.dataset.index)];
            if (!this.selected.has(entry.path)) {
                this.clearSelection();
                this.setSelected(Number(row.dataset.index), true);
                this.updateFoot();
            }
            const paths = this.selectedPaths();
            event.dataTransfer.effectAllowed = 'copyMove';
            event.dataTransfer.setData('application/x-filebridge', JSON.stringify({ site: this.siteId, paths, base: this.path }));
            event.dataTransfer.setData('text/plain', paths.join('\n'));

            const ghost = el('div', { class: 'drag-ghost' },
                icon('copy', 'icon icon-sm'),
                `${paths.length} item${paths.length === 1 ? '' : 's'}`);
            document.body.append(ghost);
            event.dataTransfer.setDragImage(ghost, 20, 16);
            setTimeout(() => ghost.remove(), 0);
        });

        const over = (event) => {
            const external = Array.from(event.dataTransfer.types).includes('Files');
            const internal = Array.from(event.dataTransfer.types).includes('application/x-filebridge');
            if (!external && !internal) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = event.shiftKey && internal ? 'move' : 'copy';
            this.root.classList.add('is-dropping');
            const row = event.target.closest?.('tr[data-index]');
            $$('tr.is-drop-target', this.el.rows).forEach((node) => node.classList.remove('is-drop-target'));
            if (row && this.view[Number(row.dataset.index)]?.isDir) row.classList.add('is-drop-target');
        };

        this.root.addEventListener('dragover', over);
        this.root.addEventListener('dragenter', over);
        this.root.addEventListener('dragleave', (event) => {
            if (!this.root.contains(event.relatedTarget)) this.root.classList.remove('is-dropping');
        });
        this.root.addEventListener('drop', (event) => {
            event.preventDefault();
            this.root.classList.remove('is-dropping');
            $$('tr.is-drop-target', this.el.rows).forEach((node) => node.classList.remove('is-drop-target'));

            const row = event.target.closest?.('tr[data-index]');
            const hovered = row ? this.view[Number(row.dataset.index)] : null;
            const destination = hovered?.isDir ? hovered.path : this.path;

            if (event.dataTransfer.files?.length) {
                this.uploadFiles(Array.from(event.dataTransfer.files));
                return;
            }
            const raw = event.dataTransfer.getData('application/x-filebridge');
            if (!raw) return;
            const payload = JSON.parse(raw);
            if (payload.site === this.siteId && payload.base === destination) return;
            this.ctx.transfer({
                sourceSite: payload.site,
                sourceBase: payload.base,
                paths: payload.paths,
                targetSite: this.siteId,
                targetPath: destination,
                mode: event.shiftKey ? 'move' : 'copy',
            });
        });
    }

    // ── context menu ─────────────────────────────────────────────────────────

    onRowMenu(event) {
        const row = event.target.closest('tr[data-index]');
        event.preventDefault();
        this.focus();

        if (!row) { this.folderMenu(event); return; }
        const index = Number(row.dataset.index);
        const entry = this.view[index];
        if (!this.selected.has(entry.path)) {
            this.clearSelection();
            this.setSelected(index, true);
            this.setCursor(index);
            this.updateFoot();
        }

        const chosen = this.selection();
        const many = chosen.length > 1;
        const other = this.ctx.getOther(this);

        contextMenu(event.clientX, event.clientY, [
            { label: many ? `${chosen.length} items selected` : entry.name, header: true },
            { label: entry.isDir ? 'Open' : (entry.editable ? 'Edit' : 'Download'), icon: entry.isDir ? 'folder-open' : (entry.editable ? 'pencil' : 'download'), disabled: many, onSelect: () => this.open(entry) },
            { label: `Copy to ${other?.siteLabel() || 'other panel'}`, icon: this.side === 'left' ? 'arrow-right' : 'arrow-left', onSelect: () => this.ctx.copyToOther(this, 'copy'), key: 'F5' },
            { label: 'Move to the other panel', icon: 'swap', onSelect: () => this.ctx.copyToOther(this, 'move'), key: 'F6' },
            'sep',
            { label: 'Download', icon: 'download', onSelect: () => this.download(), key: 'Ctrl+D' },
            { label: 'Rename', icon: 'pencil', disabled: many, onSelect: () => this.rename(entry), key: 'F2' },
            { label: 'Permissions', icon: 'lock', disabled: this.capabilities.chmod === false, onSelect: () => this.chmod(), },
            { label: 'Copy path', icon: 'copy', onSelect: () => this.copyPaths() },
            { label: 'Properties', icon: 'info', disabled: many, onSelect: () => propertiesDialog(entry, findSite(this.siteId)?.name || '') },
            'sep',
            { label: 'Delete', icon: 'trash', danger: true, onSelect: () => this.remove(), key: 'Del' },
        ]);
    }

    folderMenu(event) {
        contextMenu(event.clientX, event.clientY, [
            { label: this.path, header: true },
            { label: 'New folder', icon: 'folder-plus', onSelect: () => this.mkdir(), key: 'F7' },
            { label: 'Upload files', icon: 'upload', onSelect: () => this.el.fileInput.click() },
            { label: 'Refresh', icon: 'refresh', onSelect: () => this.load(this.path), key: 'Ctrl+R' },
            'sep',
            { label: 'Select all', icon: 'check', onSelect: () => this.toggleAll(), key: 'Ctrl+A' },
            { label: this.showHidden ? 'Hide hidden files' : 'Show hidden files', icon: this.showHidden ? 'eye-off' : 'eye', onSelect: () => this.toggleHidden(), key: 'Ctrl+H' },
            { label: 'Copy current path', icon: 'copy', onSelect: () => this.copyPaths([{ path: this.path }]) },
        ]);
    }

    async copyPaths(entries = this.selection()) {
        const text = entries.map((entry) => entry.path).join('\n');
        try {
            await navigator.clipboard.writeText(text);
            toast('Copied to clipboard', text.split('\n')[0], 'ok', 2200);
        } catch {
            toast('Clipboard blocked', text, 'warn', 6000);
        }
    }

    siteLabel() {
        return findSite(this.siteId)?.name || this.siteId;
    }
}
