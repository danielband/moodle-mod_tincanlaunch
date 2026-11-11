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

namespace mod_tincanlaunch;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/tincanlaunch/lib.php');
require_once($CFG->dirroot . '/mod/tincanlaunch/locallib.php');
require_once($CFG->dirroot . '/repository/lib.php');

/**
 * Integration tests for Linked Package refreshes.
 *
 * @package    mod_tincanlaunch
 * @copyright  2026 TAWK GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_tincanlaunch\local\package
 */
final class package_refresh_test extends \advanced_testcase {
    /** @var string Repository package pathname. */
    private string $packagepath;

    /**
     * Set up an isolated filesystem repository source.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $CFG;
        $directory = make_writable_directory($CFG->dataroot . '/repository/tincanlaunch-refresh');
        $this->packagepath = $directory . '/package.zip';
    }

    /**
     * A changed Repository Alias activates new content without losing the alias.
     */
    public function test_refresh_activates_changed_repository_alias(): void {
        global $DB;

        $activity = $this->create_linked_activity();
        $context = \context_module::instance($activity->cmid);
        $this->write_package('Revision two');
        $DB->set_field('files_reference', 'lastsync', 0);

        $result = $this->package((int)$activity->id)->refresh();

        $this->assertSame('updated', $result->get_status());
        $updated = $DB->get_record('tincanlaunch', ['id' => $activity->id], '*', MUST_EXIST);
        $this->assertSame(2, (int)$updated->revision);
        $this->assertSame('https://example.com/activity', $updated->tincanactivityid);
        $this->assertStringEndsWith('/mod_tincanlaunch/content/2/index.html', $updated->tincanlaunchurl);
        $content = get_file_storage()->get_file(
            $context->id,
            'mod_tincanlaunch',
            'content',
            2,
            '/',
            'index.html'
        );
        $this->assertSame('Revision two', $content->get_content());
        $packages = get_file_storage()->get_area_files(
            $context->id,
            'mod_tincanlaunch',
            'package',
            0,
            'id',
            false
        );
        $this->assertCount(1, $packages);
        $this->assertTrue(reset($packages)->is_external_file());
        $this->assertSame('package.zip', reset($packages)->get_reference());
    }

