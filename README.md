# GLPI PDF

One branded PDF export for the whole suite — for GLPI 11.

Every plugin here eventually grows something a person needs on paper: a
procedure to sign, a change record for an auditor, a service review for a
customer. Left to themselves each one grows its own answer — a print stylesheet
in this plugin, an HTML archive in that one — and the estate ends up handing
customers six documents that look like six products.

So this plugin owns the *document*, and the other plugins own their *content*.

## What it produces

A ticket, with its SOP checklist folded in as an appendix rather than arriving
as a second file:

```
  Bijstaan Help Desk
  ─────────────────────────────────────────────
  browsercheck — account lockout for jsmith
  Ticket #1095
  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Status            New
  Entity            Root entity
  Opened            2026-08-22 20:06
  …

  Description
  People
  Procedures
    Example — account lockout
    ⚠ 3 required steps were still outstanding when this was exported.
    □  1   Confirm the caller's identity *
    □  2   Account name *
    □  3   Did unlocking the account resolve it? *
    ·  3a  What is the account still reporting? *
           not applicable to this item
```

Nowhere on it does the word GLPI appear — not in the masthead, not in the
footer, not in the file's own metadata.

## Why not the browser's print dialogue

That is what glpi-report did, and it was the right call when it was made: a PHP
PDF library looked like a new dependency, and this monorepo deliberately has no
`composer.json` anywhere.

It turns out GLPI 11 already ships `tecnickcom/tcpdf` in its vendor tree and
uses it for the search engine's list export. So a real PDF costs nothing new —
and "print this and choose Save as PDF" is not an instruction to give somebody
who has to send a customer a document every month.

Core's own PDF export is a different thing: `Glpi\Search\Output\Tcpdf` renders a
*search result list* — the columns you chose, one row per ticket. That answers
"what is in this queue". It cannot answer "what happened on this ticket", which
is the question somebody has when they are asked to produce a record.

## Choosing what goes in

Every itemtype something can render grows a **PDF tab**, and it is the only way
in for a single item — there is deliberately no export button on the form
itself. A ticket offers nineteen sections — everything the record holds, including the ones another
plugin contributed — and each is a checkbox:

```
  FULL RECORD
  ☑ Summary and fields        ☑ Linked assets
  ☑ Description               ☑ Linked tickets, changes and problems
  ☑ People                    ☑ Attached documents
  ☑ Approvals                 ☑ Knowledge base articles
  ☑ Service levels            ☑ Costs
  ☑ Followups                 ☑ Contracts
  ☑ Tasks                     ☑ Project tasks
  ☑ Solution                  ☑ Satisfaction survey
                              ☐ Timings and delays
  FROM OTHER PLUGINS          ☐ Field history (full audit trail)
  ☑ SOP checklists
```

What is not ticked is left out entirely — not emptied, not marked absent — so
an export is exactly as long as what it says.

Two ship **off**. The field history is `glpi_logs`: every field change, with who
and when, which is the artefact that lets this plugin use the word *audit* — and
also several hundred rows on an old ticket, which would make the ordinary export
forty pages for the majority who did not come for one. It is one tick away.

A bulk export from a search takes the defaults, because there is nobody to ask
once fifty items are selected.

## What it can export

| Object | Document | From |
|---|---|---|
| Ticket / Change / Problem | Full record — fields, analysis and plans, actors, approvals, service levels, followups, tasks, solutions, linked assets, linked ITIL objects, attachments with checksums, knowledge articles, costs, contracts, project tasks, satisfaction, timings, and the full field history | this plugin |
| SOP | The authored procedure, as a controlled document | glpi-sop |
| Ticket / Change / Problem | *appendix:* the SOP checklists run on it, with answers, skips and reasons | glpi-sop |
| Change | *appendix:* risk assessment with the answers that scored it, window, outcome | glpi-change |
| Major incident | The incident record, including internal updates | glpi-major |
| Major incident | The post-incident review — customer-facing updates only | glpi-major |
| Business service | Service card — health, members, subscribers | glpi-service |
| Report | An archived service review, re-rendered from what was archived | glpi-report |

An **appendix** is the part that makes the suite hang together rather than
merely coexist. Without it a ticket carrying an SOP run gives you two files: a
checklist with no context, and a ticket that does not mention the procedure it
was worked under. The person who needs both is the auditor, who now has to
staple them together and be trusted to have picked the right pair.

## Adding your own

A plugin registers one hook — the same shape glpi-sop already uses to offer its
procedures to glpi-ai:

```php
$PLUGIN_HOOKS['glpipdf_documents']['myplugin'] = [MyPdf::class, 'offers'];
```

and describes what it has to say as **data**, never as markup:

```php
public static function offers(): array
{
    return [[
        'key'      => 'myplugin.thing',
        'itemtype' => Thing::class,
        'label'    => __('Thing'),
        'kind'     => 'document',          // or 'appendix'
        'build'    => static fn(CommonDBTM $item): ?Doc => Doc::make($item->fields['name'])
            ->reference('Thing #' . $item->getID())
            ->meta(['Owner' => '…', 'Opened' => '…'])
            ->section('What happened')
            ->text($item->fields['content'])
            ->table(['Step', 'Result'], $rows)
            ->meter('Attainment', 92.3, '24 of 26 within target')
            ->note('Not yet approved', Doc::WARN),

        // Optional. Keeps the document off the tab for items you have nothing
        // for, instead of offering one that answers with an error.
        'available' => static fn(CommonDBTM $item): bool => …,
    ]];
}
```

