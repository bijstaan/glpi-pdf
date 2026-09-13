# GLPI PDF

One branded PDF export for the whole suite, for GLPI 11. This plugin owns the
*document*; other plugins own their *content* and contribute it through a hook.

Nothing on the output says GLPI — not the masthead, not the footer, not the
file's metadata.

## Example

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

## What it can export

| Object | Document | Contributed by |
|---|---|---|
| Ticket / Change / Problem | Full record: fields, analysis and plans, actors, approvals, service levels, followups, tasks, solutions, linked assets, linked ITIL objects, attachments with checksums, knowledge articles, costs, contracts, project tasks, satisfaction, timings, field history | this plugin |
| SOP | The authored procedure as a controlled document | `glpisop` |
| Ticket / Change / Problem | *appendix:* SOP checklists run on it, with answers, skips and reasons | `glpisop` |
| Change | *appendix:* risk assessment with the answers that scored it, window, outcome | `glpichange` |
| Major incident | The incident record, including internal updates | `glpimajor` |
| Major incident | Post-incident review, public updates only | `glpimajor` |
| Business service | Service card: health, members, subscribers | `glpiservice` |
| Report | An archived service review, re-rendered from what was archived | `glpireport` |

An appendix is what keeps a ticket and its procedure in one file. Without it an
auditor gets a checklist with no context and a ticket that never mentions the
procedure it was worked under.

## Choosing sections

Every itemtype something can render grows a **PDF tab**, which is the only way
in for a single item — there is no export button on the form. A ticket offers
nineteen sections, each a checkbox:

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

Unticked sections are omitted entirely rather than rendered empty. Two ship off:
field history is `glpi_logs`, which is several hundred rows on an old ticket.

A bulk export from a search takes the defaults, since there is nobody to ask
once fifty items are selected.

## Adding your own

One hook:

```php
$PLUGIN_HOOKS['glpipdf_documents']['myplugin'] = [MyPdf::class, 'offers'];
```

Describe what you have to say as data, never as markup:

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

        // Optional: keeps the document off the tab for items you have nothing
        // for, rather than offering one that answers with an error.
        'available' => static fn(CommonDBTM $item): bool => …,
    ]];
}
```

Tickable sections are declared alongside the offer, and the builder is handed
the ones chosen:

```php
'parts' => [
    ['key' => 'thing.summary', 'label' => __('Summary'), 'default' => true],
    ['key' => 'thing.history', 'label' => __('History'), 'default' => false],
],
'build' => static fn(CommonDBTM $item, array $parts = []): ?Doc => …,
```

An empty `$parts` means everything; that is what a plain link or a bulk action
passes.

Blocks are `section`, `subsection`, `text`, `muted`, `kv`, `table`, `checklist`,
`bullets`, `timeline`, `meter`, `note`, `spacer` and `pageBreak`. None is a
layout primitive — no columns, no boxes, no widths. Contributors choose what to
say; the engine decides how it looks, so changing the house style is one edit.

Guard `offers()` with `class_exists(Doc::class)` and register the hook
unconditionally; only this plugin reads it.

Contributors never produce HTML. TCPDF supports a narrow, undocumented subset of
CSS — no flex, grid, float or classes — so every plugin would separately
discover the same limits and drift into its own dialect of them. The engine does
generate HTML internally, because that is how TCPDF flows text across page
breaks and repeats a table header, but it does so in one place from data.

## Branding

Name and logo are read from `glpiwhitelabel` rather than copied, so rebranding
stays one page. Unconfigured, documents come out neutral: no name, no logo, and
never GLPI's. Only the accent colour belongs to this plugin, since a palette
that reads well as an application sidebar is not a rule colour on white A4.

The logo is rasterised and downscaled once into a cache keyed on
glpiwhitelabel's `revision`. TCPDF embeds an image at its original pixel
dimensions — a four-page procedure came out at 1.1 MB from a 1.2 MB print
original — and it cannot place an SVG through `Image()` at all.

## Fonts

DejaVu Sans rather than the default Helvetica, for correctness rather than
taste. The PDF core fonts have no glyphs outside Latin-1 and TCPDF substitutes
`?` silently, so a completed SOP run — whose notation is `✓` for done and `⊘`
for skipped — exports as a column of question marks with no error anywhere.

Measured: helvetica rendered `? check ? skip ?`; dejavusans rendered
`⌇ check ✓ skip ⊘`. Costs about 45 KB per file.

## Permissions

There is no `plugin_glpipdf_*` right. A PDF of a ticket contains the ticket, so
the check is `canViewItem()` on the item itself, re-derived from the database on
every request. A right of its own would allow an instance where somebody can
read a ticket on screen but not on paper, or the reverse — a plugin right that
becomes a way to read every ticket through a URL with an id in it.

Bulk export skips items the caller cannot read rather than refusing the job, and
the document says how many were left out.

## Install

```bash
# from the GLPI root
git clone https://github.com/bijstaan/glpi-pdf.git plugins/glpipdf
php bin/console plugin:install -u glpi glpipdf
php bin/console plugin:activate glpipdf
```

No tables and no rights, only settings rows, which uninstall removes. Settings
are at **Setup → Plugins → GLPI PDF**, and that page lists every registered
document, so "why is there no PDF tab on a change" is answered by looking.

## Tests

```bash
sh tests/run.sh                 # pure: the document model. Runs in CI
php tests/class-load.php glpipdf glpisop glpichange glpimajor glpiservice glpireport
node tests/browser/pdf-check.js
```

`class-load.php` boots a GLPI kernel and loads every class, which is the only
way to catch an inheritance-signature fatal: `php -l` parses a file, it does not
link it. This plugin shipped one on its first run — a private `writeHtml()`
colliding with TCPDF's `writeHTML()`, which passed lint and answered every
export with a 500.

`pdf-check.js` covers the tab and the bulk action; `pdf-tab-check.js` covers the
section picker and the download.

## Limitations

- No scheduling and no email; `glpireport` owns that, with a queue and a send
  trail.
- Nothing is stored. The file streams and is gone. Filing it as a GLPI
  `Document` is not built.
- No per-document layout options: the point is one house style
  across six plugins.

## Licence

GPL-3.0-or-later, the same licence as GLPI. The plugin is loaded into GLPI's
process and extends its classes, so it is a derivative work. See
[LICENSE](LICENSE).
