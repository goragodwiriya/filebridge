// Transfer queue drawer: enqueue, poll, render, cancel, retry.

import { call } from './api.js';
import { el, icon, $, bytes, speed, duration, when, toast, notifyError, modal } from './ui.js';
import { transferDialog } from './dialogs.js';

const ACTIVE = new Set(['queued', 'scanning', 'running']);

const STATUS = {
    queued:    { glyph: 'clock',   label: 'Queued' },
    scanning:  { glyph: 'search',  label: 'Scanning' },
    running:   { glyph: 'loader',  label: 'Transferring' },
    done:      { glyph: 'check',   label: 'Completed' },
    error:     { glyph: 'alert',   label: 'Failed' },
    cancelled: { glyph: 'stop',    label: 'Cancelled' },
};

export class Queue {
    constructor(onFinished) {
        this.jobs = [];
        this.timer = null;
        this.onFinished = onFinished;
        this.seenFinished = new Set();
        this.nudged = new Set();

        this.root = $('#queue');
        this.body = $('#queue-body');
        this.count = $('#queue-count');
        this.summary = $('#queue-summary');
        this.chip = $('#btn-queue');

        $('#queue-head').addEventListener('click', (event) => {
            if (event.target.closest('#btn-clear-queue')) return;
            this.toggle();
        });
        this.chip.addEventListener('click', () => this.toggle(true));
        $('#btn-clear-queue').addEventListener('click', async (event) => {
            event.stopPropagation();
            try {
                await call('transfer.clear');
                this.refresh();
            } catch (error) { notifyError(error); }
        });
    }

    toggle(force) {
        const open = force === true ? true : !this.root.classList.contains('is-open');
        this.root.classList.toggle('is-open', open);
        this.body.hidden = !open;
        this.chip.classList.toggle('is-active', open);
        if (open) this.refresh();
    }

    /** Ask for options, then queue the job. */
    async start(payload, context) {
        const options = await transferDialog({
            mode: payload.mode,
            count: payload.paths.length,
            from: context.from,
            to: context.to,
            targetPath: payload.targetPath,
        });
        if (!options) return;

        try {
            const data = await call('transfer.enqueue', { ...payload, conflict: options.conflict });
            this.toggle(true);
            this.poll(true);
            toast('Transfer queued', `${payload.paths.length} item(s) → ${payload.targetPath}`, 'ok', 2600);
            // No detached worker on this host: drive the job from the browser instead.
            if (data.job && data.job.spawned === false) this.nudge(data.job.id);
        } catch (error) { notifyError(error); }
    }

    /** Fallback runner for hosts where the worker could not be detached. */
    nudge(id) {
        if (this.nudged.has(id)) return;
        this.nudged.add(id);
        call('transfer.run', { id }).catch(() => { /* progress is polled anyway */ });
    }

    async refresh() {
        try {
            const data = await call('transfer.list');
            this.jobs = data.jobs;
            this.render();
        } catch (error) {
            if (error.code !== 'unauthenticated') notifyError(error);
        }
    }

    /** Poll fast while something is moving, slowly when idle. */
    poll(immediate = false) {
        clearTimeout(this.timer);
        const tick = async () => {
            await this.refresh();
            const busy = this.jobs.some((job) => ACTIVE.has(job.status));
            this.timer = setTimeout(tick, busy ? 800 : 5000);
        };
        this.timer = setTimeout(tick, immediate ? 60 : 800);
    }

    render() {
        const active = this.jobs.filter((job) => ACTIVE.has(job.status));

        this.count.hidden = active.length === 0;
        this.count.textContent = String(active.length);

        if (active.length) {
            const totalDone = active.reduce((sum, job) => sum + job.bytesDone, 0);
            const totalAll = active.reduce((sum, job) => sum + job.bytesTotal, 0);
            const rate = active.reduce((sum, job) => sum + job.speed, 0);
            this.summary.className = 'badge badge-accent';
            this.summary.textContent = `${active.length} running · ${bytes(totalDone)} / ${bytes(totalAll)} · ${speed(rate)}`;
        } else {
            this.summary.className = 'badge';
            this.summary.textContent = this.jobs.length ? `${this.jobs.length} in history` : 'idle';
        }

        // A job still queued long after it was created means no worker picked
        // it up - run it over HTTP instead of leaving it stuck.
        const now = Date.now() / 1000;
        for (const job of this.jobs) {
            if (job.status === 'queued' && now - job.createdAt > 8) this.nudge(job.id);
        }

        // Tell the app when a job wraps up so open panels can refresh.
        for (const job of this.jobs) {
            if (!ACTIVE.has(job.status) && !this.seenFinished.has(job.id)) {
                this.seenFinished.add(job.id);
                if (job.startedAt) this.onFinished?.(job);
            }
        }

        if (this.body.hidden) return;

        if (this.jobs.length === 0) {
            this.body.replaceChildren(el('div', { class: 'state' },
                el('span', { class: 'glyph' }, icon('inbox', 'icon icon-lg')),
                el('h3', { text: 'No transfers yet' }),
                el('p', { text: 'Select files and press F5, or drag them onto the other panel.' })));
            return;
        }

        this.body.replaceChildren(...this.jobs.map((job) => this.card(job)));
    }

