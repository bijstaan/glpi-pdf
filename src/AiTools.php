<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

use CommonDBTM;
use Document;
use Entity;
use GlpiPlugin\Glpiai\Tool;

/**
 * Branded exports, offered to glpi-ai's assistant.
 *
 * Two tools, and the read is the one that has to exist first. Which documents
 * a record can produce is not a fixed list: it is whatever the plugins have
 * offered for that itemtype and whatever is *available* on that particular
 * item — a SOP run that nobody has started offers a blank procedure and not a
 * filled-in one. A model that guessed a document key would be wrong on
 * exactly the instances that had configured the most.
 *
 * **`export_pdf` writes, and the interesting decision is where the file
 * lands.** The obvious thing — attach it to the ticket — is the one thing it
 * must not do: a document attached to a ticket is visible to the requester,
 * and this plugin's own PDFs quote internal notes, assessments and technician
 * names. So the document is filed against the **entity**, which is where an
 * auditor looks for "what did we produce about this entity" and where a
 * self-service user cannot see it at all. A person who wants the entity to
 * have it can attach it in one click, having read it first.
 *
 * That is also why nothing here emails anything. Rendering a PDF is
 * reversible — delete the document. Sending one is not.
 *
 * Gated on GLPI's own `document` right at CREATE, plus the item's own read
 * right, which {@see Export::load()} checks. Both are necessary: the first is
 * what "may put files in this GLPI" means, and the second is what stops a
 * technician exporting a change they could not open.
 */
final class AiTools
{
    /** @return Tool[] */
    public static function all(): array
    {
        return [self::documents(), self::export()];
    }

    // ------------------------------------------------------------ documents

