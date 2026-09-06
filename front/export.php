<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Produce a PDF and hand it over.
 *
 * Two entry points, one page:
 *
 *  - `?itemtype=…&items_id=…` — the link on an item's form.
 *  - `?bulk=1` — the redirect target of the massive action, which left its
 *    selection in the session. See GlpiPlugin\Glpipdf\Bulk.
 *
 * Everything arrives from the browser, so nothing is taken on trust: the item
 * is re-read from the database and re-checked against the caller's own rights
 * on every request. Without that, a URL with an id in it would be a way to read
 * any ticket in the instance.
 *
 * A failure renders an error page rather than a zero-byte download. A browser
 * handed an empty `application/pdf` shows "failed to load document", which
 * tells the person nothing about why.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpipdf\Bulk;
use GlpiPlugin\Glpipdf\Export;

Session::checkLoginUser();

if (!Export::permitted()) {
    Html::displayRightError();
}

// ------------------------------------------------------------------- bulk

if (!empty($_GET['bulk'])) {
    $selection = Bulk::take();

    if ($selection === null) {
        Html::displayErrorAndDie(
            __('There is nothing waiting to be exported. Make a selection and try again.', 'glpipdf')
        );
    }

    $result = Export::many($selection['itemtype'], $selection['ids'], $selection['key']);

    if ($result === null) {
        Html::displayErrorAndDie(
            __('None of the selected items could be exported.', 'glpipdf')
        );
    }

    Export::stream($result['filename'], $result['bytes']);
}

// --------------------------------------------------------------- one item

$itemtype = (string) ($_GET['itemtype'] ?? '');
$items_id = (int) ($_GET['items_id'] ?? 0);
$key      = (string) ($_GET['key'] ?? '');

// `chose` is what separates "the reader unticked everything" from "nobody was
// asked" — an empty `parts` with no `chose` is a plain link and means the
// defaults, while an empty `parts` *with* it is a deliberate, if odd, choice.
$parts = isset($_GET['chose'])
    ? array_map('strval', (array) ($_GET['parts'] ?? []))
    : null;

$result = Export::one($itemtype, $items_id, $key, $parts);

if ($result === null) {
    // One message for "no such item", "not yours to read" and "nothing can be
    // rendered for this type". Distinguishing them out loud would turn this
    // page into a way to ask whether ticket #4172 exists.
    //
    // A named document that exists but has nothing to say is the one case
    // worth separating, because it is not an error on the reader's part and
    // the link that led here should not have been offered — see
    // Registry::available().
    $item = Export::load($itemtype, $items_id);

    Html::displayErrorAndDie(
        $item !== null && $key !== ''
            ? __('There is nothing to put in that document yet.', 'glpipdf')
            : __('That item cannot be exported.', 'glpipdf')
    );
}

Export::stream($result['filename'], $result['bytes']);
