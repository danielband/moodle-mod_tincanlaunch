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
 * Unit tests for mod_tincanlaunch backup and restore.
 *
 * @package    mod_tincanlaunch
 * @copyright  2024 David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_tincanlaunch;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/mod/tincanlaunch/lib.php');

/**
 * Unit tests for mod_tincanlaunch backup and restore functionality.
 *
 * @package    mod_tincanlaunch
 * @copyright  2024 David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \backup_tincanlaunch_activity_task
 * @covers \restore_tincanlaunch_activity_task
 */
final class backup_restore_test extends \advanced_testcase {
    /**
     * Restoring preserves the Active Revision and reconnects only same-site aliases.
     *
     * @dataProvider revision_launches
     * @param string $launch Launch path including any query and fragment.
     * @param bool $samesite Whether the backup originates from this site.
     */
    public function test_duplicate_preserves_linked_active_revision(string $launch, bool $samesite = true): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/repository/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/');
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('tincanlaunch', [
            'course' => $course->id,
            'tincanlaunchtype' => 0,
            'tincanactivityid' => 'https://example.com/activity',
            'revision' => 7,
            'packagehash' => str_repeat('a', 40),
            'packageerrorhash' => str_repeat('b', 40),
            'updatefreq' => 2,
            'grade' => 100,
        ]);
        $context = \context_module::instance($activity->cmid);
        $DB->set_field(
            'tincanlaunch',
            'tincanlaunchurl',
            $CFG->wwwroot . '/pluginfile.php/' . $context->id . '/mod_tincanlaunch/content/7/' . $launch,
            ['id' => $activity->id]
        );
        $directory = make_writable_directory($CFG->dataroot . '/repository/tincanlaunch-backup');
        $archive = new \ZipArchive();
        $archive->open($directory . '/package.zip', \ZipArchive::CREATE);
        $archive->addFromString('tincan.xml', '<tincan/>');
        $archive->addFromString('index.html', 'Active content');
        $archive->close();
        $this->getDataGenerator()->create_repository_type('filesystem');
        $repository = $this->getDataGenerator()->create_repository('filesystem', ['fs_path' => 'tincanlaunch-backup']);
        $storage = get_file_storage();
        $storage->create_file_from_reference([
            'contextid' => $context->id,
            'component' => 'mod_tincanlaunch',
            'filearea' => 'package',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'package.zip',
        ], $repository->id, 'package.zip');
        $storage->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_tincanlaunch',
            'filearea' => 'content',
            'itemid' => 7,
            'filepath' => '/',
            'filename' => 'index.html',
        ], 'Active content');
        $cm = get_coursemodule_from_instance('tincanlaunch', $activity->id);

        $duplicate = $samesite ? duplicate_module($course, $cm) : $this->restore_foreign_activity($course);

        $restored = $DB->get_record('tincanlaunch', ['id' => $duplicate->instance], '*', MUST_EXIST);
        $this->assertSame(7, (int)$restored->revision);
        $this->assertSame(str_repeat('a', 40), $restored->packagehash);
        $this->assertSame($samesite ? 2 : 0, (int)$restored->updatefreq);
        $this->assertSame(100, (int)$restored->grade);
        $this->assertNull($restored->packageerrorhash);
        $newcontext = \context_module::instance($duplicate->id);
        $this->assertStringContainsString(
            '/pluginfile.php/' . $newcontext->id . '/mod_tincanlaunch/content/7/' . $launch,
            $restored->tincanlaunchurl
        );
        $content = $storage->get_file($newcontext->id, 'mod_tincanlaunch', 'content', 7, '/', 'index.html');
        $this->assertSame('Active content', $content->get_content());
        $packages = $storage->get_area_files($newcontext->id, 'mod_tincanlaunch', 'package', 0, 'id', false);
        $this->assertCount($samesite ? 1 : 0, $packages);
        if ($samesite) {
            $this->assertTrue(reset($packages)->is_external_file());
        }
    }

    /**
     * Local launch URLs supported by existing activities.
     *
     * @return array
     */
    public static function revision_launches(): array {
        return [
            'plain launch' => ['index.html'],
            'query and fragment' => ['index.html?mode=review&lang=en#slide2'],
            'foreign site' => ['index.html', false],
        ];
    }

    /**
     * Restore a course backup with a different source site identifier.
     *
     * @param \stdClass $course Source course.
     * @return \stdClass Restored course module.
     */
    private function restore_foreign_activity(\stdClass $course): \stdClass {
        global $DB, $USER;

        $backup = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $backup->execute_plan();
        $file = $backup->get_results()['backup_destination'];
        $directory = 'test-foreign-tincanlaunch';
        $path = make_backup_temp_directory($directory);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);
        $backup->destroy();
        $manifest = simplexml_load_file($path . '/moodle_backup.xml');
        $manifest->information->original_site_identifier_hash = 'foreign-site';
        $manifest->asXML($path . '/moodle_backup.xml');
        $courseid = \restore_dbops::create_new_course('Foreign restore', 'foreign-restore', $course->category);
        $restore = new \restore_controller(
            $directory,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertFalse($restore->is_samesite());
        $restore->execute_precheck();
        $restore->execute_plan();
        $restore->destroy();
        $activity = $DB->get_record('tincanlaunch', ['course' => $courseid], '*', MUST_EXIST);
        return get_coursemodule_from_instance('tincanlaunch', $activity->id);
    }

    /**
     * Test backup and restore of a basic tincanlaunch instance.
     */
    public function test_backup_restore_basic(): void {
        global $DB, $USER, $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/');  // Suppress output from backup/restore.

        // Create a course with a tincanlaunch instance.
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('tincanlaunch', [
            'course' => $course->id,
            'name' => 'Test xAPI Activity',
            'intro' => 'This is a test xAPI activity',
            'introformat' => FORMAT_HTML,
            'tincanlaunchurl' => 'https://example.com/activity/index.html',
            'tincanactivityid' => 'https://example.com/activity',
            'tincanverbid' => 'http://adlnet.gov/expapi/verbs/completed',
            'tincanexpiry' => 30,
            'tincanmultipleregs' => 1,
            'tincansimplelaunchnav' => 0,
        ]);

        // Backup the course.
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];

        // Extract backup.
        $fp = get_file_packer('application/vnd.moodle.backup');
        $tempdir = make_backup_temp_directory('test-restore-tincanlaunch');
        $file->extract_to_pathname($fp, $tempdir);
        $bc->destroy();

        // Restore to a new course.
        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname . ' (restored)',
            $course->shortname . '_restored',
            $course->category
        );
        $rc = new \restore_controller(
            'test-restore-tincanlaunch',
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        // Verify restored instance.
        $restoredinstances = $DB->get_records('tincanlaunch', ['course' => $newcourseid]);
        $this->assertCount(1, $restoredinstances);

        $restored = reset($restoredinstances);
        $this->assertEquals('Test xAPI Activity', $restored->name);
        $this->assertEquals('This is a test xAPI activity', $restored->intro);
        $this->assertEquals('https://example.com/activity/index.html', $restored->tincanlaunchurl);
        $this->assertEquals('https://example.com/activity', $restored->tincanactivityid);
        $this->assertEquals('http://adlnet.gov/expapi/verbs/completed', $restored->tincanverbid);
        $this->assertEquals(30, $restored->tincanexpiry);
        $this->assertEquals(1, $restored->tincanmultipleregs);
        $this->assertEquals(0, $restored->tincansimplelaunchnav);
    }

    /**
     * Test backup and restore of a tincanlaunch instance with LRS overrides.
     */
    public function test_backup_restore_with_lrs_override(): void {
        global $DB, $USER, $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectOutputRegex('/.*/');  // Suppress output from backup/restore.

        // Create a course with a tincanlaunch instance that overrides LRS settings.
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('tincanlaunch', [
            'course' => $course->id,
            'name' => 'Custom LRS Activity',
            'overridedefaults' => 1,
            'tincanlaunchlrsendpoint' => 'https://custom-lrs.example.com/endpoint/',
            'tincanlaunchlrsauthentication' => 1,
            'tincanlaunchlrslogin' => 'customkey',
            'tincanlaunchlrspass' => 'customsecret',
            'tincanlaunchlrsduration' => 5000,
            'tincanlaunchcustomacchp' => 'https://myschool.example.com',
            'tincanlaunchuseactoremail' => 0,
        ]);

        // Verify the LRS record was created.
        $originallrs = $DB->get_record('tincanlaunch_lrs', ['tincanlaunchid' => $instance->id]);
        $this->assertNotFalse($originallrs);

        // Backup the course.
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];

        // Extract and restore.
        $fp = get_file_packer('application/vnd.moodle.backup');
        $tempdir = make_backup_temp_directory('test-restore-tincanlaunch-lrs');
        $file->extract_to_pathname($fp, $tempdir);
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname . ' (restored)',
            $course->shortname . '_restored2',
            $course->category
        );
        $rc = new \restore_controller(
            'test-restore-tincanlaunch-lrs',
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        // Verify restored instance and LRS settings.
        $restoredinstances = $DB->get_records('tincanlaunch', ['course' => $newcourseid]);
        $this->assertCount(1, $restoredinstances);

        $restored = reset($restoredinstances);
        $this->assertEquals('Custom LRS Activity', $restored->name);
        $this->assertEquals(1, $restored->overridedefaults);

        // Verify LRS record was restored.
        $restoredlrs = $DB->get_record('tincanlaunch_lrs', ['tincanlaunchid' => $restored->id]);
        $this->assertNotFalse($restoredlrs);
        $this->assertEquals('https://custom-lrs.example.com/endpoint/', $restoredlrs->lrsendpoint);
        $this->assertEquals(1, $restoredlrs->lrsauthentication);
        $this->assertEquals('customkey', $restoredlrs->lrslogin);
        $this->assertEquals('customsecret', $restoredlrs->lrspass);
        $this->assertEquals(5000, $restoredlrs->lrsduration);
        $this->assertEquals('https://myschool.example.com', $restoredlrs->customacchp);
        $this->assertEquals(0, $restoredlrs->useactoremail);
    }
}
