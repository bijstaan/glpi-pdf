<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

use CommonDBTM;
use CommonITILActor;
use CommonITILObject;
use CommonITILValidation;
use Html;
use ITILFollowup;
use ITILSolution;
use Log;

/**
 * Ticket, Change and Problem as documents — everything the record holds.
 *
 * The one contributor that lives in this plugin rather than in the plugin it
 * describes, because core has no plugin to put it in. Everything else follows
 * the rule that a plugin describes its own objects.
 *
 * Core does have a PDF export, and it is not this: `Glpi\Search\Output\Tcpdf`
 * renders a *search result list* — the columns you chose, one row per ticket.
 * That answers "what is in this queue". It cannot answer "what happened on this
 * ticket", which is the question somebody has when they are asked to produce
 * evidence.
 *
 * ### Completeness is the point, and so is being able to turn it off
 *
 * An audit-ready export and a readable one are not the same document. So every
 * section is a named {@see Parts} entry the reader ticks, and two of them ship
 * *off*:
 *
 *  - **Field history** — `glpi_logs`, every field change with who and when. The
 *    single most important artefact at audit and the reason this plugin can
 *    claim the word; also hundreds of rows on an old ticket, which would make
 *    the ordinary export forty pages for the majority who did not come for it.
 *  - **Timings** — the delay statistics core computes. Useful, and noise on a
 *    document somebody is reading rather than measuring.
 *
 * The history is read through `Log::getHistoryData()` rather than off the table
 * directly. That method decodes `id_search_option` against the itemtype's own
 * search options — turning `4` into "Technician" and an id into a name — and
 * reimplementing it here would produce a second, worse, and silently divergent
 * account of the same rows.
 */