    card(job) {
        const meta = STATUS[job.status] || STATUS.queued;
        const running = ACTIVE.has(job.status);
        const percent = job.percent || 0;

        const actions = el('div', { class: 'actions' });
        if (running) {
            actions.append(el('button', {
                class: 'icon-btn', title: 'Cancel',
                onclick: async () => {
                    try { await call('transfer.cancel', { id: job.id }); this.refresh(); } catch (error) { notifyError(error); }
                },
            }, icon('stop', 'icon icon-sm')));
        } else {
            actions.append(el('button', {
                class: 'icon-btn', title: 'Run again',
                onclick: async () => {
                    try { await call('transfer.retry', { id: job.id }); this.poll(true); } catch (error) { notifyError(error); }
                },
            }, icon('rotate', 'icon icon-sm')));
        }
        actions.append(el('button', {
            class: 'icon-btn', title: 'Details',
            onclick: () => this.details(job),
        }, icon('list', 'icon icon-sm')));

        const line2 = el('div', { class: 'line2' });
        if (running) {
            line2.append(
                el('span', { class: 'current', text: job.current || 'preparing…' }),
                el('span', { class: 'mono', text: `${bytes(job.bytesDone)} / ${bytes(job.bytesTotal)}` }),
                el('span', { class: 'mono', text: speed(job.speed) }),
                el('span', { class: 'mono', text: job.eta ? `ETA ${duration(job.eta)}` : '' }),
            );
        } else {
            line2.append(
                el('span', { class: 'current', text: job.error || `${job.filesDone} file(s) · ${bytes(job.bytesDone)}${job.filesSkipped ? ` · ${job.filesSkipped} skipped` : ''}` }),
                el('span', { class: 'mono', text: when(job.finishedAt || job.createdAt) }),
            );
        }

        return el('div', { class: `job is-${job.status}` },
            el('span', { class: 'glyph' }, icon(meta.glyph, `icon icon-sm${job.status === 'running' ? ' spin' : ''}`)),
            el('div', { class: 'line1' },
                el('span', { class: 'title', text: job.title }),
                el('span', { class: 'route' },
                    el('span', { text: job.sourceSiteName }),
                    icon(job.mode === 'move' ? 'swap' : 'arrow-right', 'icon icon-sm'),
                    el('span', { text: job.targetSiteName })),
                el('span', { class: `badge ${job.status === 'error' ? 'badge-danger' : job.status === 'done' ? 'badge-ok' : job.status === 'cancelled' ? 'badge-warn' : 'badge-accent'}`, text: meta.label }),
                el('span', { class: 'muted mono', text: `${percent}%` })),
            actions,
            el('div', { class: `bar${running ? ' is-busy' : ''}${job.status === 'error' ? ' bar-danger' : job.status === 'done' ? ' bar-ok' : job.status === 'cancelled' ? ' bar-warn' : ''}` },
                el('i', { style: { width: `${Math.max(percent, running ? 2 : 100)}%` } })),
            el('div', { class: 'line2-wrap', style: { gridColumn: '2 / span 2' } }, line2));
    }

    details(job) {
        return modal((close) => ({
            title: `Transfer ${job.id}`,
            sub: `${job.sourceSiteName} → ${job.targetSiteName}`,
            glyph: 'list',
            wide: true,
            body: [
                el('div', { class: 'grid-2' },
                    el('div', { class: 'field' }, el('label', { text: 'Source' }), el('div', { class: 'mono', text: job.sourcePaths.join('\n') })),
                    el('div', { class: 'field' }, el('label', { text: 'Destination' }), el('div', { class: 'mono', text: job.targetPath }))),
                el('div', { class: 'grid-3' },
                    el('div', { class: 'field' }, el('label', { text: 'Files' }), el('div', { class: 'mono', text: `${job.filesDone} / ${job.filesTotal}` })),
                    el('div', { class: 'field' }, el('label', { text: 'Bytes' }), el('div', { class: 'mono', text: `${bytes(job.bytesDone)} / ${bytes(job.bytesTotal)}` })),
                    el('div', { class: 'field' }, el('label', { text: 'Mode' }), el('div', { class: 'mono', text: `${job.mode} · ${job.conflict}` }))),
                el('div', { class: 'field' },
                    el('label', { text: 'Log' }),
                    el('div', {
                        class: 'mono',
                        style: { maxHeight: '260px', overflow: 'auto', background: 'var(--surface-2)', padding: '10px', borderRadius: 'var(--radius-sm)', whiteSpace: 'pre-wrap' },
                        text: job.log.map((line) => `${new Date(line.time * 1000).toLocaleTimeString()}  [${line.level}] ${line.message}`).join('\n') || 'No entries yet.',
                    })),
            ],
            foot: [el('button', { class: 'btn btn-primary', onclick: () => close(undefined) }, 'Close')],
        }));
    }
}
