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
 * Expected outcome of a Linked Refresh.
 *
 * @package    mod_tincanlaunch
 * @copyright  2026 TAWK GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class package_refresh_result {
    /** @var string */
    const UPDATED = 'updated';

    /** @var string */
    const UNCHANGED = 'unchanged';

    /** @var string */
    const REJECTED = 'rejected';

    /** @var string */
    const BUSY = 'busy';

    /** @var string */
    private $status;

    /** @var package_update_exception|null */
    private $rejection;

    /**
     * Constructor.
     *
     * @param string $status
     * @param package_update_exception|null $rejection
     */
    private function __construct(string $status, ?package_update_exception $rejection = null) {
        $this->status = $status;
        $this->rejection = $rejection;
    }

    /**
     * Return an updated result.
     *
     * @return self
     */
    public static function updated(): self {
        return new self(self::UPDATED);
    }

    /**
     * Return an unchanged result.
     *
     * @return self
     */
    public static function unchanged(): self {
        return new self(self::UNCHANGED);
    }

    /**
     * Return a rejected result.
     *
     * @param package_update_exception $rejection
     * @return self
     */
    public static function rejected(package_update_exception $rejection): self {
        return new self(self::REJECTED, $rejection);
    }

    /**
     * Return a busy result.
     *
     * @return self
     */
    public static function busy(): self {
        return new self(self::BUSY);
    }

    /**
     * Return the outcome status.
     *
     * @return string
     */
    public function get_status(): string {
        return $this->status;
    }

    /**
     * Return the rejection.
     *
     * @return package_update_exception
     */
    public function get_rejection(): package_update_exception {
        if (!$this->rejection) {
            throw new \coding_exception('This package refresh result is not a rejection.');
        }
        return $this->rejection;
    }
}
