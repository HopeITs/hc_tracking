/**
 * The Ask bar's instant layer: everything it can answer without the server,
 * searched on every key in well under a millisecond. The index is built by
 * App\Support\Desk\AskCommands (only what the viewer may open, take or
 * place) and shipped once with the page; the server still answers questions
 * ("what is late?") and finds records by name (components/desk/⚡ask).
 *
 * It reads what was typed the way App\Support\Desk\AskEngine does, in English
 * or Arabic: the same normalisation (letter variants, diacritics, «ال»), typo
 * allowance (one edit from four letters, two from seven), and the same
 * wrong-keyboard-layout reading (App\Support\KeyboardLayout). On top, a verb
 * turns a name into a command:
 *   "add tasks to desk" / "pin evidence types"     → put it on My desk
 *   "remove matrix from desk"                       → take it off
 *   "new task" / "create donor" / "add task"        → open that form
 *   "open projects" / "projects"                    → open the page
 *   "switch to …" / "close tabs" / "close all tabs" → Desk tabs
 *   "dark", "arabic", "customize", "tour", "layout money" → commands
 * What the viewer picks is remembered (frecency) and comes first next time.
 *
 * Pure functions only: desk-ask.js draws the rows and runs them.
 */

const latinToArabic = {
    q: 'ض', w: 'ص', e: 'ث', r: 'ق', t: 'ف', y: 'غ', u: 'ع', i: 'ه', o: 'خ', p: 'ح', '[': 'ج', ']': 'د',
    a: 'ش', s: 'س', d: 'ي', f: 'ب', g: 'ل', h: 'ا', j: 'ت', k: 'ن', l: 'م', ';': 'ك', "'": 'ط',
    z: 'ئ', x: 'ء', c: 'ؤ', v: 'ر', b: 'لا', n: 'ى', m: 'ة', ',': 'و', '.': 'ز', '/': 'ظ', '`': 'ذ',
};

const arabicToLatin = Object.fromEntries(Object.entries(latinToArabic).map(([key, letter]) => [letter, key]));

const hasArabic = (text) => /\p{Script=Arabic}/u.test(text);
const hasLatin = (text) => /[a-z]/i.test(text);

/** Lower case, without diacritics, tatweel or letter variants, «ال» dropped from long words: AskEngine::normalize. */
export function normalize(text) {
    let value = String(text ?? '').toLowerCase().trim();

    value = value.replace(/[ً-ٰٟـ]/g, '');
    value = value.replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي').replace(/[’']/g, '');
    value = value.replace(/[^\p{L}\p{N}]+/gu, ' ');

    return value.split(' ').filter(Boolean).map((word) => ([...word].length > 4 && word.startsWith('ال') ? word.slice(2) : word)).join(' ');
}

/** The text as typed on the other layout, word by word: KeyboardLayout::candidates. */
export function otherLayouts(text) {
    const words = String(text ?? '').trim().split(/\s+/).filter(Boolean);
    const toLatin = (word) => {
        let out = '';

        for (let i = 0; i < word.length; i++) {
            if (word[i] === 'ل' && word[i + 1] === 'ا') {
                out += 'b';
                i++;
            } else {
                out += arabicToLatin[word[i]] ?? word[i];
            }
        }

        return out;
    };
    const toArabic = (word) => [...word.toLowerCase()].map((char) => latinToArabic[char] ?? char).join('');
    const latin = words.map((word) => (hasArabic(word) ? toLatin(word) : word)).join(' ');
    const arabic = words.map((word) => (! hasArabic(word) && hasLatin(word) ? toArabic(word) : word)).join(' ');

    return [...new Set([latin, arabic])].filter((candidate) => candidate !== '' && candidate !== words.join(' '));
}

/** Levenshtein distance by character. */
export function distance(a, b) {
    const left = [...a];
    const right = [...b];
    let previous = Array.from({ length: right.length + 1 }, (_, i) => i);

    left.forEach((char, i) => {
        const current = [i + 1];

        right.forEach((other, j) => {
            current.push(Math.min(previous[j + 1] + 1, current[j] + 1, previous[j] + (char === other ? 0 : 1)));
        });

        previous = current;
    });

    return previous[right.length];
}

/**
 * How well a typed word matches a vocabulary word: 3 exactly, 2 as its start
 * (two letters or more), 1 within a typo; 0 not at all.
 */
