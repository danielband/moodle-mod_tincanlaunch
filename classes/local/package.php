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

namespace mod_tincanlaunch\local;

use mod_tincanlaunch\exception\package_update_exception;

/**
 * Owns the package lifecycle of one Tin Can Launch activity.
 *
 * @package    mod_tincanlaunch
 * @copyright  2026 TAWK GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class package {
    /** @var int Moodle activity instance ID. */
    private int $instanceid;

    /** @var \context_module */
    private \context_module $context;

    /** @var int Active Revision. */
    private int $activerevision;

    /** @var string|null Accepted package content identity. */
    private ?string $acceptedcontenthash;

    /** @var string Accepted xAPI Activity ID. */
    private string $xapiactivityid;

    /** @var string Active Revision launch URL. */
    private string $launchurl;

    /** @var string|null Last successfully notified Candidate Fingerprint. */
    private ?string $lastnotifiedfingerprint;

    /** @var \moodle_database */
    private \moodle_database $database;

    /** @var \file_storage */
    private \file_storage $filestorage;

    /** @var \file_packer */
    private \file_packer $packer;

    /** @var \core\lock\lock_factory */
    private \core\lock\lock_factory $lockfactory;

    /**
     * Constructor.
     *
     * @param int $instanceid Moodle activity instance ID.
     * @param \moodle_database $database
     * @param \file_storage $filestorage
     * @param \file_packer $packer
     * @param \core\lock\lock_factory $lockfactory
     */
    public function __construct(
        int $instanceid,
        \moodle_database $database,
        \file_storage $filestorage,
        \file_packer $packer,
        \core\lock\lock_factory $lockfactory
    ) {
        $this->instanceid = $instanceid;
        $this->database = $database;
        $this->filestorage = $filestorage;
        $this->packer = $packer;
        $this->lockfactory = $lockfactory;
    }

    /**
     * Install the package selected through the activity form.
     *
     * @param int $coursemoduleid
     */
    public function install(int $coursemoduleid): void {
        $lock = $this->lockfactory->get_lock((string)$this->instanceid, 0);
        if (!$lock) {
            throw new package_update_exception('packageupdateinprogress');
        }

        try {
            $this->reload_state($coursemoduleid);
            $candidate = $this->load_candidate();
            $manifest = $this->read_manifest($candidate);
            $this->activate($candidate, $manifest);
        } finally {
            $lock->release();
        }
    }

    /**
     * Perform a Linked Refresh while preserving the activity's xAPI identity.
     *
     * @return package_refresh_result
     */
    public function refresh(): package_refresh_result {
        $lock = $this->lockfactory->get_lock((string)$this->instanceid, 0);
        if (!$lock) {
            return package_refresh_result::busy();
        }

        try {
            $this->reload_state();

            try {
                $candidate = $this->load_candidate();
                if (
                    !empty($this->acceptedcontenthash) &&
                        hash_equals($this->acceptedcontenthash, $candidate->contenthash)
                ) {
                    if (!empty($this->lastnotifiedfingerprint)) {
                        $this->database->set_field(
                            'tincanlaunch',
                            'packageerrorhash',
                            null,
                            ['id' => $this->instanceid]
                        );
                        $this->lastnotifiedfingerprint = null;
                    }
                    return package_refresh_result::unchanged();
                }

                $manifest = $this->read_manifest($candidate);
                if ($manifest->get_activity_id() === '') {
                    throw $this->rejection($candidate, 'manifestactivityidmissing');
                }
                if ($manifest->get_launch() === '') {
                    throw $this->rejection($candidate, 'manifestlaunchmissing');
                }
                if (
                    !empty($this->xapiactivityid) &&
                        $this->xapiactivityid !== $manifest->get_activity_id()
                ) {
                    throw $this->rejection($candidate, 'activityidmismatch', (object)[
                        'current' => $this->xapiactivityid,
                        'candidate' => $manifest->get_activity_id(),
                    ]);
                }

                $this->activate($candidate, $manifest);
                return package_refresh_result::updated();
            } catch (package_update_exception $exception) {
                if ($exception->errorcode === 'packageupdateinprogress') {
                    return package_refresh_result::busy();
                }
                return package_refresh_result::rejected($exception);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Reload accepted state after acquiring the package lock.
     *
     * @param int|null $coursemoduleid Supplied during Installation before the module links to its instance.
     */
    private function reload_state(?int $coursemoduleid = null): void {
        $record = $this->database->get_record('tincanlaunch', ['id' => $this->instanceid], '*', MUST_EXIST);
        if ($coursemoduleid === null) {
            $coursemodule = get_coursemodule_from_instance(
                'tincanlaunch',
                $this->instanceid,
                $record->course,
                false,
                MUST_EXIST
            );
            $coursemoduleid = (int)$coursemodule->id;
        }

        $this->context = \context_module::instance($coursemoduleid);
        $this->activerevision = (int)$record->revision;
        $this->acceptedcontenthash = $record->packagehash;
        $this->xapiactivityid = $record->tincanactivityid;
        $this->launchurl = $record->tincanlaunchurl;
        $this->lastnotifiedfingerprint = $record->packageerrorhash;
    }

    /**
     * Load and synchronize the activity's Candidate Revision.
     *
     * @return \stdClass Candidate file and synchronized content identity.
     */
    private function load_candidate(): \stdClass {
        $files = $this->filestorage->get_area_files($this->context->id, 'mod_tincanlaunch', 'package', 0, 'id', false);
        $candidate = $files ? reset($files) : null;
        if (!$candidate) {
            throw new package_update_exception(
                'packagefilenotfound',
                null,
                sha1('activity:' . $this->instanceid . ':packagefilenotfound')
            );
        }

        if ($candidate->is_external_file()) {
            $reference = $candidate->get_reference();
            try {
                $candidate->import_external_file_contents();
            } catch (\Throwable $exception) {
                throw new package_update_exception(
                    'packagesyncfailed',
                    $exception->getMessage(),
                    sha1($reference . ':packagesyncfailed')
                );
            }
            if ($candidate->get_status()) {
                throw new package_update_exception(
                    'packagesourcemissing',
                    null,
                    sha1($reference . ':packagesourcemissing')
                );
            }
        }

        return (object)[
            'file' => $candidate,
            'contenthash' => $candidate->get_contenthash(),
        ];
    }

    /**
     * Read the Candidate Revision's Tin Can manifest.
     *
     * @param \stdClass $candidate
     * @return tincan_manifest
     */
    private function read_manifest(\stdClass $candidate): tincan_manifest {
        $stagingpath = make_request_directory();
        if (!$candidate->file->extract_to_pathname($this->packer, $stagingpath)) {
            throw $this->rejection($candidate, 'badarchive');
        }

        $manifestpath = $stagingpath . '/tincan.xml';
        if (!is_readable($manifestpath)) {
            throw $this->rejection($candidate, 'nomanifest');
        }

        $xml = file_get_contents($manifestpath);
        if ($xml === false) {
            throw $this->rejection($candidate, 'badmanifest');
        }

        try {
            return tincan_manifest::from_xml($xml);
        } catch (\InvalidArgumentException $exception) {
            throw $this->rejection($candidate, 'badmanifest');
        }
    }

    /**
     * Activate a validated Candidate Revision, preserving missing metadata.
     *
     * @param \stdClass $candidate
     * @param tincan_manifest $manifest
     */
    private function activate(\stdClass $candidate, tincan_manifest $manifest): void {
        $previousrevision = $this->activerevision;
        $nextrevision = $previousrevision + 1;
        $candidatexapiactivityid = $manifest->get_activity_id();
        $candidatelaunch = $manifest->get_launch();
        $activated = false;
        $this->filestorage->delete_area_files($this->context->id, 'mod_tincanlaunch', 'content', $nextrevision);

        try {
            if (
                !$candidate->file->extract_to_storage(
                    $this->packer,
                    $this->context->id,
                    'mod_tincanlaunch',
                    'content',
                    $nextrevision,
                    '/'
                )
            ) {
                throw $this->rejection($candidate, 'packageextractfailed');
            }

            $storedrevision = (int)$this->database->get_field(
                'tincanlaunch',
                'revision',
                ['id' => $this->instanceid],
                MUST_EXIST
            );
            if ($storedrevision !== $this->activerevision) {
                throw new package_update_exception('packageupdateinprogress');
            }

            $update = (object)[
                'id' => $this->instanceid,
                'packagehash' => $candidate->contenthash,
                'revision' => $nextrevision,
                'packageerrorhash' => null,
                'timemodified' => time(),
            ];
            if ($candidatexapiactivityid !== '') {
                $update->tincanactivityid = $candidatexapiactivityid;
            }
            if ($candidatelaunch !== '') {
                $update->tincanlaunchurl = $this->make_launch_url($nextrevision, $candidatelaunch);
            }
            $this->database->update_record('tincanlaunch', $update);
            $this->activerevision = $nextrevision;
            $this->acceptedcontenthash = $candidate->contenthash;
            $this->xapiactivityid = $update->tincanactivityid ?? $this->xapiactivityid;
            $this->launchurl = $update->tincanlaunchurl ?? $this->launchurl;
            $this->lastnotifiedfingerprint = null;
            $activated = true;
        } finally {
            if (!$activated) {
                $this->filestorage->delete_area_files($this->context->id, 'mod_tincanlaunch', 'content', $nextrevision);
            }
        }

        $this->filestorage->delete_area_files($this->context->id, 'mod_tincanlaunch', 'content', $previousrevision);
    }

    /**
     * Create a rejection tied to the Candidate Revision and its reason.
     *
     * @param \stdClass $candidate
     * @param string $errorcode
     * @param mixed $a
     * @return package_update_exception
     */
    private function rejection(\stdClass $candidate, string $errorcode, $a = null): package_update_exception {
        return new package_update_exception($errorcode, $a, sha1($candidate->contenthash . ':' . $errorcode));
    }

    /**
     * Build a launch URL for the proposed next revision.
     *
     * @param int $nextrevision
     * @param string $candidatelaunch
     * @return string
     */
    private function make_launch_url(int $nextrevision, string $candidatelaunch): string {
        $scheme = parse_url($candidatelaunch, PHP_URL_SCHEME);
        if (!in_array($scheme, [null, false], true)) {
            return $candidatelaunch;
        }

        $parts = parse_url($candidatelaunch);
        $url = \moodle_url::make_pluginfile_url(
            $this->context->id,
            'mod_tincanlaunch',
            'content',
            $nextrevision,
            '/',
            ltrim($parts['path'] ?? '', '/')
        )->out(false);
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }
        return $url;
    }
}
