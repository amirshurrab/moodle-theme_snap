<?php
// This file is part of the custom Moodle Snap theme
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
//

/**
 * Functions for dealing with activity modules.
 * Note, they have to be part of a class to facilitate auto loading.
 */
namespace theme_snap;

class activity {

    /**
     * Return standard meta data for module
     *
     * @param stdClass $mod
     * @param string $timeopenfld
     * @param string $timeclosefld
     * @param string $keyfield
     * @param string $submissiontable
     * @param string $submittedonfld
     * @param string $submitstrkey
     * @param bool $isgradeable
     * @param string $submitselect - sql to further filter submission row select statement - e.g. st.status='finished'
     * @return bool | \theme_snap\activity_meta
     */
    protected static function std_meta($mod,
                                       $timeopenfld,
                                       $timeclosefld,
                                       $keyfield,
                                       $submissiontable,
                                       $submittedonfld,
                                       $submitstrkey,
                                       $isgradeable = false,
                                       $submitselect = ''
    ) {
        global $USER, $COURSE;

        // Create meta data object.
        $meta = new \theme_snap\activity_meta();
        $meta->submitstrkey = $submitstrkey;
        $meta->submittedstr = get_string($submitstrkey, 'theme_snap');
        $meta->notsubmittedstr = get_string('not'.$submitstrkey, 'theme_snap');

        // If module is not available then don't bother getting meta data.
        if (!$mod->available) {
            return ($meta);
        }

        $activitydates = self::instance_activity_dates($COURSE->id, $mod, $timeopenfld, $timeclosefld);
        $meta->timeopen = $activitydates->timeopen;
        $meta->timeclose = $activitydates->timeclose;

        if (has_capability('moodle/course:manageactivities', \context_course::instance($COURSE->id))) {
            $meta->isteacher = true;

            // Teacher - useful teacher meta data.
            $methodnsubmissions = $mod->modname.'_num_submissions';
            $methodnungraded = $mod->modname.'_num_submissions_ungraded';

            if (method_exists('theme_snap\\activity', $methodnsubmissions)) {
                $meta->numsubmissions = call_user_func('theme_snap\\activity::'.$methodnsubmissions, $COURSE->id, $mod->instance);
            }
            if (method_exists('theme_snap\\activity', $methodnungraded)) {
                $meta->numrequiregrading = call_user_func('theme_snap\\activity::'.$methodnungraded, $COURSE->id, $mod->instance);
            }
        } else {
            // Student - useful student meta data - only display if activity is available.
            if (empty($activitydates->timeopen) || usertime($activitydates->timeopen) <= time()) {
                $submissionrow = self::get_submission_row($COURSE->id, $mod, $submissiontable, $keyfield, $submitselect);

                if (!empty($submissionrow)) {
                    $meta->submitted = true;
                    $meta->timesubmitted = !empty($submissionrow->$submittedonfld) ? $submissionrow->$submittedonfld : null;
                    // If submitted on field uses modified field then fall back to timecreated if modified is 0.
                    if (empty($meta->timesubmitted) && $submittedonfld = 'timemodified') {
                        if (isset($submissionrow->timemodified)) {
                            $meta->timesubmitted = $submissionrow->timemodified;
                        } else {
                            $meta->timesubmitted = $submissionrow->timecreated;
                        }
                    }

                    $graderow = false;
                    if ($isgradeable) {
                        $graderow = self::grade_row($COURSE->id, $mod, $keyfield);
                    }

                    if ($graderow) {
                        $gradeitem = \grade_item::fetch(array(
                            'itemtype' => 'mod',
                            'itemmodule' => $mod->modname,
                            'iteminstance' => $mod->instance,
                        ));

                        $grade = new \grade_grade(array('itemid' => $gradeitem->id, 'userid' => $USER->id));

                        $coursecontext = \context_course::instance($COURSE->id);
                        $canviewhiddengrade = has_capability('moodle/grade:viewhidden', $coursecontext);

                        if (!$grade->is_hidden() || $canviewhiddengrade) {
                            $meta->grade = true;
                        }

                    }
                }
            }
        }
        return $meta;
    }

