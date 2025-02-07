<?php
// This file is part of the Accredible Certificate module for Moodle - http://moodle.org/
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

namespace mod_accredible\local;
defined('MOODLE_INTERNAL') || die();

use mod_accredible\apirest\apirest;
use mod_accredible\Html2Text\Html2Text;

/**
 * Local functions related to groups/courses.
 *
 * @package    mod_accredible
 * @subpackage accredible
 * @copyright  Accredible <dev@accredible.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class groups {
    /**
     * The apirest object used to call API requests.
     * @var apirest
     */
    private $apirest;

    /**
     * A random value for a new group name.
     * @var int
     */
    private $rand;

    /**
     * Constructor method.
     *
     * @param stdObject $apirest a mock apirest for testing.
     * @param int $rand a random number to avoid duplicated names when creating groups.
     */
    public function __construct($apirest = null, $rand = null) {
        // An apirest with a mock client is passed when unit testing.
        if ($apirest) {
            $this->apirest = $apirest;
        } else {
            $this->apirest = new apirest();
        }

        // A fixed value is passed when unit testing.
        if ($rand) {
            $this->rand = $rand;
        } else {
            $this->rand = mt_rand();
        }
    }

    /**
     * Get the groups for the issuer
     * @return array[stdClass] $groups
     */
    public function get_groups() {
        $pagesize = 50;
        $page = 1;

        try {
            $groups = array();
            // Query the Accredible API and loop until it returns that there is no next page.
            for ($i = 0; $i <= 100; $i++) {
                $response = $this->apirest->get_groups($pagesize, $page);
                foreach ($response->groups as $group) {
                    $groups[$group->id] = $group->name;
                }

                $page++;
                if ($response->meta->next_page === null) {
                    // If the Accredible API returns that there
                    // is no next page, end the loop.
                    break;
                }
            }
            return $groups;
        } catch (\Exception $e) {
            throw new \moodle_exception('getgroupserror', 'accredible', 'https://help.accredible.com/hc/en-us');
        }
    }

    /**
     * List all of the issuer's templates
     *
     * @return array[stdClass] $templates
     */
    public function get_templates() {
        $pagesize = 50;
        $page = 1;

        try {
            $templates = array();
            // Query the Accredible API and loop until it returns that there is no next page.
            for ($i = 0; $i <= 100; $i++) {
                $response = $this->apirest->search_groups($pagesize, $page);
                foreach ($response->groups as $group) {
                    $templates[$group->name] = $group->name;
                }

                $page++;
                if ($response->meta->next_page === null) {
                    // If the Accredible API returns that there
                    // is no next page, end the loop.
                    break;
                }
            }
            return $templates;
        } catch (\Exception $e) {
            throw new \moodle_exception('gettemplateserror', 'accredible', 'https://help.accredible.com/hc/en-us');
        }
    }

    /**
     * Sync the selected course information with a group on Accredible - returns a group ID.
     * Optionally takes a group ID so we can set it and change the assigned group.
     *
     * @param stdClass $course
     * @param int|null $instanceid
     * @param int|null $groupid
     * @return int $groupid
     */
    public function sync_group_with($course, $instanceid = null, $groupid = null) {
        global $DB;

        $courselink = new \moodle_url('/course/view.php', array('id' => $course->id));

        try {
            if ($instanceid != null) {
                // Update the group.
                $accredible = $DB->get_record('accredible', array('id' => $instanceid), '*', MUST_EXIST);
                // Get the group id.
                if (!isset($groupid)) {
                    $groupid = $accredible->groupid;
                }
                $res = $this->apirest->update_group($groupid, null, null, null, $courselink);
            } else {
                // Create a new Group on Accredible.
                $description = Html2Text::convert($course->summary);
                if (empty($description)) {
                    $description = "Recipient has compeleted the achievement.";
                }
                // Add a random number to deal with duplicate course names.
                $name = $course->shortname . $this->rand;
                $res = $this->apirest->create_group($name, $course->fullname, $description, $courselink);
            }
            return $res->group->id;
        } catch (\Exception $e) {
            throw new \moodle_exception('syncgroupwitherror',
                                        'accredible',
                                        'https://help.accredible.com/hc/en-us',
                                        $course->id,
                                        $course->id);
        }
    }
}
