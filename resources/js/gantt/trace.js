/**
 * Route tracing for the Gantt engine: given any row, find everything it is
 * connected to — the way MS Project's Task Path highlights a task's drivers
 * and dependents, stretched across the whole portfolio and its money.
 *
 * Roles a traced row can take:
 * - self:    the selected row;
 * - part:    a row inside it (its WBS descendants);
 * - up:      a predecessor, directly or further up the chain of links;
 * - down:    a successor, directly or further down;
 * - money:   a funding relation within two hops (the proposal that funds the
 *            project, that proposal's transfers and donor reports, the other
 *            projects it funds);
 * - context: an ancestor of any traced row, kept readable for orientation.
 */

/** A stable key for a dependency link. */
export function linkKey(link) {
    return `${link.from}>${link.to}`;
}

/**
 * Index the rows, links and relations once so every trace is a walk, not a scan.
 */
export function buildGraph(rows, links, relations) {
    const byId = {};
    const children = {};
    const incoming = {};
    const outgoing = {};
    const related = {};

    for (const row of rows) {
        byId[row.id] = row;
    }

    for (const row of rows) {
        if (row.parent && byId[row.parent]) {
            (children[row.parent] ??= []).push(row.id);
        }
    }

    for (const link of links) {
        (incoming[link.to] ??= []).push(link);
        (outgoing[link.from] ??= []).push(link);
    }

    relations.forEach((relation, index) => {
        (related[relation.from] ??= []).push({ id: relation.to, index, type: relation.type });
        (related[relation.to] ??= []).push({ id: relation.from, index, type: relation.type });
    });

    return { byId, children, incoming, outgoing, related };
}

/** The row's ancestors, outermost first. */
export function ancestorsOf(graph, id) {
    const chain = [];

    for (let parent = graph.byId[graph.byId[id]?.parent]; parent; parent = graph.byId[parent.parent]) {
        chain.unshift(parent);
    }

    return chain;
}

/** Every row id nested under the row, depth first. */
export function descendantsOf(graph, id) {
    const found = [];
    const stack = [...(graph.children[id] ?? [])];

    while (stack.length > 0) {
        const current = stack.pop();

        found.push(current);
        stack.push(...(graph.children[current] ?? []));
    }

    return found;
}

/**
 * @returns {{roles: Map<string, string>, links: Set<string>, relations: Set<number>}}
 */
export function traceRoute(graph, id) {
    const roles = new Map();
    const links = new Set();
    const relations = new Set();

    if (!graph.byId[id]) {
        return { roles, links, relations };
    }

    const mark = (rowId, role) => {
        if (!roles.has(rowId)) {
            roles.set(rowId, role);
        }
    };

    mark(id, 'self');

    const parts = descendantsOf(graph, id);

    parts.forEach((rowId) => mark(rowId, 'part'));

    // A summary's route is the route of everything inside it.
    const members = [id, ...parts];
    const walk = (edges, next, role) => {
        const queue = [...members];
        const seen = new Set(members);

        while (queue.length > 0) {
            const current = queue.shift();

            for (const link of edges[current] ?? []) {
                links.add(linkKey(link));

                const other = next(link);

                if (!seen.has(other)) {
                    seen.add(other);
                    mark(other, role);
                    queue.push(other);
                }
            }
        }
    };

    walk(graph.incoming, (link) => link.from, 'up');
    walk(graph.outgoing, (link) => link.to, 'down');

    // Money travels two hops from the row, its parts and the project it sits in.
    const seeds = [...members, ...ancestorsOf(graph, id).map((row) => row.id)];
    const seen = new Set(seeds);
    let frontier = seeds;

    for (let hop = 0; hop < 2; hop++) {
        const next = [];

        for (const current of frontier) {
            for (const edge of graph.related[current] ?? []) {
                relations.add(edge.index);

                if (!seen.has(edge.id)) {
                    seen.add(edge.id);
                    mark(edge.id, 'money');
                    next.push(edge.id);
                }
            }
        }

        frontier = next;
    }

    for (const rowId of [...roles.keys()]) {
        for (const ancestor of ancestorsOf(graph, rowId)) {
            mark(ancestor.id, 'context');
        }
    }

    return { roles, links, relations };
}