    /**
     * Get assignment meta data
     *
     * @param stdClass $modinst - module instance
     * @return string
     */
    public static function assign_meta($modinst) {
        return self::std_meta($modinst, 'allowsubmissionsfromdate', 'duedate', 'assignment', 'submission',
            'timemodified', 'submitted', true);
    }

    /**
     * Get choice module meta data
     *
     * @param stdClass $modinst - module instance
     * @return string
     */
    public static function choice_meta($modinst) {
        return  self::std_meta($modinst, 'timeopen', 'timeclose', 'choiceid', 'answers', 'timeseen', 'answered');
    }

    /**
     * Get database module meta data
     *
     * @param stdClass $modinst - module instance
     * @return string
     */
    public static function data_meta($modinst) {
        return self::std_meta($modinst, 'timeavailablefrom', 'timeavailableto', 'dataid', 'records', 'timemodified', 'contributed');
    }

    /**
     * Get feedback module meta data
     *
     * @param stdClass $modinst - module instance
     * @return string
     */
    public static function feedback_meta($modinst) {
        return self::std_meta($modinst, 'timeopen', 'timeclose', 'feedback', 'completed', 'timemodified', 'submitted');
    }

    /**
     * Get lesson module meta data
     *
     * @param stdClass $modinst - module instance
     * @return string
     */
    public static function lesson_meta($modinst) {
        return self::std_meta($modinst, 'available', 'deadline', 'lessonid', 'attempts', 'timeseen', 'attempted', true);
    }

    /**
     * Get quiz module meta data
     *
     * @param stdClass $modinst - module instance
     * @return string
     */
    public static function quiz_meta($modinst) {
        return self::std_meta($modinst, 'timeopen', 'timeclose', 'quiz', 'attempts', 'timemodified', 'attempted', true, 'AND st.state=\'finished\'');
    }

    /**
     * Get all assignments (for all courses) waiting to be graded.
     *
     * @param $assignmentid
     * @return array $ungraded
     */
    public static function assign_ungraded($courseids) {
        global $DB;

        if (empty($courseids)) {
            return false;
        }

        $ungraded = array();

        $sixmonthsago = time() - YEARSECS / 2;

        foreach ($courseids as $courseid) {

            // Get people who are typically not students (people who can view grader report) so that we can exclude them!
            list($graderids, $params) = get_enrolled_sql(\context_course::instance($courseid), 'moodle/grade:viewall');
            $params = array_merge(array('courseid' => $courseid), $params);

            $sql = "-- Snap sql
                    SELECT cm.id AS coursemoduleid, a.id AS instanceid, a.course,
                           a.allowsubmissionsfromdate AS opentime, a.duedate AS closetime,
                           count(DISTINCT sb.userid) AS ungraded
                      FROM {assign} a
                      JOIN {course} c ON c.id = a.course
                      JOIN {modules} m ON m.name = 'assign'
                      JOIN {course_modules} cm ON cm.module = m.id
                           AND cm.instance = a.id
                      JOIN {assign_submission} sb ON sb.assignment = a.id
                 LEFT JOIN {assign_grades} gd ON gd.assignment = sb.assignment
                           AND gd.userid = sb.userid
                     WHERE sb.status='submitted'
                           AND gd.id IS NULL AND a.course = :courseid
                           AND sb.userid NOT IN ($graderids)
                           AND (a.duedate = 0 OR a.duedate > $sixmonthsago)
                  GROUP BY instanceid, a.course, opentime, closetime, coursemoduleid ORDER BY a.duedate ASC";
            $rs = $DB->get_records_sql($sql, $params);
            $ungraded = array_merge($ungraded, $rs);
        }

        return $ungraded;
    }

