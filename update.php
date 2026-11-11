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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Manually perform a Linked Refresh.
 *
 * @package    mod_tincanlaunch
 * @copyright  2026 TAWK GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('tincanlaunch', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$activity = $DB->get_record('tincanlaunch', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);

if (!data_submitted()) {
    throw new moodle_exception('invalidrequest');
}
require_sesskey();

$returnurl = new moodle_url('/mod/tincanlaunch/view.php', ['id' => $cm->id]);
$filestorage = get_file_storage();
$packages = $filestorage->get_area_files($context->id, 'mod_tincanlaunch', 'package', 0, 'id', false);
$package = $packages ? reset($packages) : null;
if (!empty($activity->tincanlaunchtype) || !$package || !$package->is_external_file()) {
    redirect(
        $returnurl,
        get_string('packageupdatenotlinked', 'tincanlaunch'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$packer = get_file_packer('application/zip');
$package = new \mod_tincanlaunch\local\package(
    (int)$activity->id,
    $DB,
    $filestorage,
    $packer,
    \core\lock\lock_config::get_lock_factory('mod_tincanlaunch_package_update')
);
$result = $package->refresh();
if ($result->get_status() === \mod_tincanlaunch\local\package_refresh_result::UPDATED) {
    $message = get_string('packageupdatesuccess', 'tincanlaunch');
    redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}
if ($result->get_status() === \mod_tincanlaunch\local\package_refresh_result::UNCHANGED) {
    $message = get_string('packageupdateunchanged', 'tincanlaunch');
    redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

$message = $result->get_status() === \mod_tincanlaunch\local\package_refresh_result::BUSY
    ? get_string('packageupdateinprogress', 'tincanlaunch')
    : $result->get_rejection()->getMessage();
redirect($returnurl, $message, null, \core\output\notification::NOTIFY_ERROR);
