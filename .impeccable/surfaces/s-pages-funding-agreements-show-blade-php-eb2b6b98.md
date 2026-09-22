---
version: 1
slug: "s-pages-funding-agreements-show-blade-php-eb2b6b98"
primary_target: "resources/views/pages/funding-agreements/⚡show.blade.php"
related_targets: []
---

# Funding agreement surface brief

Scope: `/funding-agreements/{id}`, one donor's promise against one proposal. Mode: Operate.
Audience: the projects & fundraising manager ("how much of this promise has landed, what is late, who do I chase, what does it pay for") and officers recording or correcting tranches. Job: read the agreement's collection state in seconds, act on a tranche (schedule, edit, record receipt) in place, and follow any connection out: donor and its follow-up, proposal and its term/status, funded projects, accounts, co-funders, agreement files. Currencies are never summed across USD/EUR/SYP; a tranche in another currency is counted apart, not converted. Pending tranches never count as received even when net amounts were pre-filled. Every string in lang/en + lang/ar; RTL via logical classes. User delegated the concept choice ("choose best option possible").

## Direction contract

THESIS: The whole promise is one dial: every tranche is an arc sized by its money, coloured by where it stands, with the unscheduled remainder drawn as a visible gap; the dial and its tranche trackers answer "landed, late, owed" before anything is read. Refuses the stat-tile pair over a flat tranche list.

OWN-WORLD: Inherited from the proposal dossier. White ground, Somar Sans, tabular numerals, hairlines, no boxed cards or shadowed panels. Funding teal for landed, amber stripes for on-its-way, HC red only for late/failed, pale ghost for unscheduled. Dot-and-thread for tranche journeys and the connection thread.

STORY: The manager lands, reads the dial's centre (share landed, amount still owed), sees the red arc, hovers it to light its tranche, reads that tranche's four-stop tracker and its sent → fees → rate → net trace, edits or records it in place, then follows the thread to the donor's next follow-up or a funded project.

FIRST VIEWPORT: Breadcrumb; donor · proposal at display scale, project number, currency, proposal status, Schedule tranche / Edit agreement. Start half: the dial at hero size with share landed and still owed in its centre. End half: readings on hairlines (committed, landed, on its way, late in red, not scheduled, fees lost) then an attention line when anything is late. Tranche journeys start directly under, with the connection thread in a side column.

FORM: The Collection Dial, position 7 on the ordered list, seed key d62547a5 (surface round; user delegated, dealt lead locked), raised with the Settlement Statement's per-tranche trace and running still-owed balance and the Connection Map's threaded links. Code-led, no comp. Signature interaction: arc and tranche row light each other on hover/focus; the dial draws itself in once, arc by arc, on load.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