    /**
     * Unchanged content retains its Active Revision and clears a previous rejection.
     */
    public function test_unchanged_package_preserves_active_revision(): void {
        global $DB;

        $activity = $this->create_linked_activity();
        $DB->set_field('tincanlaunch', 'packageerrorhash', str_repeat('a', 40), ['id' => $activity->id]);

        $result = $this->package((int)$activity->id)->refresh();

        $this->assertSame('unchanged', $result->get_status());
        $updated = $DB->get_record('tincanlaunch', ['id' => $activity->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$updated->revision);
        $this->assertSame($activity->packagehash, $updated->packagehash);
        $this->assertSame($activity->tincanlaunchurl, $updated->tincanlaunchurl);
        $this->assertNull($updated->packageerrorhash);
    }

    /**
     * A rejected Candidate Revision leaves learner content and identity unchanged.
     *
     * @dataProvider rejected_candidates
     * @param string $candidatexapiactivityid Candidate xAPI Activity ID.
     * @param string $launch Candidate launch path.
     * @param string|null $manifest Override manifest XML.
     * @param string $errorcode Expected rejection reason.
     */
    public function test_rejected_candidate_preserves_active_revision(
        string $candidatexapiactivityid,
        string $launch,
        ?string $manifest,
        string $errorcode
    ): void {
        global $DB;

        $activity = $this->create_linked_activity();
        $this->write_package('Rejected candidate', $candidatexapiactivityid, $launch, $manifest);
        $DB->set_field('files_reference', 'lastsync', 0);

        $result = $this->package((int)$activity->id)->refresh();

        $this->assertSame('rejected', $result->get_status());
        $this->assertSame($errorcode, $result->get_rejection()->errorcode);
        $unchanged = $DB->get_record('tincanlaunch', ['id' => $activity->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$unchanged->revision);
        $this->assertSame($activity->packagehash, $unchanged->packagehash);
        $this->assertSame('https://example.com/activity', $unchanged->tincanactivityid);
        $this->assertSame($activity->tincanlaunchurl, $unchanged->tincanlaunchurl);
        $content = get_file_storage()->get_file(
            \context_module::instance($activity->cmid)->id,
            'mod_tincanlaunch',
            'content',
            1,
            '/',
            'index.html'
        );
        $this->assertSame('Revision one', $content->get_content());
    }

    /**
     * Candidate Revisions that must not replace the Active Revision.
     *
     * @return array
     */
    public static function rejected_candidates(): array {
        return [
            'different identity' => ['https://example.com/other', 'index.html', null, 'activityidmismatch'],
            'missing identity' => ['', 'index.html', null, 'manifestactivityidmissing'],
            'missing launch' => ['https://example.com/activity', '', null, 'manifestlaunchmissing'],
            'malformed manifest' => ['https://example.com/activity', 'index.html', '<tincan>', 'badmanifest'],
        ];
    }

    /**
     * Repository synchronization during inspection must not change the Candidate Fingerprint.
     */
    public function test_rejection_fingerprint_uses_inspected_candidate_identity(): void {
        global $DB;

        $activity = $this->create_linked_activity();
        $contenthash = str_repeat('a', 40);
        $candidate = $this->getMockBuilder(\stored_file::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'is_external_file',
                'get_reference',
                'import_external_file_contents',
                'get_status',
                'get_contenthash',
                'extract_to_pathname',
            ])
            ->getMock();
        $candidate->method('is_external_file')->willReturn(true);
        $candidate->method('get_reference')->willReturn('package.zip');
        $candidate->method('get_status')->willReturn(0);
        $candidate->method('get_contenthash')->willReturnCallback(static function () use (&$contenthash) {
            return $contenthash;
        });
        $candidate->method('extract_to_pathname')->willReturnCallback(static function () use (&$contenthash) {
            $contenthash = str_repeat('b', 40);
            return false;
        });
        $storage = $this->getMockBuilder(\file_storage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_area_files'])
            ->getMock();
        $storage->method('get_area_files')->willReturn([$candidate]);
        $package = new local\package(
            (int)$activity->id,
            $DB,
            $storage,
            get_file_packer('application/zip'),
            \core\lock\lock_config::get_lock_factory('mod_tincanlaunch_package_update')
        );

        $result = $package->refresh();

        $this->assertSame('rejected', $result->get_status());
        $this->assertSame('badarchive', $result->get_rejection()->errorcode);
        $this->assertSame('c7dcf4b89808e8c94fb351c02ff84427a7d0d053', $result->get_rejection()->get_fingerprint());
    }

    /**
     * A competing Linked Refresh leaves the Active Revision untouched.
     */
    public function test_busy_package_can_be_refreshed_after_lock_release(): void {
        global $DB;

        $activity = $this->create_linked_activity();
        $this->write_package('Revision two');
        $DB->set_field('files_reference', 'lastsync', 0);
        $factory = new \core\lock\file_lock_factory('mod_tincanlaunch_package_update');
        $lock = $factory->get_lock((string)$activity->id, 0);
        $this->assertNotFalse($lock);

        try {
            $result = $this->package((int)$activity->id, $factory)->refresh();
            $this->assertSame('busy', $result->get_status());
            $this->assertSame(1, (int)$DB->get_field('tincanlaunch', 'revision', ['id' => $activity->id]));
        } finally {
            $lock->release();
        }

        $result = $this->package((int)$activity->id, $factory)->refresh();
        $this->assertSame('updated', $result->get_status());
        $this->assertSame(2, (int)$DB->get_field('tincanlaunch', 'revision', ['id' => $activity->id]));
    }

    /**
     * The daily task refreshes only opted-in ZIP activities.
     *
     * @covers \mod_tincanlaunch\task\update_packages
     */
    public function test_daily_task_refreshes_only_opted_in_zip_activities(): void {
        global $DB;

        $never = $this->create_linked_activity();
        $daily = $this->create_linked_activity();
        $external = $this->create_linked_activity();
        $DB->set_field('tincanlaunch', 'updatefreq', 2, ['id' => $daily->id]);
        $DB->update_record('tincanlaunch', (object)[
            'id' => $external->id,
            'tincanlaunchtype' => 1,
            'updatefreq' => 2,
        ]);
        $this->write_package('Revision two');
        $DB->set_field('files_reference', 'lastsync', 0);
        $this->expectOutputString('Updated Tin Can package for activity ' . $daily->id . ".\n");

        (new task\update_packages())->execute();

        $this->assertSame(1, (int)$DB->get_field('tincanlaunch', 'revision', ['id' => $never->id]));
        $this->assertSame(2, (int)$DB->get_field('tincanlaunch', 'revision', ['id' => $daily->id]));
        $this->assertSame(1, (int)$DB->get_field('tincanlaunch', 'revision', ['id' => $external->id]));
    }

    /**
     * Batch rejections notify the administrator once per Candidate Fingerprint.
     *
     * @covers ::tincanlaunch_refresh_packages
     * @covers ::tincanlaunch_notify_package_rejection
     */
    public function test_daily_rejection_notifications_are_deduplicated(): void {
        global $DB;

        $activity = $this->create_linked_activity();
        $DB->set_field('tincanlaunch', 'updatefreq', 2, ['id' => $activity->id]);
        $this->write_package('Rejected candidate', 'https://example.com/other');
        $DB->set_field('files_reference', 'lastsync', 0);
        $sink = $this->redirectEmails();
        ob_start();
        try {
            $result = $this->package((int)$activity->id)->refresh();
            $this->assertTrue(tincanlaunch_notify_package_rejection($activity, $result));
            tincanlaunch_refresh_packages();
            tincanlaunch_refresh_packages();
            $emails = $sink->get_messages();
            $this->assertCount(1, $emails);
            $this->assertSame(get_admin()->email, $emails[0]->to);
            $this->assertSame('xAPI package update rejected: Linked xAPI activity', $emails[0]->subject);
            $this->assertStringContainsString('https://example.com/other', $emails[0]->body);

            $this->write_package('Different rejected candidate', 'https://example.com/other');
            $DB->set_field('files_reference', 'lastsync', 0);
            tincanlaunch_refresh_packages();
            $this->assertCount(2, $sink->get_messages());
            $this->assertSame(1, (int)$DB->get_field('tincanlaunch', 'revision', ['id' => $activity->id]));
        } finally {
            ob_end_clean();
            $sink->close();
        }
    }

    /**
     * The upgrade preserves legacy item-ID-zero content and launch URLs.
     *
     * @covers \xmldb_tincanlaunch_upgrade
     */
    public function test_upgrade_preserves_legacy_package_content(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/tincanlaunch/db/upgrade.php');
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('tincanlaunch', [
            'course' => $course->id,
            'tincanlaunchtype' => 0,
            'grade' => 100,
        ]);
        $context = \context_module::instance($activity->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_tincanlaunch',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'index.html',
        ], 'Legacy content');
        $table = new \xmldb_table('tincanlaunch');
        foreach (['packageerrorhash', 'updatefreq', 'revision', 'packagehash'] as $name) {
            $DB->get_manager()->drop_field($table, new \xmldb_field($name));
        }
        set_config('version', 2026022502, 'mod_tincanlaunch');

        $this->assertTrue(xmldb_tincanlaunch_upgrade(2026022502));

        $updated = $DB->get_record('tincanlaunch', ['id' => $activity->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$updated->revision);
        $this->assertSame(0, (int)$updated->updatefreq);
        $this->assertNull($updated->packagehash);
        $this->assertNull($updated->packageerrorhash);
        $this->assertSame(0, (int)$updated->tincanlaunchtype);
        $this->assertSame(100, (int)$updated->grade);
        $this->assertSame($activity->tincanlaunchurl, $updated->tincanlaunchurl);
        $this->assertSame(
            'Legacy content',
            tincanlaunch_find_package_content($context, 0, ['index.html'])->get_content()
        );
        $this->assertSame(
            'Legacy content',
            tincanlaunch_find_package_content($context, 0, ['0', 'index.html'])->get_content()
        );
    }

    /**
     * Revisioned Installation preserves existing launch query and fragment behavior.
     */
    public function test_installation_preserves_launch_query_and_fragment(): void {
        global $CFG;

        $activity = $this->create_linked_activity('index.html?mode=review&lang=en#slide2');
        $context = \context_module::instance($activity->cmid);

        $this->assertSame(
            $CFG->wwwroot . '/pluginfile.php/' . $context->id .
                '/mod_tincanlaunch/content/1/index.html?mode=review&lang=en#slide2',
            $activity->tincanlaunchurl
        );
    }

    /**
     * Installation may replace the xAPI identity that Linked Refresh must preserve.
     */
    public function test_installation_can_replace_xapi_identity(): void {
        global $DB;

        $activity = $this->create_linked_activity();
        $this->write_package('Replacement package', 'https://example.com/replacement');
        $DB->set_field('files_reference', 'lastsync', 0);

        $this->package((int)$activity->id)->install((int)$activity->cmid);

        $installed = $DB->get_record('tincanlaunch', ['id' => $activity->id], '*', MUST_EXIST);
        $this->assertSame(2, (int)$installed->revision);
        $this->assertSame('https://example.com/replacement', $installed->tincanactivityid);
        $this->assertStringEndsWith('/mod_tincanlaunch/content/2/index.html', $installed->tincanlaunchurl);
        $content = get_file_storage()->get_file(
            \context_module::instance($activity->cmid)->id,
            'mod_tincanlaunch',
            'content',
            2,
            '/',
            'index.html'
        );
        $this->assertSame('Replacement package', $content->get_content());
    }

    /**
     * A reused package object observes Installation performed through another instance.
     */
    public function test_refresh_observes_installation_after_package_creation(): void {
        global $DB;

        $activity = $this->create_linked_activity();
        $package = $this->package((int)$activity->id);
        $this->assertSame('unchanged', $package->refresh()->get_status());
        $this->write_package('Replacement package', 'https://example.com/replacement');
        $DB->set_field('files_reference', 'lastsync', 0);
        $this->package((int)$activity->id)->install((int)$activity->cmid);

        $result = $package->refresh();

        $this->assertSame('unchanged', $result->get_status());
        $installed = $DB->get_record('tincanlaunch', ['id' => $activity->id], '*', MUST_EXIST);
        $this->assertSame(2, (int)$installed->revision);
        $this->assertSame('https://example.com/replacement', $installed->tincanactivityid);
    }

    /**
     * Reusing a package after Rejection retains the accepted identity and revision sequence.
     */
    public function test_reused_package_can_activate_after_rejection(): void {
        global $DB;

        $activity = $this->create_linked_activity();
        $package = $this->package((int)$activity->id);
        $this->write_package('Revision two');
        $DB->set_field('files_reference', 'lastsync', 0);
        $this->assertSame('updated', $package->refresh()->get_status());

        $this->write_package('Rejected candidate', 'https://example.com/other');
        $DB->set_field('files_reference', 'lastsync', 0);
        $result = $package->refresh();
        $this->assertSame('rejected', $result->get_status());
        $this->assertSame('activityidmismatch', $result->get_rejection()->errorcode);
        $rejected = $DB->get_record('tincanlaunch', ['id' => $activity->id], '*', MUST_EXIST);
        $this->assertSame(2, (int)$rejected->revision);
        $this->assertSame('https://example.com/activity', $rejected->tincanactivityid);

        $this->write_package('Revision three');
        $DB->set_field('files_reference', 'lastsync', 0);
        $this->assertSame('updated', $package->refresh()->get_status());
        $accepted = $DB->get_record('tincanlaunch', ['id' => $activity->id], '*', MUST_EXIST);
        $this->assertSame(3, (int)$accepted->revision);
        $this->assertSame('https://example.com/activity', $accepted->tincanactivityid);
        $content = get_file_storage()->get_file(
            \context_module::instance($activity->cmid)->id,
            'mod_tincanlaunch',
            'content',
            3,
            '/',
            'index.html'
        );
        $this->assertSame('Revision three', $content->get_content());
    }

    /**
     * Install an initial package through the activity's public installation seam.
     *
     * @param string $launch Manifest launch path.
     * @return \stdClass Activity including its course module ID.
     */
    private function create_linked_activity(string $launch = 'index.html'): \stdClass {
        global $DB, $USER;

        $this->write_package('Revision one', launch: $launch);
        if (!$DB->record_exists('repository', ['type' => 'filesystem'])) {
            $this->getDataGenerator()->create_repository_type('filesystem');
        }
        $repository = $this->getDataGenerator()->create_repository('filesystem', [
            'fs_path' => 'tincanlaunch-refresh',
        ]);
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('tincanlaunch', [
            'course' => $course->id,
            'name' => 'Linked xAPI activity',
            'tincanlaunchtype' => 0,
            'tincanactivityid' => 'https://example.com/activity',
        ]);
        $draftid = file_get_unused_draft_itemid();
        $alias = get_file_storage()->create_file_from_reference([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'package.zip',
        ], $repository->id, 'package.zip');
        $alias->import_external_file_contents();
        tincanlaunch_process_new_package((object)[
            'id' => $activity->id,
            'coursemodule' => $activity->cmid,
            'packagefile' => $draftid,
        ]);

        $installed = $DB->get_record('tincanlaunch', ['id' => $activity->id], '*', MUST_EXIST);
        $installed->cmid = $activity->cmid;
        return $installed;
    }

    /**
     * Publish a package at the same repository pathname.
     *
     * @param string $content Launch file content.
     * @param string $xapiactivityid Manifest xAPI Activity ID.
     * @param string $launch Manifest launch path.
     * @param string|null $manifest Override manifest XML.
     */
    private function write_package(
        string $content,
        string $xapiactivityid = 'https://example.com/activity',
        string $launch = 'index.html',
        ?string $manifest = null
    ): void {
        $archive = new \ZipArchive();
        $archive->open($this->packagepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $archive->addFromString(
            'tincan.xml',
            $manifest ?? '<tincan><activities><activity id="' . $xapiactivityid . '"><launch>' .
                $launch . '</launch></activity></activities></tincan>'
        );
        $archive->addFromString('index.html', $content);
        $archive->close();
        clearstatcache(true, $this->packagepath);
    }

    /**
     * Load the package belonging to an activity.
     *
     * @param int $instanceid Moodle activity instance ID.
     * @param \core\lock\lock_factory|null $lockfactory Lock factory override.
     * @return local\package
     */
    private function package(int $instanceid, ?\core\lock\lock_factory $lockfactory = null): local\package {
        global $DB;

        return new local\package(
            $instanceid,
            $DB,
            get_file_storage(),
            get_file_packer('application/zip'),
            $lockfactory ?? \core\lock\lock_config::get_lock_factory('mod_tincanlaunch_package_update')
        );
    }
}
