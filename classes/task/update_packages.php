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

namespace mod_tincanlaunch\task;

/**
 * Performs daily Linked Refreshes.
 *
 * @package    mod_tincanlaunch
 * @copyright  2026 TAWK GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_packages extends \core\task\scheduled_task {
    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('updatepackagestask', 'mod_tincanlaunch');
    }

    /**
     * Refresh Linked Packages configured for daily checks.
     */
    public function execute() {
        global $CFG;

        require_once($CFG->dirroot . '/mod/tincanlaunch/locallib.php');
        tincanlaunch_refresh_packages();
    }
}