export function wordScore(typed, word) {
    if (typed === word) {
        return 3;
    }

    const length = [...typed].length;

    if (length >= 2 && word.startsWith(typed)) {
        return 2;
    }

    const allowed = length >= 7 ? 2 : length >= 4 ? 1 : 0;

    return allowed > 0 && Math.abs([...word].length - length) <= allowed && distance(typed, word) <= allowed ? 1 : 0;
}

/** The best score of a typed word against a list of words. */
function bestOf(typed, words) {
    let best = 0;

    for (const word of words) {
        best = Math.max(best, wordScore(typed, word));

        if (best === 3) {
            break;
        }
    }

    return best;
}

const has = (tokens, words) => tokens.some((token) => words.includes(token));

/**
 * How well terms find an entry: every term must match one of its words; the
 * name starting with what was typed counts most. 0 when a term finds nothing.
 */
export function entryScore(terms, entry) {
    if (terms.length === 0) {
        return 0;
    }

    const words = entry.tokens ??= `${normalize(entry.label)} ${entry.words ?? ''}`.split(' ').filter(Boolean);
    let score = 0;

    for (const term of terms) {
        const best = bestOf(term, words);

        if (best === 0) {
            return 0;
        }

        score += best;
    }

    const name = entry.name ??= normalize(entry.label);
    const phrase = terms.join(' ');

    if (name.startsWith(phrase)) {
        score += 3;
    } else if (name.includes(phrase)) {
        score += 1;
    }

    return score;
}

/** The boost for what was picked before: often and lately counts more. */
export function frecency(recent, key, now = Date.now()) {
    const seen = recent?.[key];

    if (! seen) {
        return 0;
    }

    const days = (now - (seen.t ?? 0)) / 86400000;

    return Math.min(4, Math.log2(1 + (seen.n ?? 1))) * (days <= 7 ? 1 : days <= 30 ? 0.6 : 0.3);
}

/** The name with what was typed marked, safe to use as HTML. */
export function highlight(label, terms) {
    const escape = (text) => text.replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
    const plain = String(label ?? '');
    const lower = plain.toLowerCase();
    const marks = [];

    terms.filter((term) => term.length >= 2).forEach((term) => {
        let at = lower.indexOf(term);

        while (at >= 0) {
            marks.push([at, at + term.length]);
            at = lower.indexOf(term, at + term.length);
        }
    });

    if (marks.length === 0) {
        return escape(plain);
    }

    marks.sort((a, b) => a[0] - b[0]);

    let out = '';
    let from = 0;

    marks.forEach(([start, end]) => {
        if (start < from) {
            return;
        }

        out += `${escape(plain.slice(from, start))}<mark>${escape(plain.slice(start, end))}</mark>`;
        from = end;
    });

    return out + escape(plain.slice(from));
}

/** A Desk button ("New …" action a-…, page button g-…): it is kept in Quick access, never on the Desk itself. */
function isButton(item) {
    return /^[ag]-/.test(item ?? '');
}

/**
 * Every row the instant layer offers for what was typed, best first.
 *
 * context: { index, texts, recent, tabs: [{ id, title, url, active }], onDesk, canCustomize, dark, otherLanguage }
 * A row: { key, kind, label, html, sub, icon, badge, score, ...what running it needs }.
 */
export function search(query, context, limit = 8) {
    const raw = String(query ?? '');
    const commandsOnly = raw.trimStart().startsWith('>');
    const typed = commandsOnly ? raw.trimStart().slice(1) : raw;
    let rows = rowsFor(typed, context, commandsOnly);

    // Nothing found as typed: read it as typed on the other keyboard layout ("حقخلقشةس" → "programs").
    if (rows.length === 0 && [...normalize(typed).replace(/ /g, '')].length >= 3) {
        for (const reading of otherLayouts(typed)) {
            rows = rowsFor(reading, context, commandsOnly).map((row) => ({ ...row, corrected: reading }));

            if (rows.length > 0) {
                break;
            }
        }
    }

    return rows.slice(0, limit);
}

