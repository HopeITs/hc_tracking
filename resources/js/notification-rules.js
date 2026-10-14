/**
 * The notification rules page: a live draft of every rule, held in the
 * Livewire component's own properties through $wire (no request until Save),
 * plus what is drawn from it — the late-item steps, the unsaved-change count
 * and the "use default" links.
 *
 * Bounds mirror the server rules in pages/admin/⚡notification-settings; the
 * server still validates everything on Save.
 */

const NUMBER_BOUNDS = {
    overdueRepeat: [1, 30],
    escalateManagerAfter: [1, 60],
    escalateTopAfter: [2, 90],
    escalationCatchUp: [1, 30],
    stalledTaskInProgress: [1, 365],
    stalledTaskUnderReview: [1, 365],
    stalledReportDraft: [1, 365],
    stalledReportReviewed: [1, 365],
    stalledProposal: [1, 365],
    offTrackPace: [1, 100],
};

const KEYS = ['workingDays', 'dueSoonDays', ...Object.keys(NUMBER_BOUNDS), 'dailyBriefTime', 'weeklyDigestTime', 'events'];

const MAX_WARNINGS = 6;

const parseDays = (value) =>
    [...new Set(String(value ?? '')
        .split(',')
        .map((part) => Number.parseInt(part.trim(), 10))
        .filter((days) => Number.isInteger(days) && days > 0))]
        .sort((a, b) => b - a);

const parseWeekdays = (value) =>
    [...new Set((Array.isArray(value) ? value : []).map(Number).filter((day) => day >= 1 && day <= 7))].sort((a, b) => a - b);

/** Event rows compare by what the server stores: enabled, importance and the sorted roles. */
const normalEvents = (rows) =>
    JSON.stringify((Array.isArray(rows) ? rows : []).map((row) => [
        row.key,
        Boolean(row.enabled),
        row.importance,
        [...(row.oversight_roles ?? [])].sort(),
    ]));

const clone = (value) => (Array.isArray(value) ? JSON.parse(JSON.stringify(value)) : value);

