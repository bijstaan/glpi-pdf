// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * GLPI PDF — the three convenience buttons on the PDF tab.
 *
 * Delegated from the document rather than bound on load: the tab's content
 * arrives over ajax after this file has run, and re-renders whenever somebody
 * switches tabs. Nothing bound directly would survive that.
 *
 * "Back to the usual set" restores each box to the state the server rendered it
 * in, read off the DOM through `defaultChecked` rather than from a list kept
 * here — the defaults belong to the plugin that declared the part, and a copy
 * of them in JavaScript is a copy that goes stale.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest
            ? event.target.closest('[data-glpipdf-all],[data-glpipdf-none],[data-glpipdf-reset]')
            : null;

        if (!button) {
            return;
        }

        var form = button.closest('form');
        if (!form) {
            return;
        }

        event.preventDefault();

        var boxes = form.querySelectorAll('input[type="checkbox"][name="parts[]"]');

        Array.prototype.forEach.call(boxes, function (box) {
            if (button.hasAttribute('data-glpipdf-all')) {
                box.checked = true;
            } else if (button.hasAttribute('data-glpipdf-none')) {
                box.checked = false;
            } else {
                box.checked = box.defaultChecked;
            }
        });
    }, false);
}());
