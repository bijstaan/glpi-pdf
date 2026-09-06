#!/bin/sh
# doc.php is pure PHP — no DB, no GLPI bootstrap — and runs anywhere, including
# in CI's bare php:8.3 container. It covers the contract this plugin offers to
# every other one: what a Doc accepts, what it drops, and what a file ends up
# called.
#
# class-load.php is the other half and cannot run here: it boots a GLPI kernel
# to catch the inheritance-signature fatals `php -l` structurally cannot see
# (this plugin shipped one on its first run — a private writeHtml() colliding
# with TCPDF's writeHTML()). Run it against the dev instance:
#
#   docker exec glpi-glpi-1 php /var/www/glpi/plugins/glpipdf/tests/class-load.php \
#       glpipdf glpisop glpichange glpimajor glpiservice glpireport
#
# The rendering itself — fonts, page breaks, the branded masthead, the download
# and the bulk action — is exercised in a real browser against real records by
# glpi-pdf/tests/browser/pdf-check.js.
set -e

cd "$(dirname "$0")/.."

status=0
php tests/doc.php || status=1

exit $status
