/**
 * Draws the proposals page funding flow: donor → proposal ribbons sized by
 * agreement budget, and proposal → project threads.
 *
 * The nodes are server-rendered HTML (real links and buttons); this component
 * only measures them and paints the SVG between the columns, so text stays
 * crisp and the flow works in RTL, where the columns mirror. It also owns the
 * trace highlight shared with the dossier rows through the `proposalFlow`
 * store: focusing any node lights its whole donor → proposal → project chain.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.store('proposalFlow', {
        focus: null,
        related: [],

        isLit(key) {
            return this.focus !== null && (this.focus === key || this.related.includes(key));
        },

        isDimmed(key) {
            return this.focus !== null && this.focus !== key && !this.related.includes(key);
        },

        /**
         * Dossier rows only fade while a donor or project is traced; hovering one
         * row must not grey out the list the pointer is travelling through.
         */
        isRowDimmed(key) {
            return this.focus !== null && !this.focus.startsWith('p') && !this.related.includes(key);
        },
    });

    window.Alpine.data('fundingFlow', (graph) => ({
        observer: null,

        init() {
            this.$watch('$store.proposalFlow.focus', (key) => {
                // Only the flow that owns the focused node answers, so a second currency's flow stays quiet.
                if (key !== null && !graph.order.includes(key)) {
                    return;
                }

                this.$store.proposalFlow.related = key === null ? [] : this.chainOf(key);
                this.paint();
            });

            this.observer = new ResizeObserver(() => this.draw());
            this.observer.observe(this.$el);
            this.$nextTick(() => this.draw());
        },

        destroy() {
            this.observer?.disconnect();

            // A page swap must not leave a highlight pointing at nodes that no longer exist.
            this.$store.proposalFlow.focus = null;
        },

        /**
         * Every node reachable from the focused one, in both directions.
         */
        chainOf(key) {
            const edges = [...graph.money.map((m) => [m.from, m.to]), ...graph.links.map((l) => [l.from, l.to])];
            const seen = new Set([key]);

            // Walk downstream from donors and upstream from projects, never sideways:
            // tracing a donor must not light a second donor that shares one of its proposals.
            const walk = (start, direction) => {
                const queue = [start];

                while (queue.length > 0) {
                    const current = queue.shift();

                    edges.forEach(([from, to]) => {
                        const [here, there] = direction === 'down' ? [from, to] : [to, from];

                        if (here === current && !seen.has(there)) {
                            seen.add(there);
                            queue.push(there);
                        }
                    });
                }
            };

            walk(key, 'down');
            walk(key, 'up');

            return [...seen];
        },

        rectOf(selector) {
            const element = this.$el.querySelector(selector);

            return element === null ? null : element.getBoundingClientRect();
        },

        draw() {
            const frame = this.$el.getBoundingClientRect();

            if (frame.width === 0) {
                return;
            }

            const local = (rect) => ({
                left: rect.left - frame.left,
                right: rect.right - frame.left,
                top: rect.top - frame.top,
                bottom: rect.bottom - frame.top,
                height: rect.height,
            });

            // Split each node's bar into one band per link, in node order, so bands stack without overlap.
            const cursor = {};
            const band = (key, amount, total, rect) => {
                const start = cursor[key] ?? rect.top;
                const size = total > 0 ? (amount / total) * rect.height : 0;
                cursor[key] = start + size;

                return [start, start + size];
            };

            const totals = {};
            graph.money.forEach((m) => {
                totals[m.from] = (totals[m.from] ?? 0) + m.amount;
                totals[m.to] = (totals[m.to] ?? 0) + m.amount;
            });

            const order = (key) => graph.order.indexOf(key);
            const money = [...graph.money].sort((a, b) => order(a.from) - order(b.from) || order(a.to) - order(b.to));

            const ribbons = money
                .map((m) => {
                    const sourceRect = this.rectOf(`[data-flow-out="${m.from}"]`);
                    const targetRect = this.rectOf(`[data-flow-in="${m.to}"]`);

                    if (sourceRect === null || targetRect === null) {
                        return null;
                    }

                    return { m, source: local(sourceRect), target: local(targetRect) };
                })
                .filter(Boolean)
                // Bands on the proposal side stack in donor order, bands on the donor side in proposal order.
                .map(({ m, source, target }) => ({ m, source, target, sourceBand: band(`out-${m.from}`, m.amount, totals[m.from], source) }))
                .sort((a, b) => order(a.m.to) - order(b.m.to) || order(a.m.from) - order(b.m.from))
                .map(({ m, source, target, sourceBand }) => {
                    const targetBand = band(`in-${m.to}`, m.amount, totals[m.to], target);
                    const forward = source.right <= target.left;
                    const x0 = forward ? source.right : source.left;
                    const x1 = forward ? target.left : target.right;
                    const mid = (x0 + x1) / 2;
                    const [a0, b0] = sourceBand;
                    const [a1, b1] = targetBand;

                    return {
                        id: `${m.from}-${m.to}`,
                        keys: [m.from, m.to],
                        path: `M${x0},${a0} C${mid},${a0} ${mid},${a1} ${x1},${a1} L${x1},${b1} C${mid},${b1} ${mid},${b0} ${x0},${b0} Z`,
                    };
                });

            const threads = graph.links
                .map((l) => {
                    const sourceRect = this.rectOf(`[data-flow-out="${l.from}"]`);
                    const targetRect = this.rectOf(`[data-flow-in="${l.to}"]`);

                    if (sourceRect === null || targetRect === null) {
                        return null;
                    }

                    const source = local(sourceRect);
                    const target = local(targetRect);
                    const forward = source.right <= target.left;
                    const x0 = forward ? source.right : source.left;
                    const x1 = forward ? target.left : target.right;
                    const y0 = source.top + source.height / 2;
                    const y1 = target.top + target.height / 2;
                    const mid = (x0 + x1) / 2;

                    return {
                        id: `${l.from}-${l.to}`,
                        keys: [l.from, l.to],
                        path: `M${x0},${y0} C${mid},${y0} ${mid},${y1} ${x1},${y1}`,
                    };
                })
                .filter(Boolean);

            // Paths are built by hand: a <template x-for> inside <svg> is parsed as an SVG node and never renders.
            const layer = this.$refs.paths;
            const namespace = 'http://www.w3.org/2000/svg';
            layer.replaceChildren(
                ...[...ribbons.map((shape) => ({ ...shape, kind: 'flow-ribbon' })), ...threads.map((shape) => ({ ...shape, kind: 'flow-thread' }))].map((shape) => {
                    const path = document.createElementNS(namespace, 'path');
                    path.setAttribute('d', shape.path);
                    path.setAttribute('class', shape.kind);
                    path.dataset.keys = shape.keys.join(' ');

                    return path;
                }),
            );

            this.paint();
        },

        paint() {
            this.$refs.paths?.querySelectorAll('path').forEach((path) => {
                path.dataset.state = this.pathState(path.dataset.keys.split(' '));
            });
        },

        pathState(keys) {
            const store = this.$store.proposalFlow;

            if (store.focus === null) {
                return 'rest';
            }

            return keys.every((key) => store.related.includes(key)) ? 'lit' : 'dim';
        },
    }));
});
