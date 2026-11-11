<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * German strings for tincanlaunch
 *
 * You can have a rather longer description of the file as well,
 * if you like, and it can span multiple lines.
 *
 * @package mod_tincanlaunch
 * @copyright  2013 Andrew Downes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activityidmismatch'] = 'Das verkn&uuml;pfte Paket wurde nicht aktualisiert, weil sich die Activity-ID von "{$a->current}" zu "{$a->candidate}" ge&auml;ndert hat.';
$string['everyday'] = 'T&auml;glich';
$string['manifestactivityidmissing'] = 'Das Paketmanifest enth&auml;lt keine Activity-ID.';
$string['manifestlaunchmissing'] = 'Das Paketmanifest enth&auml;lt keinen Startpfad.';
$string['packageextractfailed'] = 'Das Paket konnte nicht entpackt werden.';
$string['packagefilenotfound'] = 'Die Paketdatei wurde nicht gefunden.';
$string['packagesourcemissing'] = 'Die Quelle des verkn&uuml;pften Pakets fehlt oder ist nicht verf&uuml;gbar.';
$string['packagesyncfailed'] = 'Das verkn&uuml;pfte Paket konnte nicht synchronisiert werden: {$a}';
$string['packageupdateemailbody'] = 'Das verkn&uuml;pfte xAPI-Paket f&uuml;r "{$a->activity}" auf "{$a->site}" wurde nicht aktiviert. Lernende verwenden weiterhin das zuletzt akzeptierte Paket.' . "\n\n" . 'Fehler: {$a->error}';
$string['packageupdateemailsubject'] = 'xAPI-Paketaktualisierung abgelehnt: {$a->activity}';
$string['packageupdateinprogress'] = 'Dieses Paket wird bereits aktualisiert. Versuchen Sie es in K&uuml;rze erneut.';
$string['packageupdatenotlinked'] = 'Nur verkn&uuml;pfte Repository-Pakete k&ouml;nnen aktualisiert werden.';
$string['packageupdatesuccess'] = 'Das verkn&uuml;pfte Paket wurde aktualisiert.';
$string['packageupdateunchanged'] = 'Das verkn&uuml;pfte Paket wurde nicht ge&auml;ndert.';
$string['tincanlaunch_attempt'] = 'Neuer Versuch';
$string['tincanlaunch_completed'] = 'Erleben Sie komplett!';
$string['tincanlaunch_notavailable'] = 'Das Learning Record Store ist nicht verf&uuml;gbar. Bitte wenden Sie sich an den Systemadministrator.';
$string['tincanlaunch_progress'] = 'Versuch im Gange';
$string['tincanlaunchviewfirstlaunched'] = 'Zun&auml;chst startete';
$string['tincanlaunchviewlastlaunched'] = 'Letzte gestartet';
$string['tincanlaunchviewlaunchlink'] = 'Start';
$string['tincanlaunchviewlaunchlinkheader'] = 'Start Link';
$string['updatefreq'] = 'H&auml;ufigkeit der automatischen Aktualisierung';
$string['updatefreq_error'] = 'Die automatische Aktualisierung kann nur aktiviert werden, wenn die Paketdatei ein Repository-Alias ist.';
$string['updatefreq_help'] = 'Pr&uuml;ft einen Repository-Alias t&auml;glich auf ein ge&auml;ndertes Paket. Manager k&ouml;nnen die Pr&uuml;fung auch auf der Aktivit&auml;tsseite starten. Hochgeladene Kopien k&ouml;nnen nicht automatisch aktualisiert werden.';
$string['updatefreqdesc'] = 'Legt fest, ob verkn&uuml;pfte Paketdateien durch die t&auml;gliche geplante Aufgabe gepr&uuml;ft werden.';
$string['updatepackage'] = 'Nach Paketaktualisierung suchen';
$string['updatepackagestask'] = 'Verkn&uuml;pfte xAPI-Pakete aktualisieren';