Sections a reader can tick are declared alongside the offer, and the builder is
handed the ones they chose:

```php
'parts' => [
    ['key' => 'thing.summary', 'label' => __('Summary'), 'default' => true],
    ['key' => 'thing.history', 'label' => __('History'), 'default' => false],
],
'build' => static fn(CommonDBTM $item, array $parts = []): ?Doc => …,
```

An empty `$parts` is what a caller with no opinion passes — a plain link, a bulk
action — and means everything.

The blocks are `section`, `subsection`, `text`, `muted`, `kv`, `table`,
`checklist`, `bullets`, `timeline`, `meter`, `note`, `spacer` and `pageBreak`.
None of them is a layout primitive: there is no column, no box, no width. A
contributor chooses what to say; the engine decides what it looks like, which is
the only arrangement where changing the house style is one edit rather than
eight.

Guard `offers()` with `class_exists(Doc::class)` and register the hook
unconditionally — only this plugin reads it, so an instance without it pays one
array assignment.

### Why not just hand over HTML

Faster to write exactly once, and then: TCPDF supports a narrow, undocumented
subset of CSS — no flex, no grid, no float, no classes — so every plugin would
separately discover the same limits, and every plugin's document would drift
into its own dialect of them.

The engine *does* generate HTML internally, because that is how TCPDF flows text
across a page break and splits a forty-row table over three pages with its
header repeated on each. But the markup is generated in one place from data, and
no contributor sees it.

## Branding

The name and logo come from **glpi-whitelabel**, read from that plugin rather
than copied, so rebranding stays one page. Unconfigured, documents are produced
neutral — no name, no logo, and never GLPI's.

The logo is rasterised and downscaled once into a cache keyed on
glpi-whitelabel's own `revision`, which it already bumps on every save. Two
reasons, both measured rather than theoretical: TCPDF embeds the file it is
given at its original pixel dimensions, and a four-page procedure came out at
1.1 MB because this instance's logo is a 1.2 MB print original; and TCPDF cannot
place an SVG through `Image()` at all, while glpi-whitelabel correctly accepts
one.

Only the accent colour belongs to this plugin. glpi-whitelabel has no such
setting — it themes GLPI's chrome through a palette, and a palette that reads
well as an application sidebar is not a rule colour on white A4.

## Fonts

DejaVu Sans, not the default Helvetica, and this is a correctness decision
rather than a typographic one. The PDF core fonts have no glyphs outside
Latin-1, and TCPDF substitutes `?` silently — so a completed SOP run, whose
whole notation is `✓` for done and `⊘` for skipped, exports as a column of
question marks with nothing anywhere reporting an error.

Measured on this instance: helvetica rendered `? check ? skip ?`; dejavusans
rendered `⌇ check ✓ skip ⊘`. The cost is about 45 KB per file, and is accepted.

## Permissions

There is no `plugin_glpipdf_*` right, deliberately.

A PDF of a ticket contains the ticket, so anyone who can read the record can
read the export and nobody else: the check is `canViewItem()` on the item
itself, re-derived from the database on every request. A right of its own would
create the possibility of an instance where somebody can read a ticket on screen
but not on paper — or, worse, the reverse, where a plugin right granted to a
profile becomes a way to read every ticket in the estate through a URL with an
id in it.

Bulk export skips items the caller cannot read rather than refusing the whole
job, and the document says how many were left out.

## What it does not do

- **No scheduling and no email.** glpi-report already owns that road, with a
  queue and a send trail; a second one here would be a worse copy.
- **Nothing is stored.** The file streams and is gone. Filing it as a GLPI
  `Document` is a natural next step and is not built.
- **No per-document layout options.** The point is that six plugins produce one
  house style, and every knob is a way for that to stop being true.

## Install

```
docker exec glpi-glpi-1 php bin/console glpi:plugin:install --username=glpi glpipdf
docker exec glpi-glpi-1 php bin/console glpi:plugin:activate glpipdf
```

It creates no tables and no rights — only its settings rows, which uninstall
removes. Settings are at **Setup → Plugins → GLPI PDF**, and that page lists
every document currently registered, so "why is there no PDF tab on a change" is
answered by looking rather than by reading code.

## Tests

```
sh tests/run.sh                 # pure: the document model. Runs in CI.
php tests/class-load.php glpipdf glpisop glpichange glpimajor glpiservice glpireport
node glpi-pdf/tests/browser/pdf-check.js
```

`class-load.php` boots a GLPI kernel and loads every class, which is the only
way to catch an inheritance-signature fatal — `php -l` parses a file, it does
not link it. This plugin shipped one on its first run: a private `writeHtml()`
colliding with TCPDF's `writeHTML()`, which passed lint and answered every
export with a 500.

`pdf-check.js` covers the tab's presence and the bulk action; `pdf-tab-check.js`
covers the section picker and the download.

## Licence

GNU General Public License, version 3 or later — the same licence as GLPI.
This plugin is loaded into GLPI's process and extends its classes, so it is a
derivative work of GLPI and carries GLPI's licence. See [LICENSE](LICENSE).