document.addEventListener('alpine:init', () => {
    window.Alpine.data('notificationRules', ({ defaults: ruleDefaults, eventDefaults, strings, plurals, weekdays }) => {
        const defaults = { ...ruleDefaults, events: eventDefaults };

        return {
            saved: {},
            weekNotice: false,
            weekShake: false,
            newWarning: '',
            warningError: false,
            saving: false,

            init() {
                this.takeSnapshot();

                this.$wire.$on('notification-rules-saved', () => this.takeSnapshot());

                this.onBeforeUnload = (event) => {
                    if (this.dirtyCount > 0) {
                        event.preventDefault();
                        event.returnValue = '';
                    }
                };

                this.onNavigate = (event) => {
                    if (this.dirtyCount > 0 && !window.confirm(strings.leaveConfirm)) {
                        event.preventDefault();
                    }
                };

                window.addEventListener('beforeunload', this.onBeforeUnload);
                document.addEventListener('livewire:navigate', this.onNavigate);
            },

            destroy() {
                window.removeEventListener('beforeunload', this.onBeforeUnload);
                document.removeEventListener('livewire:navigate', this.onNavigate);
            },

            /* ---- Draft, snapshot and defaults ---------------------------------- */

            normal(key, value) {
                if (key === 'workingDays') {
                    return parseWeekdays(value).join(',');
                }

                if (key === 'dueSoonDays') {
                    return parseDays(value).join(',');
                }

                if (key === 'events') {
                    return normalEvents(value);
                }

                if (key in NUMBER_BOUNDS) {
                    return String(Number(value));
                }

                return String(value ?? '');
            },

            takeSnapshot() {
                this.saved = Object.fromEntries(KEYS.map((key) => [key, clone(this.$wire[key])]));
            },

            isUnsaved(key) {
                return this.normal(key, this.$wire[key]) !== this.normal(key, this.saved[key]);
            },

            isCustom(key) {
                return this.normal(key, this.$wire[key]) !== this.normal(key, defaults[key]);
            },

            /** Each changed event row counts as one change, like each changed rule. */
            get dirtyCount() {
                const savedEvents = new Map((this.saved.events ?? []).map((row) => [row.key, normalEvents([row])]));
                const changedEvents = (this.$wire.events ?? []).filter((row) => normalEvents([row]) !== savedEvents.get(row.key)).length;

                return KEYS.filter((key) => key !== 'events' && this.isUnsaved(key)).length + changedEvents;
            },

            get dirtyLabel() {
                return this.plural('unsaved', this.dirtyCount);
            },

            defaultLabel(key) {
                const value = defaults[key];

                if (key === 'dueSoonDays') {
                    return parseDays(value).join(', ');
                }

                if (key === 'offTrackPace') {
                    return `${value}%`;
                }

                return String(value);
            },

            useDefault(key) {
                this.$wire[key] = clone(defaults[key]);
            },

            discard() {
                KEYS.forEach((key) => {
                    this.$wire[key] = clone(this.saved[key]);
                });

                this.$wire.forgetErrors();
            },

            save() {
                this.saving = true;

                this.$wire.save().finally(() => {
                    this.saving = false;

                    this.$nextTick(() => {
                        this.$root.querySelector('[data-rule-error]')?.closest('[data-rule-field]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                });
            },

            /* ---- Numbers --------------------------------------------------------- */

            num(key) {
                return Number(this.$wire[key]) || 0;
            },

            bounds(key) {
                const [min, max] = NUMBER_BOUNDS[key];

                if (key === 'escalateManagerAfter') {
                    return [min, Math.min(max, this.num('escalateTopAfter') - 1)];
                }

                if (key === 'escalateTopAfter') {
                    return [Math.max(min, this.num('escalateManagerAfter') + 1), max];
                }

                return [min, max];
            },

            canStep(key, delta) {
                const [min, max] = this.bounds(key);
                const next = this.num(key) + delta;

                return next >= min && next <= max;
            },

            step(key, delta) {
                if (this.canStep(key, delta)) {
                    this.$wire[key] = this.num(key) + delta;
                }
            },

            /** Typing: follow along only while the number is already acceptable. */
            typeNumber(key, event) {
                const value = Number.parseInt(event.target.value, 10);
                const [min, max] = this.bounds(key);

                if (Number.isInteger(value) && value >= min && value <= max) {
                    this.$wire[key] = value;
                }
            },

            /** Leaving the field: settle on the nearest acceptable number. */
            commitNumber(key, event) {
                const value = Number.parseInt(event.target.value, 10);
                const [min, max] = this.bounds(key);
                const settled = Number.isInteger(value) ? Math.min(max, Math.max(min, value)) : this.num(key);

                this.$wire[key] = settled;
                event.target.value = settled;
            },

            /* ---- Working week ----------------------------------------------------- */

            get workingDays() {
                return parseWeekdays(this.$wire.workingDays);
            },

            isWorking(day) {
                return this.workingDays.includes(day);
            },

            /** The last working day cannot be switched off: the row shakes and the note stays until another day is chosen. */
            guardLastDay(event, day) {
                if (this.isWorking(day) && this.workingDays.length === 1) {
                    event.preventDefault();
                    this.weekNotice = true;
                    this.weekShake = false;
                    this.$nextTick(() => {
                        this.weekShake = true;
                    });

                    return;
                }

                this.weekNotice = false;
            },

            get workingCountLabel() {
                return `${this.workingDays.length} ${this.plural('workingDays', this.workingDays.length)}`;
            },

            get weeklyDigestHint() {
                return this.fill(strings.weeklyDigestHint, { day: weekdays[(this.workingDays[0] ?? 1) - 1] });
            },

            /* ---- Due-soon warnings ------------------------------------------------ */

            get warnings() {
                return parseDays(this.$wire.dueSoonDays);
            },

            get canAddWarning() {
                return this.warnings.length < MAX_WARNINGS;
            },

            addWarning() {
                const days = Number(this.newWarning);

                if (!Number.isInteger(days) || days < 1 || days > 99 || this.warnings.includes(days) || !this.canAddWarning) {
                    this.warningError = true;

                    return;
                }

                this.$wire.dueSoonDays = [...this.warnings, days].sort((a, b) => b - a).join(', ');
                this.newWarning = '';
                this.warningError = false;
            },

            removeWarning(days) {
                if (this.warnings.length > 1) {
                    this.$wire.dueSoonDays = this.warnings.filter((value) => value !== days).join(', ');
                }
            },

            /* ---- What happens to a late item -------------------------------------- */

            /** @returns {{ key: string, kind: string, label: string }[]} */
            get flow() {
                return [
                    ...this.warnings.map((days) => ({ key: `warn-${days}`, kind: 'warn', label: this.plural('before', days) })),
                    { key: 'due', kind: 'due', label: strings.due },
                    { key: 'remind', kind: 'remind', label: this.plural('remind', this.num('overdueRepeat')) },
                    { key: 'pm', kind: 'escalate', label: this.fill(strings.manager, { day: this.num('escalateManagerAfter') }) },
                    { key: 'top', kind: 'escalate', label: this.fill(strings.top, { day: this.num('escalateTopAfter') }) },
                ];
            },

            /* ---- Copy helpers ------------------------------------------------------ */

            fill(template, replacements) {
                return Object.entries(replacements).reduce((text, [name, value]) => text.replaceAll(`:${name}`, String(value)), template);
            },

            /**
             * The server resolves each plural for 1, 2, 3 and 11 — one count from
             * every Arabic range ({1}, {2}, [3,10], [11,*]); English uses 1 and 2+.
             */
            plural(name, count) {
                const bucket = count <= 2 ? count : count <= 10 ? 3 : 11;

                return this.fill(plurals[name]?.[Math.max(1, bucket)] ?? '', { count });
            },
        };
    });
});