final class Itil
{
    /** @return array<int,array<string,mixed>> */
    public static function offers(): array
    {
        $out = [];

        foreach (['Ticket', 'Change', 'Problem'] as $itemtype) {
            if (!class_exists($itemtype)) {
                continue;
            }

            $out[] = [
                'key'      => 'glpipdf.itil.' . strtolower($itemtype),
                'itemtype' => $itemtype,
                'label'    => __('Full record', 'glpipdf'),
                'kind'     => 'document',
                'weight'   => 10,
                'parts'    => self::parts($itemtype),
                'build'    => static fn(CommonDBTM $item, array $parts = []): ?Doc
                    => self::build($item, $parts),
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array{key:string,label:string,default:bool}>
     */
    private static function parts(string $itemtype): array
    {
        $parts = [
            ['key' => 'summary',      'label' => __('Summary and fields', 'glpipdf'),  'default' => true],
            ['key' => 'description',  'label' => __('Description'),                    'default' => true],
        ];

        // The analysis fields are the whole substance of a change or a problem
        // and do not exist on a ticket, so the option is only offered where
        // there is something behind it.
        if ($itemtype === 'Change' || $itemtype === 'Problem') {
            $parts[] = ['key' => 'analysis', 'label' => __('Analysis and plans', 'glpipdf'), 'default' => true];
        }

        $parts = array_merge($parts, [
            ['key' => 'actors',       'label' => __('People'),                         'default' => true],
            ['key' => 'approvals',    'label' => __('Approvals'),                      'default' => true],
            ['key' => 'sla',          'label' => __('Service levels', 'glpipdf'),      'default' => true],
            ['key' => 'followups',    'label' => __('Followups'),                      'default' => true],
            ['key' => 'tasks',        'label' => __('Tasks'),                          'default' => true],
            ['key' => 'solution',     'label' => __('Solution'),                       'default' => true],
            ['key' => 'items',        'label' => __('Linked assets', 'glpipdf'),       'default' => true],
            ['key' => 'links',        'label' => __('Linked tickets, changes and problems', 'glpipdf'), 'default' => true],
            ['key' => 'documents',    'label' => __('Attached documents', 'glpipdf'),  'default' => true],
            ['key' => 'knowledge',    'label' => __('Knowledge base articles', 'glpipdf'), 'default' => true],
            ['key' => 'costs',        'label' => __('Costs'),                          'default' => true],
        ]);

        if ($itemtype === 'Ticket') {
            $parts[] = ['key' => 'contracts',    'label' => __('Contracts'),            'default' => true];
            $parts[] = ['key' => 'projects',     'label' => __('Project tasks', 'glpipdf'), 'default' => true];
            $parts[] = ['key' => 'satisfaction', 'label' => __('Satisfaction survey', 'glpipdf'), 'default' => true];
        }

        // Off by default. See the class comment.
        $parts[] = ['key' => 'timings', 'label' => __('Timings and delays', 'glpipdf'), 'default' => false];
        $parts[] = ['key' => 'history', 'label' => __('Field history (full audit trail)', 'glpipdf'), 'default' => false];

        return $parts;
    }

    /** @param string[] $parts */
    private static function build(CommonDBTM $item, array $parts): ?Doc
    {
        if (!($item instanceof CommonITILObject)) {
            return null;
        }

        $itemtype = $item::getType();

        $doc = Doc::make((string) $item->fields['name'])
            ->reference(sprintf('%s #%d', $itemtype::getTypeName(1), (int) $item->getID()));

        $want = static fn(string $key): bool => in_array($key, $parts, true);

        if ($want('summary')) {
            $doc->meta(self::identity($item));
        }

        if ($want('description')) {
            $content = self::readable((string) ($item->fields['content'] ?? ''));
            if ($content !== '') {
                $doc->section(__('Description'))->text($content);
            }
        }

        if ($want('analysis')) {
            self::analysis($doc, $item);
        }
        if ($want('actors')) {
            self::actors($doc, $item);
        }
        if ($want('approvals')) {
            self::validations($doc, $item);
        }
        if ($want('sla')) {
            self::serviceLevels($doc, $item);
        }
        if ($want('followups')) {
            self::followupSection($doc, $item);
        }
        if ($want('tasks')) {
            self::taskSection($doc, $item);
        }
        if ($want('solution')) {
            self::solutions($doc, $item);
        }
        if ($want('items')) {
            self::linkedItems($doc, $item);
        }
        if ($want('links')) {
            self::linkedItil($doc, $item);
        }
        if ($want('documents')) {
            self::documents($doc, $item);
        }
        if ($want('knowledge')) {
            self::knowledge($doc, $item);
        }
        if ($want('costs')) {
            self::costs($doc, $item);
        }
        if ($want('contracts')) {
            self::contracts($doc, $item);
        }
        if ($want('projects')) {
            self::projects($doc, $item);
        }
        if ($want('satisfaction')) {
            self::satisfaction($doc, $item);
        }
        if ($want('timings')) {
            self::timings($doc, $item);
        }
        if ($want('history')) {
            self::history($doc, $item);
        }

        return $doc;
    }

    // ------------------------------------------------------------- summary

    /** @return array<string,string> */
    private static function identity(CommonITILObject $item): array
    {
        $itemtype = $item::getType();
        $f        = $item->fields;

        $meta = [
            __('Status')       => $itemtype::getStatus((int) $f['status']),
            __('Entity')       => self::dropdown('glpi_entities', (int) $f['entities_id']),
            __('Category')     => self::dropdown('glpi_itilcategories', (int) ($f['itilcategories_id'] ?? 0)),
            __('Location')     => self::dropdown('glpi_locations', (int) ($f['locations_id'] ?? 0)),
            __('Urgency')      => CommonITILObject::getUrgencyName((int) ($f['urgency'] ?? 0)),
            __('Impact')       => CommonITILObject::getImpactName((int) ($f['impact'] ?? 0)),
            __('Priority')     => CommonITILObject::getPriorityName((int) ($f['priority'] ?? 0)),
            __('Approval')     => isset($f['global_validation'])
                ? CommonITILValidation::getStatus((int) $f['global_validation'])
                : '',
            __('Opened')       => self::when($f['date'] ?? null),
            __('Opened by')    => self::dropdown('glpi_users', (int) ($f['users_id_recipient'] ?? 0)),
            __('Last updated') => self::when($f['date_mod'] ?? null),
            __('Last updated by') => self::dropdown('glpi_users', (int) ($f['users_id_lastupdater'] ?? 0)),
            __('Solved')       => self::when($f['solvedate'] ?? null),
            __('Closed')       => self::when($f['closedate'] ?? null),
        ];

        if ($itemtype === 'Ticket') {
            $meta[__('Type')] = \Ticket::getTicketTypeName((int) ($f['type'] ?? 0));
            $meta[__('Request source')] = self::dropdown('glpi_requesttypes', (int) ($f['requesttypes_id'] ?? 0));
        }

        // An external id is what ties this record to whatever raised it, and is
        // the first thing an auditor reconciling two systems looks for.
        if (trim((string) ($f['externalid'] ?? '')) !== '') {
            $meta[__('External ID', 'glpipdf')] = (string) $f['externalid'];
        }

        return $meta;
    }

    /**
     * The analysis fields of a change or a problem.
     *
     * These are free text and they are the substance: a change's rollout and
     * back-out plans are what an approver approved, and a problem's cause is
     * the finding. Printed as written, each under its own heading.
     */
    private static function analysis(Doc $doc, CommonITILObject $item): void
    {
        $fields = $item::getType() === 'Change'
            ? [
                'impactcontent'        => __('Impact analysis', 'glpipdf'),
                'controlistcontent'    => __('Control list', 'glpipdf'),
                'rolloutplancontent'   => __('Deployment plan', 'glpipdf'),
                'backoutplancontent'   => __('Back-out plan', 'glpipdf'),
                'checklistcontent'     => __('Checklist', 'glpipdf'),
            ]
            : [
                'impactcontent'  => __('Impacts', 'glpipdf'),
                'causecontent'   => __('Causes', 'glpipdf'),
                'symptomcontent' => __('Symptoms', 'glpipdf'),
            ];

        $written = [];
        foreach ($fields as $field => $heading) {
            $body = self::readable((string) ($item->fields[$field] ?? ''));
            if ($body !== '') {
                $written[$heading] = $body;
            }
        }

        if ($written === []) {
            return;
        }

        $doc->section(__('Analysis and plans', 'glpipdf'));

        foreach ($written as $heading => $body) {
            $doc->subsection($heading)->text($body);
        }
    }

    // -------------------------------------------------------------- people

    private static function actors(Doc $doc, CommonITILObject $item): void
    {
        $rows = [];

        foreach (
            [
                CommonITILActor::REQUESTER => __('Requester'),
                CommonITILActor::OBSERVER  => __('Observer'),
                CommonITILActor::ASSIGN    => __('Assigned to'),
            ] as $type => $role
        ) {
            foreach ($item->getUsers($type) as $actor) {
                $name = self::dropdown('glpi_users', (int) $actor['users_id']);

                // A requester who raised the ticket by email has no user row;
                // the address is the only thing that identifies them, and
                // dropping the line would lose the actor entirely.
                $rows[] = [
                    $role,
                    __('User'),
                    $name !== '' ? $name : (string) ($actor['alternative_email'] ?? ''),
                    (int) ($actor['use_notification'] ?? 0) === 1 ? __('Yes') : __('No'),
                ];
            }

            foreach ($item->getGroups($type) as $actor) {
                $rows[] = [$role, __('Group'), self::dropdown('glpi_groups', (int) $actor['groups_id']), ''];
            }

            foreach ($item->getSuppliers($type) as $actor) {
                $rows[] = [
                    $role,
                    __('Supplier'),
                    self::dropdown('glpi_suppliers', (int) $actor['suppliers_id']),
                    (int) ($actor['use_notification'] ?? 0) === 1 ? __('Yes') : __('No'),
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('People'))->table(
            [__('Role', 'glpipdf'), __('Type'), __('Name'), __('Notified', 'glpipdf')],
            $rows,
            [22, 16, 46, 16]
        );
    }

    /**
     * Approvals, where the itemtype has them.
     *
     * A record produced for an auditor without its approvals is the wrong
     * record — "who signed this off" is very often the only question being
     * asked of it. Both the request comment and the answer comment are kept:
     * why it was asked for and what the approver said are different facts.
     */
    private static function validations(Doc $doc, CommonITILObject $item): void
    {
        $class = match ($item::getType()) {
            'Ticket' => 'TicketValidation',
            'Change' => 'ChangeValidation',
            default  => null,
        };

        if ($class === null || !class_exists($class)) {
            return;
        }

        /** @var \DBmysql $DB */
        global $DB;

        /** @var class-string<CommonITILValidation> $class */
        $rows = [];

        foreach (
            $DB->request([
                'FROM'  => $class::getTable(),
                'WHERE' => [$class::$items_id => (int) $item->getID()],
                'ORDER' => ['submission_date', 'id'],
            ]) as $row
        ) {
            $comment = trim(implode("\n", array_filter([
                self::readable((string) ($row['comment_submission'] ?? '')),
                self::readable((string) ($row['comment_validation'] ?? '')),
            ])));

            $rows[] = [
                self::validationTarget($row),
                CommonITILValidation::getStatus((int) $row['status']),
                self::when($row['submission_date'] ?? null),
                self::when($row['validation_date'] ?? null),
                $comment,
            ];
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Approvals'))->table(
            [__('Approver'), __('Status'), __('Requested'), __('Answered'), __('Comment')],
            $rows,
            [22, 13, 15, 15, 35]
        );
    }

    /** @param array<string,mixed> $row */
    private static function validationTarget(array $row): string
    {
        $itemtype = (string) ($row['itemtype_target'] ?? '');
        $items_id = (int) ($row['items_id_target'] ?? 0);

        if ($itemtype === 'Group' && $items_id > 0) {
            return self::dropdown('glpi_groups', $items_id);
        }

        if ($itemtype === 'User' && $items_id > 0) {
            return self::dropdown('glpi_users', $items_id);
        }

        // GLPI 11 keeps the older column populated alongside the target pair.
        return self::dropdown('glpi_users', (int) ($row['users_id_validate'] ?? 0));
    }

    /**
     * The agreements the record was measured against, and the targets they set.
     *
     * An SLA breach argument is settled by two things: which agreement applied,
     * and what its target date was at the time. Both are on the row and neither
     * is anywhere else once the agreement is edited.
     */
    private static function serviceLevels(Doc $doc, CommonITILObject $item): void
    {
        $f     = $item->fields;
        $pairs = [];

        foreach (
            [
                'slas_id_tto' => [__('SLA — time to own', 'glpipdf'), 'glpi_slas'],
                'slas_id_ttr' => [__('SLA — time to resolve', 'glpipdf'), 'glpi_slas'],
                'olas_id_tto' => [__('OLA — time to own', 'glpipdf'), 'glpi_olas'],
                'olas_id_ttr' => [__('OLA — time to resolve', 'glpipdf'), 'glpi_olas'],
            ] as $field => [$label, $table]
        ) {
            $pairs[$label] = self::dropdown($table, (int) ($f[$field] ?? 0));
        }

        $pairs[__('Time to own')]     = self::when($f['time_to_own'] ?? null);
        $pairs[__('Time to resolve')] = self::when($f['time_to_resolve'] ?? null);
        $pairs[__('Internal time to own', 'glpipdf')]     = self::when($f['internal_time_to_own'] ?? null);
        $pairs[__('Internal time to resolve', 'glpipdf')] = self::when($f['internal_time_to_resolve'] ?? null);

        // Time the clock was stopped, which is the number that explains a
        // target date nobody can otherwise reconcile with the calendar.
        foreach (
            [
                'sla_waiting_duration' => __('Time excluded by the SLA (waiting)', 'glpipdf'),
                'ola_waiting_duration' => __('Time excluded by the OLA (waiting)', 'glpipdf'),
                'waiting_duration'     => __('Total waiting time', 'glpipdf'),
            ] as $field => $label
        ) {
            $seconds = (int) ($f[$field] ?? 0);
            if ($seconds > 0) {
                $pairs[$label] = self::duration($seconds);
            }
        }

        $pairs = array_filter($pairs, static fn(string $v): bool => trim($v) !== '');

        if ($pairs === []) {
            return;
        }

        $doc->section(__('Service levels', 'glpipdf'))->kv($pairs);
    }

    // ----------------------------------------------------- what was written

    /**
     * Followups, in order.
     *
     * Private entries are included and marked. This is an internal record by
     * default — the same position the SOP plugin takes about its completion
     * followups — and an export that silently dropped half the correspondence
     * would be a worse thing to hand an auditor than one that says which parts
     * the requester never saw.
     */
    private static function followupSection(Doc $doc, CommonITILObject $item): void
    {
        if (!class_exists(ITILFollowup::class)) {
            return;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $entries = [];

        foreach (
            $DB->request([
                'FROM'  => ITILFollowup::getTable(),
                'WHERE' => ['itemtype' => $item::getType(), 'items_id' => (int) $item->getID()],
                'ORDER' => ['date_creation', 'id'],
            ]) as $row
        ) {
            $kind = (int) ($row['is_private'] ?? 0) === 1
                ? __('Private')
                : __('Public');

            $source = self::dropdown('glpi_requesttypes', (int) ($row['requesttypes_id'] ?? 0));
            if ($source !== '') {
                $kind .= '  ·  ' . $source;
            }

            $entries[] = [
                'when' => self::when($row['date_creation'] ?? $row['date'] ?? null),
                'who'  => self::dropdown('glpi_users', (int) ($row['users_id'] ?? 0)),
                'kind' => $kind,
                'body' => self::readable((string) ($row['content'] ?? '')),
            ];
        }

        if ($entries === []) {
            return;
        }

        $doc->section(__('Followups'))->timeline($entries);
    }

    /**
     * Tasks, with the part that makes them evidence: who was assigned, how long
     * it took, and when it was planned for.
     */
    private static function taskSection(Doc $doc, CommonITILObject $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $class = $item::getType() . 'Task';
        if (!class_exists($class)) {
            return;
        }

        /** @var class-string<CommonDBTM> $class */
        $fk   = getForeignKeyFieldForItemType($item::getType());
        $rows = [];

        foreach (
            $DB->request([
                'FROM'  => $class::getTable(),
                'WHERE' => [$fk => (int) $item->getID()],
                'ORDER' => ['date_creation', 'id'],
            ]) as $row
        ) {
            $who = self::dropdown('glpi_users', (int) ($row['users_id_tech'] ?? 0));
            if ($who === '') {
                $who = self::dropdown('glpi_groups', (int) ($row['groups_id_tech'] ?? 0));
            }

            $planned = trim(implode(' → ', array_filter([
                self::when($row['begin'] ?? null),
                self::when($row['end'] ?? null),
            ])));

            $rows[] = [
                self::when($row['date_creation'] ?? $row['date'] ?? null),
                self::dropdown('glpi_taskcategories', (int) ($row['taskcategories_id'] ?? 0)),
                self::readable((string) ($row['content'] ?? ''))
                    . ((int) ($row['is_private'] ?? 0) === 1 ? "\n" . __('Private') : ''),
                $who,
                self::taskState((int) ($row['state'] ?? 0)),
                self::duration((int) ($row['actiontime'] ?? 0)) . ($planned !== '' ? "\n" . $planned : ''),
            ];
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Tasks'))->table(
            [
                __('Created'),
                __('Category'),
                __('Description'),
                __('Technician'),
                __('Status'),
                __('Duration'),
            ],
            $rows,
            [13, 14, 33, 14, 11, 15]
        );
    }

    private static function taskState(int $state): string
    {
        return match ($state) {
            1       => __('To do'),
            2       => __('Done'),
            default => __('Information', 'glpipdf'),
        };
    }

    /**
     * Every solution, not just the last one.
     *
     * A rejected solution followed by an accepted one is two facts, and the
     * rejection is usually the interesting one.
     */
    private static function solutions(Doc $doc, CommonITILObject $item): void
    {
        if (!class_exists(ITILSolution::class)) {
            return;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $found = false;

        foreach (
            $DB->request([
                'FROM'  => ITILSolution::getTable(),
                'WHERE' => ['itemtype' => $item::getType(), 'items_id' => (int) $item->getID()],
                'ORDER' => ['date_creation', 'id'],
            ]) as $row
        ) {
            if (!$found) {
                $doc->section(__('Solution'));
                $found = true;
            }

            $doc->kv([
                __('Type')     => self::dropdown('glpi_solutiontypes', (int) ($row['solutiontypes_id'] ?? 0)),
                __('Author')   => self::dropdown('glpi_users', (int) ($row['users_id'] ?? 0)),
                __('Filed')    => self::when($row['date_creation'] ?? null),
                __('Status')   => self::solutionStatus((int) ($row['status'] ?? 0)),
                __('Approver') => self::dropdown('glpi_users', (int) ($row['users_id_approval'] ?? 0)),
                __('Approved') => self::when($row['date_approval'] ?? null),
            ]);

            $doc->text(self::readable((string) ($row['content'] ?? '')));

            $reason = self::readable((string) ($row['user_approval_comment'] ?? ''));
            if ($reason !== '') {
                $doc->muted(sprintf(__('Approval comment: %s', 'glpipdf'), $reason));
            }

            $doc->spacer();
        }
    }

    private static function solutionStatus(int $status): string
    {
        return match ($status) {
            CommonITILValidation::WAITING  => __('Waiting for approval'),
            CommonITILValidation::ACCEPTED => __('Granted'),
            CommonITILValidation::REFUSED  => _x('validation', 'Refused'),
            default                        => '',
        };
    }

    // ------------------------------------------------------------- what to

    /**
     * The assets the record is about.
     *
     * The link table is joined per itemtype rather than through a helper,
     * because the three of them do not share one: Item_Ticket, Change_Item and
     * Item_Problem are separate classes with separate column names.
     */
    private static function linkedItems(Doc $doc, CommonITILObject $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        [$table, $fk] = match ($item::getType()) {
            'Ticket'  => ['glpi_items_tickets', 'tickets_id'],
            'Change'  => ['glpi_changes_items', 'changes_id'],
            'Problem' => ['glpi_items_problems', 'problems_id'],
            default   => [null, null],
        };

        if ($table === null) {
            return;
        }

        $rows = [];

        foreach (
            $DB->request([
                'FROM'  => $table,
                'WHERE' => [$fk => (int) $item->getID()],
                'ORDER' => ['itemtype', 'items_id'],
            ]) as $row
        ) {
            $itemtype = (string) ($row['itemtype'] ?? '');
            $items_id = (int) ($row['items_id'] ?? 0);

            if (!class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
                continue;
            }

            /** @var CommonDBTM $asset */
            $asset = new $itemtype();

            // A link outlives the asset. A row pointing at an id that no longer
            // exists is stated as such rather than dropped: at audit, "the
            // machine this was about has since been deleted" is a fact.
            if (!$asset->getFromDB($items_id)) {
                $rows[] = [$itemtype::getTypeName(1), sprintf('#%d', $items_id), __('deleted', 'glpipdf'), ''];
                continue;
            }

            $rows[] = [
                $itemtype::getTypeName(1),
                (string) ($asset->fields['name'] ?? ''),
                (string) ($asset->fields['serial'] ?? ''),
                self::dropdown('glpi_states', (int) ($asset->fields['states_id'] ?? 0)),
            ];
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Linked assets', 'glpipdf'))->table(
            [__('Type'), __('Name'), __('Serial number'), __('Status')],
            $rows,
            [22, 40, 22, 16]
        );
    }

    /** Other ITIL records this one is tied to, and how. */
    private static function linkedItil(Doc $doc, CommonITILObject $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $id   = (int) $item->getID();
        $rows = [];

        $add = static function (string $itemtype, int $items_id, string $relation) use (&$rows): void {
            if (!class_exists($itemtype)) {
                return;
            }

            /** @var CommonDBTM $other */
            $other = new $itemtype();
            if (!$other->getFromDB($items_id)) {
                return;
            }

            $rows[] = [
                $itemtype::getTypeName(1),
                sprintf('#%d', $items_id),
                (string) $other->fields['name'],
                $relation,
                $itemtype::getStatus((int) $other->fields['status']),
            ];
        };

        if ($item::getType() === 'Ticket') {
            foreach (
                $DB->request([
                    'FROM'  => 'glpi_tickets_tickets',
                    'WHERE' => ['OR' => [['tickets_id_1' => $id], ['tickets_id_2' => $id]]],
                ]) as $row
            ) {
                $other = (int) $row['tickets_id_1'] === $id
                    ? (int) $row['tickets_id_2']
                    : (int) $row['tickets_id_1'];

                $add('Ticket', $other, self::linkLabel((int) $row['link'], (int) $row['tickets_id_1'] === $id));
            }

            foreach (
                $DB->request(['FROM' => 'glpi_changes_tickets', 'WHERE' => ['tickets_id' => $id]]) as $row
            ) {
                $add('Change', (int) $row['changes_id'], __('Change'));
            }

            foreach (
                $DB->request(['FROM' => 'glpi_problems_tickets', 'WHERE' => ['tickets_id' => $id]]) as $row
            ) {
                $add('Problem', (int) $row['problems_id'], __('Problem'));
            }
        }

        if ($item::getType() === 'Change') {
            foreach (
                $DB->request(['FROM' => 'glpi_changes_tickets', 'WHERE' => ['changes_id' => $id]]) as $row
            ) {
                $add('Ticket', (int) $row['tickets_id'], __('Ticket'));
            }
            foreach (
                $DB->request(['FROM' => 'glpi_changes_problems', 'WHERE' => ['changes_id' => $id]]) as $row
            ) {
                $add('Problem', (int) $row['problems_id'], __('Problem'));
            }
        }

        if ($item::getType() === 'Problem') {
            foreach (
                $DB->request(['FROM' => 'glpi_problems_tickets', 'WHERE' => ['problems_id' => $id]]) as $row
            ) {
                $add('Ticket', (int) $row['tickets_id'], __('Ticket'));
            }
            foreach (
                $DB->request(['FROM' => 'glpi_changes_problems', 'WHERE' => ['problems_id' => $id]]) as $row
            ) {
                $add('Change', (int) $row['changes_id'], __('Change'));
            }
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Linked tickets, changes and problems', 'glpipdf'))->table(
            [__('Type'), __('ID'), __('Title'), __('Relation', 'glpipdf'), __('Status')],
            $rows,
            [14, 10, 42, 18, 16]
        );
    }

    /**
     * The link kinds, read from the correct end.
     *
     * `SON_OF` is stored once and read twice: from the son it means "child of",
     * from the parent it means "parent of". Printing one wording for both would
     * invert half the hierarchy on the page.
     */
    private static function linkLabel(int $link, bool $is_first): string
    {
        return match ($link) {
            2       => __('Duplicate of'),
            3       => $is_first ? __('Child of') : __('Parent of'),
            4       => $is_first ? __('Parent of') : __('Child of'),
            default => __('Linked to'),
        };
    }

    private static function documents(Doc $doc, CommonITILObject $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];

        foreach (
            $DB->request([
                'SELECT'    => ['d.name', 'd.filename', 'd.mime', 'd.sha1sum', 'di.date_creation', 'di.users_id'],
                'FROM'      => 'glpi_documents_items AS di',
                'LEFT JOIN' => ['glpi_documents AS d' => ['ON' => ['di' => 'documents_id', 'd' => 'id']]],
                'WHERE'     => ['di.itemtype' => $item::getType(), 'di.items_id' => (int) $item->getID()],
                'ORDER'     => ['di.date_creation', 'di.id'],
            ]) as $row
        ) {
            if (($row['filename'] ?? null) === null) {
                continue;
            }

            $rows[] = [
                (string) $row['name'],
                (string) $row['filename'],
                self::when($row['date_creation'] ?? null),
                self::dropdown('glpi_users', (int) ($row['users_id'] ?? 0)),
                // The checksum is what makes an attachment evidence rather than
                // a filename: it is how somebody proves the file they were
                // given is the file that was attached.
                substr((string) ($row['sha1sum'] ?? ''), 0, 12),
            ];
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Attached documents', 'glpipdf'))->table(
            [__('Name'), __('File'), __('Attached'), __('By', 'glpipdf'), __('SHA1', 'glpipdf')],
            $rows,
            [26, 26, 16, 16, 16]
        );

        $doc->muted(__('Attachments are listed, not embedded. The SHA1 prefix identifies each '
            . 'file in GLPI’s document store.', 'glpipdf'));
    }

    private static function knowledge(Doc $doc, CommonITILObject $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];

        foreach (
            $DB->request([
                'SELECT'    => ['k.id', 'k.name'],
                'FROM'      => 'glpi_knowbaseitems_items AS ki',
                'LEFT JOIN' => ['glpi_knowbaseitems AS k' => ['ON' => ['ki' => 'knowbaseitems_id', 'k' => 'id']]],
                'WHERE'     => ['ki.itemtype' => $item::getType(), 'ki.items_id' => (int) $item->getID()],
            ]) as $row
        ) {
            if (($row['name'] ?? null) === null) {
                continue;
            }
            $rows[] = [sprintf('#%d', (int) $row['id']), (string) $row['name']];
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Knowledge base articles', 'glpipdf'))
            ->table([__('ID'), __('Title')], $rows, [14, 86]);
    }

    private static function costs(Doc $doc, CommonITILObject $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = match ($item::getType()) {
            'Ticket'  => 'glpi_ticketcosts',
            'Change'  => 'glpi_changecosts',
            'Problem' => 'glpi_problemcosts',
            default   => null,
        };

        if ($table === null) {
            return;
        }

        $fk    = getForeignKeyFieldForItemType($item::getType());
        $rows  = [];
        $total = 0.0;

        foreach (
            $DB->request(['FROM' => $table, 'WHERE' => [$fk => (int) $item->getID()], 'ORDER' => ['begin_date', 'id']]) as $row
        ) {
            $time     = (int) ($row['actiontime'] ?? 0);
            $hours    = $time / 3600;
            $line     = ($hours * (float) ($row['cost_time'] ?? 0))
                      + (float) ($row['cost_fixed'] ?? 0)
                      + (float) ($row['cost_material'] ?? 0);
            $total   += $line;

            $rows[] = [
                (string) ($row['name'] ?? ''),
                trim(implode(' → ', array_filter([
                    self::when($row['begin_date'] ?? null, false),
                    self::when($row['end_date'] ?? null, false),
                ]))),
                self::duration($time),
                self::money($line),
            ];
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Costs'))->table(
            [__('Name'), __('Period'), __('Duration'), __('Total cost')],
            $rows,
            [36, 28, 16, 20]
        );

        $doc->kv([__('Total cost') => self::money($total)]);
    }

    private static function contracts(Doc $doc, CommonITILObject $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];

        foreach (
            $DB->request([
                'SELECT'    => ['c.name', 'c.num', 'c.begin_date', 'c.duration'],
                'FROM'      => 'glpi_tickets_contracts AS tc',
                'LEFT JOIN' => ['glpi_contracts AS c' => ['ON' => ['tc' => 'contracts_id', 'c' => 'id']]],
                'WHERE'     => ['tc.tickets_id' => (int) $item->getID()],
            ]) as $row
        ) {
            if (($row['name'] ?? null) === null) {
                continue;
            }

            $rows[] = [
                (string) $row['name'],
                (string) ($row['num'] ?? ''),
                self::when($row['begin_date'] ?? null, false),
            ];
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Contracts'))->table(
            [__('Name'), __('Number'), __('Start date')],
            $rows,
            [52, 26, 22]
        );
    }

    private static function projects(Doc $doc, CommonITILObject $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];

        foreach (
            $DB->request([
                'SELECT'    => ['pt.id', 'pt.name', 'p.name AS project_name'],
                'FROM'      => 'glpi_projecttasks_tickets AS ptt',
                'LEFT JOIN' => [
                    'glpi_projecttasks AS pt' => ['ON' => ['ptt' => 'projecttasks_id', 'pt' => 'id']],
                    'glpi_projects AS p'      => ['ON' => ['pt' => 'projects_id', 'p' => 'id']],
                ],
                'WHERE'     => ['ptt.tickets_id' => (int) $item->getID()],
            ]) as $row
        ) {
            if (($row['name'] ?? null) === null) {
                continue;
            }
            $rows[] = [(string) ($row['project_name'] ?? ''), (string) $row['name']];
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Project tasks', 'glpipdf'))
            ->table([__('Project'), __('Task')], $rows, [45, 55]);
    }

    private static function satisfaction(Doc $doc, CommonITILObject $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => 'glpi_ticketsatisfactions',
                'WHERE' => ['tickets_id' => (int) $item->getID()],
                'LIMIT' => 1,
            ]) as $row
        ) {
            $answered = self::when($row['date_answered'] ?? null);

            $doc->section(__('Satisfaction survey', 'glpipdf'))->kv([
                __('Sent', 'glpipdf')     => self::when($row['date_begin'] ?? null),
                __('Answered', 'glpipdf') => $answered !== '' ? $answered : __('not answered', 'glpipdf'),
                __('Score', 'glpipdf')    => $answered !== ''
                    ? (string) (float) ($row['satisfaction_scaled_to_5'] ?? $row['satisfaction'] ?? 0)
                    : '',
            ]);

            $comment = self::readable((string) ($row['comment'] ?? ''));
            if ($comment !== '') {
                $doc->text($comment);
            }
        }
    }

    /** The delay statistics core computes, for the reader who came to measure. */
    private static function timings(Doc $doc, CommonITILObject $item): void
    {
        $f = $item->fields;

        $pairs = array_filter([
            __('Taken into account', 'glpipdf') => self::when($f['takeintoaccountdate'] ?? null),
            __('Time to take into account', 'glpipdf') => self::duration((int) ($f['takeintoaccount_delay_stat'] ?? 0)),
            __('Time to solve', 'glpipdf')  => self::duration((int) ($f['solve_delay_stat'] ?? 0)),
            __('Time to close', 'glpipdf')  => self::duration((int) ($f['close_delay_stat'] ?? 0)),
            __('Total duration')            => self::duration((int) ($f['actiontime'] ?? 0)),
        ], static fn(string $v): bool => trim($v) !== '');

        if ($pairs === []) {
            return;
        }

        $doc->section(__('Timings and delays', 'glpipdf'))->kv($pairs);
    }

    /**
     * Every recorded change to the record.
     *
     * The reason this plugin can use the word "audit". Read through
     * `Log::getHistoryData()` because that method resolves `id_search_option`
     * against the itemtype's own search options — turning a number into
     * "Technician" and an id into a name — and a second implementation of that
     * here would be worse and would diverge silently.
     *
     * Its `change` comes back as escaped HTML with `<del>`/`<ins>` around the
     * old and new values, so it is unwrapped rather than re-derived: the
     * wording, including its translation, is core's.
     */
    private static function history(Doc $doc, CommonITILObject $item): void
    {
        if (!class_exists(Log::class)) {
            return;
        }

        $entries = Log::getHistoryData($item, 0, 0);

        if ($entries === []) {
            return;
        }

        // getHistoryData answers newest-first, which is right for a screen
        // somebody is scanning and wrong for a record somebody is reading: an
        // audit trail is read forwards.
        $entries = array_reverse($entries);

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                self::when($entry['date_mod'] ?? null),
                (string) ($entry['user_name'] ?? ''),
                self::unmarkup((string) ($entry['field'] ?? '')),
                self::unmarkup((string) ($entry['change'] ?? '')),
            ];
        }

        // The date column is wide enough for a timestamp on one line. Wrapped,
        // every one of several hundred rows is two lines tall, which is a page
        // of paper per fifty changes.
        $doc->section(__('Field history', 'glpipdf'))->table(
            [__('Date'), __('User'), __('Field'), __('Change', 'glpipdf')],
            $rows,
            [18, 16, 20, 46]
        );

        $doc->muted(sprintf(
            _n('%d recorded change, oldest first.', '%d recorded changes, oldest first.', count($rows), 'glpipdf'),
            count($rows)
        ));
    }