function rowsFor(typed, context, commandsOnly) {
    const { index, texts } = context;
    const verbs = index.verbs;
    const tokens = normalize(typed).split(' ').filter(Boolean);

    if (tokens.length === 0) {
        return commandsOnly ? commandRows([], context) : recentRows(context);
    }

    const verbWords = new Set([...verbs.add, ...verbs.desk, ...verbs.remove, ...verbs.open, ...verbs.tab, ...verbs.close, ...verbs.all, ...verbs.card, ...verbs.button, ...verbs.create, ...index.stop]);
    // Words that only carry the command, and single letters next to real words, are not names to find.
    const named = tokens.filter((token) => ! verbWords.has(token));
    const terms = named.length > 1 ? named.filter((token) => [...token].length > 1) : named;
    const pinWords = verbs.add.filter((word) => ! verbs.create.includes(word));
    const wantsDesk = has(tokens, verbs.desk) || has(tokens, verbs.card) || has(tokens, verbs.button);
    const wantsAdd = has(tokens, verbs.add);
    // "pin …", "put … on the desk", "add … to desk": place it. A bare "add …" may also mean "new …".
    const wantsPin = has(tokens, pinWords) || (wantsAdd && wantsDesk);
    const wantsCreate = has(tokens, verbs.create) && ! wantsPin;
    // "new" itself (not the "add" it shares with placing): "pin new task" means the New task button.
    const saysNew = has(tokens, verbs.create.filter((word) => ! verbs.add.includes(word)));
    const wantsRemove = has(tokens, verbs.remove);
    const wantsTab = has(tokens, verbs.tab);
    const wantsClose = has(tokens, verbs.close);
    const wantsButton = has(tokens, verbs.button);
    const placed = new Set(index.placed);
    const rows = [];
    const push = (row) => rows.push({ ...row, score: row.score + frecency(context.recent, row.key) });

    if (commandsOnly) {
        return commandRows(tokens, context).sort((a, b) => b.score - a.score).map((row) => ({ ...row, html: highlight(row.label, tokens) }));
    }

    // Desk tabs: "close all tabs", "close <tab>", "switch to <tab>", or a tab's own name.
    if (context.tabs?.length) {
        if (wantsClose && (has(tokens, verbs.all) || (wantsTab && terms.length === 0))) {
            push({ key: 'tabs:close-all', kind: 'close-all', label: texts.close_all_tabs, sub: '', icon: 'x', badge: texts.badge_run, score: 20 });
        }

        context.tabs.forEach((tab) => {
            const score = terms.length ? entryScore(terms, { label: tab.title, words: normalize(tab.url) }) : (wantsTab ? 1 : 0);

            if (score > 0) {
                push(wantsClose
                    ? { key: `tab-close:${tab.id}`, kind: 'close-tab', tab: tab.id, label: texts.close_tab.replace('__T__', tab.title), sub: '', icon: 'x', badge: texts.badge_run, score: score + 8 }
                    : { key: `tab:${tab.id}`, kind: 'tab', tab: tab.id, label: texts.switch_tab.replace('__T__', tab.title), sub: texts.open_tab, icon: 'grid', badge: texts.badge_switch, score: score + (wantsTab ? 8 : 2) });
            }
        });
    }

    // "remove … (from desk)": what is on the desk and matches.
    if (wantsRemove) {
        [...index.pages, ...index.actions].forEach((entry) => {
            if (entry.item && placed.has(entry.item) && terms.length) {
                const score = entryScore(terms, entry);

                if (score > 0) {
                    push({ key: `remove:${entry.item}`, kind: 'remove', item: entry.item, quick: isButton(entry.item), label: (isButton(entry.item) ? texts.remove_quick : texts.remove).replace('__P__', entry.label), sub: entry.group ?? '', icon: 'x', badge: texts.badge_remove, score: score + 10 });
                }
            }
        });

        return finish(rows, terms, tokens);
    }

    // "New …" actions: the form that creates it, or with "pin" a button for it on the desk.
    index.actions.forEach((action) => {
        const score = terms.length ? entryScore(terms, action) : 0;

        if (score <= 0) {
            return;
        }

        if (wantsPin) {
            push({ key: `add:${action.item}`, kind: 'add', item: action.item, route: action.route, quick: true, label: texts.add_quick.replace('__P__', action.label), sub: '', icon: 'plus', badge: placed.has(action.item) ? texts.badge_quick : texts.badge_add, score: score + (saysNew ? 10 : 7), there: placed.has(action.item) });
        }

        push({ key: `new:${action.key}`, kind: 'action', href: action.href, route: action.route, item: action.item, label: action.label, sub: texts.opens_form, icon: 'plus', badge: texts.badge_new, score: score + (wantsCreate || (wantsAdd && ! wantsDesk) ? 9 : 0) });
    });

    // Pages: open them, or put what stands for them on the desk.
    index.pages.forEach((page) => {
        const score = entryScore(terms, page);

        if (score <= 0) {
            return;
        }

        const offerAdd = wantsPin || (wantsAdd && ! hasCreateFor(page, index));
        const item = wantsButton ? `g-${page.route.replace(/\.index$/, '').replace(/\./g, '-')}` : page.item;

        if (offerAdd && item) {
            const quick = isButton(item);

            push({ key: `add:${item}`, kind: 'add', item, route: page.route, quick, label: (quick ? texts.add_quick : texts.add).replace('__P__', page.label), sub: page.group, icon: 'pin', badge: placed.has(item) ? (quick ? texts.badge_quick : texts.badge_there) : texts.badge_add, score: score + 8, there: placed.has(item) });
        }

        push({ key: `go:${page.route}`, kind: 'page', href: page.href, route: page.route, item: page.item, label: page.label, sub: page.group, icon: page.icon, badge: texts.badge_open, score: score + (wantsCreate || wantsAdd ? 0 : 1) });
    });

    // Ready layouts and the Desk's own commands.
    rows.push(...commandRows(tokens, context, terms));

    return finish(rows, terms, tokens);
}