    /**
     * Get Quizzes waiting to be graded.
     *
     * @param $assignmentid
     * @return array $ungraded
     */
    public static function quiz_ungraded($courseids) {
        global $DB;

        if (empty($courseids)) {
            return false;
        }

        $sixmonthsago = time() - YEARSECS / 2;

        $ungraded = array();

        foreach ($courseids as $courseid) {

            // Get people who are typically not students (people who can view grader report) so that we can exclude them!
            list($graderids, $params) = get_enrolled_sql(\context_course::instance($courseid), 'moodle/grade:viewall');
            $params = array_merge(array('courseid' => $courseid), $params);

            $sql = "-- Snap sql
                    SELECT cm.id AS coursemoduleid, q.id AS instanceid, q.course,
                           q.timeopen AS opentime, q.timeclose AS closetime,
                           count(DISTINCT qa.userid) AS ungraded
                      FROM {quiz} q
                      JOIN {course} c ON c.id = q.course
                      JOIN {modules} m ON m.name = 'quiz'
                      JOIN {course_modules} cm ON cm.module = m.id
                           AND cm.instance = q.id
                      JOIN {quiz_attempts} qa ON qa.quiz = q.id
                 LEFT JOIN {quiz_grades} gd ON gd.quiz = qa.quiz
                           AND gd.userid = qa.userid
                     WHERE gd.id IS NULL
                           AND q.course = :courseid
                           AND qa.userid NOT IN ($graderids)
                           AND qa.state = 'finished'
                           AND (q.timeclose = 0 OR q.timeclose > $sixmonthsago)
                  GROUP BY instanceid, q.course, opentime, closetime, coursemoduleid ORDER BY q.timeclose ASC";

            $rs = $DB->get_records_sql($sql, $params);
            $ungraded = array_merge($ungraded, $rs);
        }

        return $ungraded;
    }

    // The lesson_ungraded function has been removed as it was very tricky to implement.
    // This was because it creates a grade record as soon as a student finishes the lesson.

    /**
     * Get number of ungraded submissions for specific assignment
     *
     * @param int $courseid
     * @param int $modid
     * @return int
     */
    public static function assign_num_submissions_ungraded($courseid, $modid) {
        global $DB;

        static $totalsbyid;

        // Get people who are typically not students (people who can view grader report) so that we can exclude them!
        list($graderids, $params) = get_enrolled_sql(\context_course::instance($courseid), 'moodle/grade:viewall');
        $params = array_merge(array('courseid' => $courseid), $params);

        if (!isset($totalsbyid)) {
            // Results are not cached, so lets get them.
            // Get the number of submissions for all assignments in this course.
            $sql = "-- Snap sql
                    SELECT a.id, count(*) AS total
                      FROM {assign} a
                 LEFT JOIN {assign_submission} sb ON sb.assignment = a.id
                 LEFT JOIN {assign_grades} gd ON gd.assignment = sb.assignment
                           AND gd.userid = sb.userid
                     WHERE sb.status='submitted'
                           AND gd.id IS NULL
                           AND course = :courseid
                           AND sb.userid NOT IN ($graderids)
                  GROUP BY a.id";
            $totalsbyid = $DB->get_records_sql($sql, $params);
        }

        if (!empty($totalsbyid)) {
            if (isset($totalsbyid[$modid])) {
                return intval($totalsbyid[$modid]->total);
            }
        }
        return 0;
    }

    /**
     * Standard function for getting number of submissions (where sql is not complicated and pretty much standard)
     *
     * @param int $courseid
     * @param int $modid
     * @param string $maintable
     * @param string $mainkey
     * @param string $submittable
     * @return int
     */
    protected static function std_num_submissions($courseid,
                                                  $modid,
                                                  $maintable,
                                                  $mainkey,
                                                  $submittable,
                                                  $extraselect = '') {
        global $DB;

        static $modtotalsbyid = array();

        if (!isset($modtotalsbyid[$maintable][$courseid])) {
            // Results are not cached, so lets get them.

            // Get people who are typically not students (people who can view grader report) so that we can exclude them!
            list($graderids, $params) = get_enrolled_sql(\context_course::instance($courseid), 'moodle/grade:viewall');
            $params = array_merge(array('courseid' => $courseid), $params);

            // Get the number of submissions for all $maintable activities in this course.
            $sql = "-- Snap sql
                    SELECT m.id, COUNT(DISTINCT sb.userid) as totalsubmitted
                      FROM {".$maintable."} m
                      JOIN {".$submittable."} sb ON m.id = sb.$mainkey
                     WHERE m.course = :courseid
                           AND sb.userid NOT IN ($graderids)
                     GROUP by m.id";
            $modtotalsbyid[$maintable][$courseid] = $DB->get_records_sql($sql, $params);
        }
        $totalsbyid = $modtotalsbyid[$maintable][$courseid];

        if (!empty($totalsbyid)) {
            if (isset($totalsbyid[$modid])) {
                return intval($totalsbyid[$modid]->totalsubmitted);
            }
        }
        return 0;
    }