    // ------------------------------------------------------------- plumbing

    private static function dropdown(string $table, int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $name = \Dropdown::getDropdownName($table, $id);

        // getDropdownName answers with a non-breaking space for a row it cannot
        // find, which would print as a blank cell that looks like data.
        return $name === '&nbsp;' ? '' : (string) $name;
    }

    private static function when(mixed $stamp, bool $withTime = true): string
    {
        $stamp = (string) ($stamp ?? '');

        if ($stamp === '' || str_starts_with($stamp, '0000')) {
            return '';
        }

        return $withTime ? (string) Html::convDateTime($stamp) : (string) Html::convDate($stamp);
    }

    /** Seconds as something a person reads. Blank for zero, which means "not set". */
    private static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }

        $days  = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $mins  = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf(__('%1$dd %2$dh', 'glpipdf'), $days, $hours);
        }

        return $hours > 0
            ? sprintf(__('%1$dh %2$dm', 'glpipdf'), $hours, $mins)
            : sprintf(__('%dm', 'glpipdf'), $mins);
    }

    private static function money(float $amount): string
    {
        return \Html::formatNumber($amount);
    }

    /**
     * Core markup, as plain text.
     *
     * `Log::getHistoryData()` returns already-escaped HTML. Stripping the tags
     * and decoding the entities is what turns `<del>Low</del>` back into `Low`
     * — and doing it in that order matters, because decoding first would turn
     * an escaped `&lt;b&gt;` in somebody's ticket title into a tag that
     * strip_tags then removed.
     */
    private static function unmarkup(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * GLPI's rich text, as something worth printing.
     *
     * Ticket bodies are HTML — pasted mail, a signature block, an inline
     * screenshot. The document model takes plain text, so this is where the
     * conversion happens, and it has to keep the paragraph breaks: a
     * three-paragraph description flattened into one is the difference between
     * a readable record and a wall.
     *
     * Images are dropped rather than fetched. An inline `<img>` in a ticket is
     * a GLPI document reference behind a session, and a renderer that followed
     * it would either fetch nothing — a broken box on the page — or turn every
     * export into an outbound request from the server. A marker says one was
     * there, which is what a reader needs in order to go and look.
     */
    private static function readable(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $text = str_ireplace(
            ['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</tr>'],
            "\n",
            $html
        );

        $text = (string) preg_replace('#<img\b[^>]*>#i', ' [' . __('image', 'glpipdf') . '] ', $text);
        $text = (string) preg_replace('#<li\b[^>]*>#i', '• ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Mail quoting and editor markup leave long runs of blank lines that
        // eat a page each.
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