/** Best first, each row once, its name marked where it matched. */
function finish(rows, terms, tokens) {
    const seen = new Set();

    return rows
        .sort((a, b) => b.score - a.score)
        .filter((row) => ! seen.has(row.key) && seen.add(row.key))
        .map((row) => ({ ...row, html: highlight(row.label, terms.length ? terms : tokens) }));
}

function hasCreateFor(page, index) {
    return index.actions.some((action) => action.route === page.route);
}

function commandRows(tokens, context, terms = tokens) {
    const { index, texts } = context;
    const rows = [];
    const add = (key, label, icon, score, extra = {}) => rows.push({ key, kind: 'command', label, sub: '', icon, badge: texts.badge_run, score: score + frecency(context.recent, key), ...extra });
    const commandScore = (id) => (tokens.length ? entryScore(tokens, { label: '', words: index.commands[id] ?? '' }) : 1);

    const theme = commandScore('theme');

    if (theme > 0) {
        add('cmd:theme', context.dark ? texts.theme_light : texts.theme_dark, context.dark ? 'sun' : 'moon', theme + 4, { command: 'theme' });
    }

    const language = commandScore('language');

    if (language > 0 && context.otherLanguage) {
        add('cmd:language', texts.language.replace('__L__', context.otherLanguage), 'map', language + 4, { command: 'language' });
    }

    if (context.canCustomize) {
        const customize = commandScore('customize');

        if (customize > 0) {
            add('cmd:customize', texts.customize, 'edit', customize + 3, { command: 'customize' });
        }

        index.layouts.forEach((layout) => {
            const score = terms.length ? entryScore(terms, layout) : 0;

            if (score > 0) {
                add(`layout:${layout.key}`, texts.layout.replace('__L__', layout.label), 'grid', score + 2, { command: 'layout', layout: layout.key });
            }
        });
    }

    const tour = commandScore('tour');

    if (tour > 0 && tokens.length) {
        add('cmd:tour', texts.tour, 'spark', tour, { command: 'tour' });
    }

    const desk = commandScore('desk');

    if (desk > 0 && tokens.length && ! context.onDesk) {
        add('cmd:desk', texts.desk, 'grid', desk + 2, { command: 'desk' });
    }

    return rows;
}

/** For an empty box: what was picked most, lately. */
function recentRows(context) {
    const { index, texts, recent } = context;
    const known = new Map();

    index.pages.forEach((page) => known.set(`go:${page.route}`, { kind: 'page', href: page.href, route: page.route, item: page.item, label: page.label, sub: page.group, icon: page.icon, badge: texts.badge_open }));
    index.actions.forEach((action) => known.set(`new:${action.key}`, { kind: 'action', href: action.href, route: action.route, item: action.item, label: action.label, sub: texts.opens_form, icon: 'plus', badge: texts.badge_new }));

    return Object.keys(recent ?? {})
        .filter((key) => known.has(key))
        .map((key) => ({ key, ...known.get(key), score: frecency(recent, key), recent: true }))
        .sort((a, b) => b.score - a.score)
        .slice(0, 5)
        .map((row) => ({ ...row, html: highlight(row.label, []) }));
}
