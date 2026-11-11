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

/**
 * Metadata read from the first activity in a Tin Can package manifest.
 *
 * @package    mod_tincanlaunch
 * @copyright  2026 TAWK GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tincan_manifest {
    /** @var string */
    private $activityid;

    /** @var string */
    private $launch;

    /**
     * Constructor.
     *
     * @param string $activityid
     * @param string $launch
     */
    private function __construct(string $activityid, string $launch) {
        $this->activityid = $activityid;
        $this->launch = $launch;
    }

    /**
     * Parse manifest XML.
     *
     * Raw ampersands are tolerated for compatibility with launch URLs in existing packages.
     *
     * @param string $xml
     * @return self
     */
    public static function from_xml(string $xml): self {
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false) {
            throw new \InvalidArgumentException('Invalid Tin Can manifest XML.');
        }

        $xml = preg_replace('/&(?!\w{2,6};)/', '&amp;', $xml);
        if ($xml === null) {
            throw new \InvalidArgumentException('Invalid Tin Can manifest XML.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET);
            if (!$loaded || !$document->documentElement || $document->doctype !== null) {
                throw new \InvalidArgumentException('Invalid Tin Can manifest XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $activities = self::first_child($document->documentElement, 'activities');
        $activity = $activities ? self::first_child($activities, 'activity') : null;
        if (!$activity) {
            return new self('', '');
        }

        $activityid = '';
        foreach ($activity->attributes as $attribute) {
            if (strcasecmp($attribute->localName, 'id') === 0) {
                $activityid = $attribute->value;
                break;
            }
        }

        $launchelement = self::first_child($activity, 'launch');
        return new self($activityid, $launchelement ? $launchelement->textContent : '');
    }

    /**
     * Return the xAPI Activity ID.
     *
     * @return string
     */
    public function get_activity_id(): string {
        return $this->activityid;
    }

    /**
     * Return the package launch path.
     *
     * @return string
     */
    public function get_launch(): string {
        return $this->launch;
    }

    /**
     * Find the first direct element child with a local name.
     *
     * @param \DOMElement $parent
     * @param string $localname
     * @return \DOMElement|null
     */
    private static function first_child(\DOMElement $parent, string $localname): ?\DOMElement {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && strcasecmp($child->localName, $localname) === 0) {
                return $child;
            }
        }
        return null;
    }
}
