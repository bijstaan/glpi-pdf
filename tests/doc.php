<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The document model, tested with bare PHP.
 *
 * Doc is deliberately free of GLPI: it takes plain values and produces plain
 * arrays, which is what makes the contract between this plugin and its
 * contributors testable at all. Everything downstream of it — the engine, the
 * fonts, TCPDF — needs a booted GLPI and is covered by tests/class-load.php and
 * by glpi-pdf/tests/browser/pdf-check.js.
 *
 * Run: php tests/doc.php
 */

require_once __DIR__ . '/../src/Doc.php';

use GlpiPlugin\Glpipdf\Doc;

$failures = 0;
$total    = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $total;
    $total++;
    if ($condition) {
        echo "PASS: $label\n";
    } else {
        echo "FAIL: $label" . ($detail !== '' ? " :: $detail" : '') . "\n";
        $failures++;
    }
}

/** @return array<int,string> the block types, in order */
function types(Doc $doc): array
{
    return array_map(static fn(array $b): string => (string) $b['type'], $doc->blocks);
}

// --- the masthead ---------------------------------------------------------

$doc = Doc::make('New Employee')->subtitle('SOP')->reference('r12');

check('a fresh document has no body', $doc->isEmpty());
check('the title is kept verbatim', $doc->title === 'New Employee');

// A blank value is a row that says nothing; a document full of "Resolved: —"
// reads as a broken export.
$doc->meta(['Entity' => 'Acme', 'Resolved' => '', 'Owner' => '  ']);
check('blank meta values are dropped', array_keys($doc->meta) === ['Entity'], json_encode($doc->meta));

// --- blocks that refuse to be empty ---------------------------------------

$empty = Doc::make('x')
    ->text('')
    ->muted('   ')
    ->kv(['a' => '', 'b' => null])
    ->table(['h'], [])
    ->checklist([])
    ->bullets(['', '  '])
    ->timeline([])
    ->note('');

check('empty content adds no blocks', !$empty->hasBody(), implode(',', types($empty)));
check('and with no masthead either, the document is empty', $empty->isEmpty());

// A reader who ticked only the summary asked for a title and a facts block.
// Treating that as nothing is the export refusing a legitimate request.
$summary_only = Doc::make('x')->meta(['Status' => 'New']);
check('a masthead-only document is not empty', !$summary_only->isEmpty());
check('but it has no body', !$summary_only->hasBody());

// The distinction that matters: an empty *document* is dropped by the
// registry, so a contributor with nothing to say costs no heading.
$one = Doc::make('x')->kv(['a' => '', 'b' => 'kept']);
check('a kv block survives if any pair does', types($one) === ['kv']);
check('and carries only the pairs that said something',
    $one->blocks[0]['pairs'] === ['b' => 'kept'], json_encode($one->blocks[0]['pairs']));

// --- ordering -------------------------------------------------------------

$ordered = Doc::make('x')
    ->section('One')
    ->text('body')
    ->subsection('Two')
    ->table(['a', 'b'], [['1', '2']])
    ->spacer()
    ->pageBreak();

check('blocks keep the order they were added',
    types($ordered) === ['heading', 'text', 'heading', 'table', 'spacer', 'pagebreak'],
    implode(',', types($ordered)));

check('section and subsection differ only by level',
    $ordered->blocks[0]['level'] === 1 && $ordered->blocks[2]['level'] === 2);

// --- the meter is clamped -------------------------------------------------

$meter = Doc::make('x')->meter('a', 140.0)->meter('b', -20.0)->meter('c', 42.5);
check('a percentage over 100 is clamped', $meter->blocks[0]['percent'] === 100.0);
check('a negative percentage is clamped', $meter->blocks[1]['percent'] === 0.0);
check('a sane percentage is untouched', $meter->blocks[2]['percent'] === 42.5);

// --- notes ----------------------------------------------------------------

$note = Doc::make('x')->note('a', 'nonsense-tone');
check('an unknown tone falls back to info', $note->blocks[0]['tone'] === Doc::INFO);

// --- appending ------------------------------------------------------------

$host = Doc::make('Ticket')->section('Description')->text('body');
$app  = Doc::make('Procedures')->subtitle('from glpi-sop')->checklist([['label' => 'x']]);

$host->append($app);
check('an appendix arrives under a heading of its own title',
    types($host) === ['heading', 'text', 'heading', 'text', 'checklist'],
    implode(',', types($host)));
check('and that heading is the appendix title',
    $host->blocks[2]['text'] === 'Procedures');

// Merging many items into one file wants the blocks without the extra heading.
$flat = Doc::make('a')->append(Doc::make('b')->text('x'), false);
check('append can skip the heading', types($flat) === ['text'], implode(',', types($flat)));

$unchanged = Doc::make('a')->append(Doc::make('empty'));
check('appending an empty document adds nothing', $unchanged->isEmpty());

// An appendix is its blocks; its meta is never read, so meta alone is nothing.
$meta_only = Doc::make('a')->append(Doc::make('b')->meta(['k' => 'v']));
check('appending a masthead-only document adds nothing', $meta_only->isEmpty());

// --- filenames ------------------------------------------------------------
//
// The file leaves the instance, so the name has to mean something in a
// downloads folder and survive every filesystem it lands on.

$cases = [
    ['Ticket #1164', 'Print queue won’t clear',        'ticket-1164-print-queue-won-t-clear.pdf'],
    ['',             'R&D / Ops — review',             'r-d-ops-review.pdf'],
    ['',             '   ',                            'document.pdf'],
    ['',             '../../etc/passwd',               'etc-passwd.pdf'],
];

foreach ($cases as [$reference, $title, $expected]) {
    $name = Doc::make($title)->reference($reference)->filename();
    check("filename for " . json_encode($title), $name === $expected, "got $name");
}

$long = Doc::make(str_repeat('a', 400))->filename();
check('a very long title is capped', strlen($long) <= 84, "len=" . strlen($long));
check('and still ends in .pdf', str_ends_with($long, '.pdf'));

// Non-Latin titles must not reduce to nothing — an instance running in Greek
// would otherwise download every document as "document.pdf".
$greek = Doc::make('Αναφορά υπηρεσίας')->filename();
check('a non-Latin title survives', $greek !== 'document.pdf', $greek);

// --------------------------------------------------------------------------

printf("\n%d checks, %d failure(s)\n", $total, $failures);

exit($failures === 0 ? 0 : 1);
