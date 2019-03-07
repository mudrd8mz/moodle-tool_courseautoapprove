<?php
// This file is part of Moodle - https://moodle.org/
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
 * Provides the {@link 'tool_courseautoapprove\task\pprove_course_requests_task} class.
 *
 * @package     tool_courseautoapprove
 * @category    task
 * @copyright   2014 Dan Poltawski <dan@moodle.com>, 2019 David Mudrák <david@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_courseautoapprove\task;

defined('MOODLE_INTERNAL') || die();

/**
 * A scheduled task for approving course requests.
 *
 * @package    tool_courseautoapprove
 * @copyright  2014 Dan Poltawski <dan@moodle.com>, 2019 David Mudrák <david@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approve_course_requests_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('courseautoapprovetask', 'tool_courseautoapprove');
    }

    /**
     * Run the task.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        if (empty($CFG->enablecourserequests)) {
            mtrace('... Automatic approval of course requests skipped ($CFG->enablecourserequests disabled).');
            return;
        }

        $config = get_config('tool_courseautoapprove');

        if (empty($config->maxcourses)) {
            mtrace('... Automatic approval of course requests skipped (maxcourses set to zero).');
            return;
        }

        mtrace('... Starting to auto-approve course requests.');

        $rs = $DB->get_recordset('course_request');
        foreach ($rs as $request) {
            $courserequest = new \course_request($request);
            $currentcourses = self::count_courses_user_is_teacher($request->requester);

            if ($currentcourses >= $config->maxcourses) {
                mtrace("... - Denying course request from userid {$request->requester} as they are already a teacher ".
                    "in {$currentcourses} existing course(s) and the limit is {$config->maxcourses}.");

                if ($config->reject) {
                    mtrace("...   Marking the course request as rejected and notifying the user.");
                    $courserequest->reject(get_string('rejectmsgcount', 'tool_courseautoapprove',
                        ['currentcourses' => $currentcourses, 'maxcourses' => $config->maxcourses]));
                }

                continue;
            }

            if ($courserequest->check_shortname_collision()) {
                mtrace("... - Denying course request with shortname {$request->shortname} as there is another with the same shortname.");

                if ($config->reject) {
                    mtrace("...   Marking the course request as rejected and notifying the user.");
                    $courserequest->reject(get_string('rejectmshshortname', 'tool_courseautoapprove'));
                }

                continue;
            }

            mtrace("... - Approving course request from userid {$request->requester} for the course {$request->shortname}.");
            $courserequest->approve();
        }
        $rs->close();

        mtrace('... Finished auto-approving course requests.');
    }

    /**
     * Return the number of courses where the given user acts as a teacher.
     *
     * @param int $userid The id of user to check.
     * @return int Number of courses.
     */
    public static function count_courses_user_is_teacher($userid) {

        $result = 0;
        $enroledcourses = enrol_get_all_users_courses($userid);

        foreach ($enroledcourses as $course) {
            \context_helper::preload_from_record($course);
            $context = \context_course::instance($course->id);
            if (has_capability('moodle/course:update', $context, $userid)) {
                $result++;
            }
        }

        return $result;
    }
}