    /**
     * Get number of submissions for specific assignment
     *
     * @param int $courseid
     * @param int $assignmentid
     * @return int
     */
    public static function assign_num_submissions($courseid, $modid) {
        $extraselect = "sb.status='submitted' AND";
        return self::std_num_submissions($courseid, $modid, 'assign', 'assignment', 'assign_submission', $extraselect);
    }

    /**
     * Get number of answers for specific choice
     *
     * @param int $courseid
     * @param int $choiceid
     * @return int
     */
    public static function choice_num_submissions($courseid, $modid) {
        return self::std_num_submissions($courseid, $modid, 'choice', 'choiceid', 'choice_answers');
    }

    /**
     * Get number of submissions for feedback activity
     *
     * @param int $courseid
     * @param int $feedbackid
     * @return int
     */
    public static function feedback_num_submissions($courseid, $modid) {
        return self::std_num_submissions($courseid, $modid, 'feedback', 'feedback', 'feedback_completed');
    }

    /**
     * Get number of submissions for lesson activity
     *
     * @param int $courseid
     * @param int $feedbackid
     * @return int
     */
    public static function lesson_num_submissions($courseid, $modid) {
        return self::std_num_submissions($courseid, $modid, 'lesson', 'lessonid', 'lesson_attempts');
    }

    /**
     * Get number of attempts for specific quiz
     *
     * @param int $courseid
     * @param int $quizid
     * @return int
     */
    public static function quiz_num_submissions($courseid, $modid) {
        $extraselect = "sb.timefinish IS NOT NULL AND";
        return self::std_num_submissions($courseid, $modid, 'quiz', 'quiz', 'quiz_attempts', $extraselect);
    }

    /**
     * Get number of ungraded quiz attempts for specific quiz
     *
     * @param int $courseid
     * @param int $quizid
     * @return int
     */
    public static function quiz_num_submissions_ungraded($courseid, $quizid) {
        global $DB;

        static $totalsbyquizid;

        // Get people who are typically not students (people who can view grader report) so that we can exclude them!
        list($graderids, $params) = get_enrolled_sql(\context_course::instance($courseid), 'moodle/grade:viewall');
        $params = array_merge(array('courseid' => $courseid), $params);

        if (!isset($totalsbyquizid)) {
            // Results are not cached.
            // Get the number of attempts that requiring marking for all quizes in this course.
            $sql = "-- Snap sql
                    SELECT q.id, count(*) as total
                      FROM {quiz_attempts} sb
                 LEFT JOIN {quiz} q ON q.id=sb.quiz
                 LEFT JOIN {quiz_grades} gd ON gd.quiz = sb.quiz
                           AND gd.userid = sb.userid
                     WHERE sb.timefinish IS NOT NULL
                           AND gd.id IS NULL
                           AND q.course = :courseid
                           AND sb.userid NOT IN ($graderids)
                  GROUP BY q.id";
            $totalsbyquizid = $DB->get_records_sql($sql, $params);
        }

        if (!empty($totalsbyquizid)) {
            if (isset($totalsbyquizid[$quizid])) {
                return intval($totalsbyquizid[$quizid]->total);
            }
        }

        return 0;
    }

    /**
     * Get activity submission row
     *
     * @param $mod
     * @param $submissiontable
     * @param $modfield
     * @param $tabrow
     * @return mixed
     */
    public static function get_submission_row($courseid, $mod, $submissiontable, $modfield, $extraselect='') {
        global $DB, $USER;

        // Note: Caches all submissions to minimise database transactions.
        static $submissions = array();

        // Pull from cache?
        if (isset($submissions[$courseid.'_'.$mod->modname])) {
            if (isset($submissions[$courseid.'_'.$mod->modname][$mod->instance])) {
                return $submissions[$courseid.'_'.$mod->modname][$mod->instance];
            } else {
                return false;
            }
        }

        $submissiontable = $mod->modname.'_'.$submissiontable;
        $sql = "-- Snap sql
                SELECT a.id AS instanceid, st.*
                    FROM {".$submissiontable."} st
                    LEFT JOIN {".$mod->modname."} a ON a.id = st.$modfield
                WHERE a.course = ? AND userid = ? $extraselect ORDER BY $modfield DESC, st.id DESC";
        $submissions[$courseid.'_'.$mod->modname] = $DB->get_records_sql($sql,
            array($courseid, $USER->id, 0, 1));

        if (isset($submissions[$courseid.'_'.$mod->modname][$mod->instance])) {
            return $submissions[$courseid.'_'.$mod->modname][$mod->instance];
        } else {
            return false;
        }
    }