    private static function documents(): Tool
    {
        return new Tool(
            name: 'pdf_documents',
            description: 'Which branded PDF documents can be produced for a record — a ticket, '
                . 'change, problem, procedure or report — and the key for each. Different '
                . 'itemtypes offer different documents, and some are only available once there '
                . 'is something to put in them, so call this before export_pdf rather than '
                . 'guessing a name.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'itemtype' => [
                        'type'        => 'string',
                        'description' => 'The record kind, e.g. Ticket, Change, Problem.',
                    ],
                    'items_id' => [
                        'type'        => 'integer',
                        'description' => 'Its id. Omit to list what the itemtype offers in '
                            . 'general rather than what this record can actually produce.',
                    ],
                ],
                'required'   => ['itemtype'],
            ],
            handler: [self::class, 'runDocuments'],
            right: 'document',
            source: 'glpipdf',
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runDocuments(array $arguments = [], mixed $context = null): array
    {
        $itemtype = trim((string) ($arguments['itemtype'] ?? ''));
        $items_id = (int) ($arguments['items_id'] ?? 0);

        if ($items_id <= 0 && $context instanceof \GlpiPlugin\Glpiai\ToolContext
            && (int) $context->items_id > 0) {
            $itemtype = $itemtype !== '' ? $itemtype : (string) $context->itemtype;
            $items_id = (int) $context->items_id;
        }

        if ($itemtype === '' || !Registry::exportable($itemtype)) {
            return [
                'documents' => [],
                'note'      => sprintf(
                    'Nothing here exports a %s. Exportable kinds are: %s.',
                    $itemtype !== '' ? $itemtype : 'record',
                    implode(', ', Registry::itemtypes()) ?: 'none configured'
                ),
            ];
        }

        $item = null;
        if ($items_id > 0) {
            $item = Export::load($itemtype, $items_id);

            if ($item === null) {
                return ['error' => sprintf('No %s %d is visible to you.', $itemtype, $items_id)];
            }
        }

        $out = [];
        foreach (Registry::documentsFor($itemtype, $item) as $offer) {
            $out[] = array_filter([
                'key'   => (string) $offer['key'],
                'label' => (string) $offer['label'],
                'from'  => (string) $offer['plugin'],
            ], static fn($v): bool => $v !== '');
        }

        $appendices = [];
        foreach (Registry::appendicesFor($itemtype) as $offer) {
            $appendices[] = (string) $offer['label'];
        }

        return array_filter([
            'itemtype'   => $itemtype,
            'documents'  => $out,
            'appendices' => $appendices,
            'note'       => $out === []
                ? ($item !== null
                    ? 'Nothing can be produced for this particular record yet — the documents '
                        . 'this kind offers all need something that is not there.'
                    : 'This kind is exportable but nothing is offering a document for it.')
                : 'Appendices are added automatically to whichever document is produced.',
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    // --------------------------------------------------------------- export

    private static function export(): Tool
    {
        return new Tool(
            name: 'export_pdf',
            description: 'Produce a branded PDF of a ticket, change, problem, procedure or '
                . 'report and file it in the document library, returning a link. Use it when '
                . 'somebody needs a record on paper or as a file — for an auditor, an entity '
                . 'meeting, a supplier, a signature. The file is filed against the entity '
                . 'entity and is deliberately NOT attached to the record, because these '
                . 'documents quote internal notes and the requester can see anything attached '
                . 'to their ticket. Tell the person where it went and let them attach or send '
                . 'it themselves.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'itemtype' => [
                        'type'        => 'string',
                        'description' => 'The record kind, e.g. Ticket, Change, Problem.',
                    ],
                    'items_id' => [
                        'type'        => 'integer',
                        'description' => 'Its id. Omit to use the record the conversation is '
                            . 'about.',
                    ],
                    'document' => [
                        'type'        => 'string',
                        'description' => 'Which document, by the key from pdf_documents. Omit '
                            . 'for the first one offered.',
                    ],
                ],
                'required'   => ['itemtype'],
            ],
            handler: [self::class, 'runExport'],
            mutates: true,
            right: 'document',
            right_level: CREATE,
            source: 'glpipdf',
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runExport(array $arguments = [], mixed $context = null): array
    {
        $itemtype = trim((string) ($arguments['itemtype'] ?? ''));
        $items_id = (int) ($arguments['items_id'] ?? 0);

        if ($items_id <= 0 && $context instanceof \GlpiPlugin\Glpiai\ToolContext
            && (int) $context->items_id > 0) {
            $itemtype = $itemtype !== '' ? $itemtype : (string) $context->itemtype;
            $items_id = (int) $context->items_id;
        }

        // The same gate the export pages use. It is about the interface rather
        // than a right: an export is technician output, and a self-service
        // session has no business producing one.
        if (!Export::permitted()) {
            return ['error' => 'Exports are only available from the central interface.'];
        }

        $item = $items_id > 0 ? Export::load($itemtype, $items_id) : null;

        if ($item === null) {
            return [
                'error' => sprintf(
                    'No %s %d that you can see, or that kind cannot be exported. Exportable '
                    . 'kinds: %s.',
                    $itemtype !== '' ? $itemtype : 'record',
                    $items_id,
                    implode(', ', Registry::itemtypes()) ?: 'none configured'
                ),
            ];
        }

        $rendered = Export::one($itemtype, $items_id, trim((string) ($arguments['document'] ?? '')));

        if ($rendered === null) {
            return [
                'error' => 'That document could not be produced for this record. Use '
                    . 'pdf_documents to see what it actually offers.',
            ];
        }

        return self::store($item, (string) $rendered['filename'], (string) $rendered['bytes']);
    }

    /**
     * Put the bytes into a GLPI Document, filed against the entity.
     *
     * @return array<string,mixed>
     */
    private static function store(CommonDBTM $item, string $filename, string $bytes): array
    {
        if (Document::isValidDoc($filename) === '') {
            return [
                'error' => 'The PDF document type is disabled on this GLPI instance, so nothing '
                    . 'can be stored. An administrator enables it under Setup > Dropdowns > '
                    . 'Document types.',
            ];
        }

        $path = GLPI_TMP_DIR . '/' . $filename;

        if (@file_put_contents($path, $bytes) === false) {
            return ['error' => 'The document could not be written to the temporary directory.'];
        }

        $entities_id = (int) ($item->fields['entities_id'] ?? 0);

        $document     = new Document();
        $documents_id = $document->add([
            'name'         => mb_substr(
                sprintf('%s %d — %s', $item::getTypeName(1), (int) $item->getID(),
                    (string) ($item->fields['name'] ?? '')),
                0,
                250
            ),
            'entities_id'  => $entities_id,
            'is_recursive' => 0,
            // Entity, not the record. See the class comment: anything attached
            // to a ticket is visible to the person who raised it.
            'itemtype'     => Entity::class,
            'items_id'     => $entities_id,
            '_filename'    => [$filename],
            '_prefix_filename' => [''],
            'comment'      => sprintf(
                'Exported from %s %d by the assistant, at a technician\'s request.',
                $item::getType(),
                (int) $item->getID()
            ),
        ]);

        @unlink($path);

        if ($documents_id === false || (int) $documents_id <= 0) {
            return ['error' => 'GLPI refused to store the document.'];
        }

        return [
            'exported'     => sprintf('%s %d', $item::getType(), (int) $item->getID()),
            'documents_id' => (int) $documents_id,
            'filename'     => $filename,
            'link'         => sprintf('%s/front/document.send.php?docid=%d', self::root(), (int) $documents_id),
            'filed_under'  => \Dropdown::getDropdownName('glpi_entities', $entities_id),
            'note'         => 'Filed in the document library against the entity, and '
                . 'not attached to the record — these exports quote internal notes, and '
                . 'attaching one to a ticket would show it to the requester. Say where it is '
                . 'and let a person decide who gets it.',
        ];
    }

    /** The instance's own base URL, for a link somebody can click. */
    private static function root(): string
    {
        /** @var array<string,mixed> $CFG_GLPI */
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
    }
}
