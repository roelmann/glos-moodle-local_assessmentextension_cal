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
 * A scheduled task for scripted database integrations.
 *
 * @package    local_assessmentextension_cal - template
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assessmentextension_cal\task;
use stdClass;
use calendar_event;
use moodle_url;
use assign;
use assign_update_events;
use context_module;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot.'/lib/accesslib.php');
require_once($CFG->dirroot.'/calendar/lib.php');
require_once($CFG->dirroot.'/mod/assign/lib.php');
require_once($CFG->dirroot.'/mod/assign/locallib.php');
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');

/**
 * A scheduled task for scripted external database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assessmentextension_cal extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_assessmentextension_cal');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;
        $submissiontime = date('H:i:s', strtotime('3pm'));

        $externaldb = new \local_extdb\extdb();
        $name = $externaldb->get_name();

        $externaldbtype = $externaldb->get_config('dbtype');
        $externaldbhost = $externaldb->get_config('dbhost');
        $externaldbname = $externaldb->get_config('dbname');
        $externaldbencoding = $externaldb->get_config('dbencoding');
        $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
        $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
        $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
        $externaldbuser = $externaldb->get_config('dbuser');
        $externaldbpassword = $externaldb->get_config('dbpass');
        $tableassm = get_string('assessmentstable', 'local_assessmentextension_cal');
        $tablegrades = get_string('stuassesstable', 'local_assessmentextension_cal');

        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$externaldbtype) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $externaldbtype . '<br>';
        }
        // Check remote assessments table - usr_data_assessments.
        if (!$tableassm) {
            echo 'Assessments Table not defined.<br>';
            return 0;
        } else {
            echo 'Assessments Table: ' . $tableassm . '<br>';
        }
        // Check remote student grades table - usr_data_student_assessments.
        if (!$tablegrades) {
            echo 'Student Grades Table not defined.<br>';
            return 0;
        } else {
            echo 'Student Grades Table: ' . $tablegrades . '<br>';
        }
        echo 'Starting connection...<br>';

        // Report connection error if occurs.
        if (!$extdb = $externaldb->db_init(
            $externaldbtype,
            $externaldbhost,
            $externaldbuser,
            $externaldbpassword,
            $externaldbname)) {
            echo 'Error while communicating with external database <br>';
            return 1;
        }

        $extensions = array();
        // Read grades and extensions data from external table.
        /********************************************************
         * ARRAY                                                *
         *     id                                               *
         *     student_code                                     *
         *     assessment_idcode                                *
         *     student_ext_duedate                               *
         *     student_ext_duetime                              *
         *     student_fbdue_date                               *
         *     student_fbdue_time                               *
         ********************************************************/
        $sql = $externaldb->db_get_sql($tablegrades, array(), array(), true);
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $externaldb->db_decode($fields);
                    $extensions[] = $fields;
                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table<br>';
            return 4;
        }

        // Create reference array of students - if has a linked assessement AND an extension date/time.
        $student = array();
        foreach ($extensions as $e) {
            $key = $e['student_code'].$e['assessment_idcode'];
            if ($e['assessment_idcode'] && ($e['student_ext_duedate'] || $e['student_ext_duetime'])) {
                $student[$key]['stucode'] = $e['student_code'];
                $student[$key]['lc'] = $e['assessment_idcode'];
                $student[$key]['extdate'] = $e['student_ext_duedate'];
                $student[$key]['exttime'] = $e['student_ext_duetime'];
            }
        }

        // Set extensions.
        // Echo statements output to cron or when task run immediately for debugging.
        foreach ($student as $k => $v) {
            if (!empty($student[$k]['extdate'])) {
                // Get dates.
                if (strpos($student[$k]['lc'], '18/19') > 1) {
                    echo '18/19 HANDIN 6pm<br>';
                    $submissiontime = date('H:i:s', strtotime('6pm'));
                }

                $extdate = $student[$k]['extdate'];
                $exttime = $submissiontime;
                // Convert dates and time to Unix time stamp.
                $exttimestamp = strtotime($extdate.' '.$exttime);
                
                $userid = $DB->get_field('user', 'id', array('idnumber' => 's'.$student[$k]['stucode']));
                $cm = $DB->get_record('course_modules', array('idnumber' => $student[$k]['lc']));
                if (!empty($cm->id)) {
                    $assignid = $cm->instance;
                    $courseid = $cm->course;
                    $context = context_module::instance($cm->id);
                    $assigncal = new assign($context, $cm, $courseid);
                    echo '<br><p>userid '.$userid.' : module '.$courseid.' : assignment '.$assignid.'</p>';
                }

                // Note to self: assign_update_submissions is module based, not user based, so will trigger update for
                // everyone on the module whenever an update is fired. Therefore make sure only firing for NEW instances.
                // Will still update everyone, but will only do so on modules where there is a new extension.
                if (!$DB->record_exists('event', array('userid' => $userid, 'modulename' => 'assign',
                    'instance' => $assignid, 'eventtype' => 'due'))) {
                    // Create calendar overrides.
                    if (!empty($context) && !empty($cm) && !empty($courseid)) {
                        if ($DB->record_exists('assign', array('id' => $assignid))) {
                            echo 'create calendar override'."\n";
                            echo $exttimestamp;
                            assign_update_events($assigncal);
                        }
                    }
                }
            // reset submission time to 3pm
            $submissiontime = date('H:i:s', strtotime('3pm'));
            }
        }

        // Reset change flags.

        // Free memory.
        $extdb->Close();
    }

}