    /**
     * Get the activity dates for a specific module instance
     *
     * @param $courseid
     * @param stdClass $mod
     * @param $timeopenfld
     * @param $timeclosefld
     *
     * @return bool|stdClass
     */
    public static function instance_activity_dates($courseid, $mod, $timeopenfld, $timeclosefld) {
        global $DB;

        // Note: Caches all moduledates to minimise database transactions.
        static $moddates = array();

        if (isset($moddates[$courseid.'_'.$mod->modname])
            && isset($moddates[$courseid.'_'.$mod->modname][$mod->instance])
        ) {
            return $moddates[$courseid.'_'.$mod->modname][$mod->instance];
        }

        $sql = "-- Snap sql
                SELECT id, $timeopenfld AS timeopen, $timeclosefld as timeclose
                    FROM {".$mod->modname."}
                WHERE course = ?";
        $moddates[$courseid.'_'.$mod->modname] = $DB->get_records_sql($sql, array($courseid));

        if (!$moddates || !isset($moddates[$courseid.'_'.$mod->modname][$mod->instance])) {
            return false;
        }

        return $moddates[$courseid.'_'.$mod->modname][$mod->instance];
    }

    /**
     * Return grade row for specific module instance.
     *
     * @param $courseid
     * @param $mod
     * @param $modfield
     * @return bool
     */
    public static function grade_row($courseid, $mod, $modfield) {
        global $DB, $USER;

        static $grades = array();

        if (isset($grades[$courseid.'_'.$mod->modname])
            && isset($grades[$courseid.'_'.$mod->modname][$mod->instance])
        ) {
            return $grades[$courseid.'_'.$mod->modname][$mod->instance];
        }

        $gradetable = $mod->modname.'_grades';
        $sql = "-- Snap sql
                SELECT m.id AS instanceid, gt.*
                    FROM {".$gradetable."} gt
                    LEFT JOIN {".$mod->modname."} m ON gt.$modfield = m.id
                WHERE m.course = ? AND gt.userid = ?";
        $grades[$courseid.'_'.$mod->modname] = $DB->get_records_sql($sql, array($courseid, $USER->id));

        if (isset($grades[$courseid.'_'.$mod->modname][$mod->instance])) {
            return $grades[$courseid.'_'.$mod->modname][$mod->instance];
        } else {
            return false;
        }
    }

    /**
     * Get everything graded from a specific date to the current date.
     *
     * @param null|int $showfrom - timestamp to show grades from. Note if not set defaults to 1 month ago.
     * @return mixed
     */
    public static function events_graded($showfrom = null) {
        global $USER;

        $logmanger = \get_log_manager();
        $readers = $logmanger->get_readers('\core\log\sql_select_reader');
        $reader = reset($readers);

        $select = "userid != :userid
                   AND relateduserid = :relateduserid
                   AND timecreated > :showfrom
                   AND eventname LIKE '%event_course_module_graded'";
        $onemonthago = time() - (DAYSECS * 31);
        $showfrom = $showfrom !== null ? $showfrom : $onemonthago;
        $params = array('userid' => $USER->id, 'relateduserid' => $USER->id, 'showfrom' => $showfrom);
        $sort = 'timecreated DESC';
        $limitfrom = 0;
        $limitnum = 5;
        $events = $reader->get_events_select($select, $params, $sort, $limitfrom, $limitnum);

        // Event data + additional information (course name, module name, course module id).
        $eventdata = array();

        // Add event data to moduleevents array hashed by module name.
        $moduleevents = array();
        foreach ($events as $event) {
            $data = (object) $event->get_data();
            $instanceid = intval($data->other['instanceid']);
            $eventdata[$instanceid] = $data;
            $modname = $data->other['modulename'];
            if (!isset($moduleevents[$modname])) {
                $moduleevents[$modname] = [];
            }
            $moduleevents[$modname][] = $data;
        }

        return $eventdata;
    }
}