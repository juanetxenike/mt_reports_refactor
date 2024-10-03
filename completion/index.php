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
 * Course completion progress report
 *
 * @package    report
 * @subpackage completion
 * @copyright  2024 onwards WIDE Services {@link https://www.wideservices.gr}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\report_helper;
use report_completion\course_report_pdf;
use report_completion\engine;

require('../../config.php');
require_once("{$CFG->libdir}/completionlib.php");

/**
 * Configuration
 */
define('COMPLETION_REPORT_PAGE',        25);
define('COMPLETION_REPORT_COL_TITLES',  true);

/*
 * Setup page
 */
// Get parameters
$courseid = required_param('course', PARAM_INT);
$format = optional_param('format','',PARAM_ALPHA);
$sort = optional_param('sort','',PARAM_ALPHA);
$edituser = optional_param('edituser', 0, PARAM_INT);

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id);
$url = new moodle_url('/report/completion/index.php', ['course'=>$course->id]);

$firstnamesort = ($sort == 'firstname');

$excel = ($format == 'excelcsv');
$csv = ($format == 'csv' || $excel);

$dateformat = $csv ? get_string('strftimedatetimeshort', 'langconfig'): "%F %T";

// DEFINE ACCESS.
require_login($course);
require_capability('report/completion:view', $context);

// The group parameter is optional, but if it is present, it must be valid.
// It serves two purposes.
// 1. To verify that the group exists in the course, and if it doesn´t to verify that the course has groups.
// And that the user has the capability to access all groups.
// 2. It is used to filter the users that are going to be displayed in the report.
$group = groups_get_course_group($course, true); // Supposed to verify group.
if ($group === 0 && $course->groupmode == SEPARATEGROUPS) {
    require_capability('moodle/site:accessallgroups', $context);
}

// Retrieve course_module data for all modules in the course.
$modinfo = get_fast_modinfo($course);

// DATA PREPARATION.
// Get completion information for course.
$completion = new completion_info($course);
// Create a criteria array to store the criteria.
$criteria = [];

// The criteria will on one hand contain the completion criteria for the course and the completion criteria for activities.
// define('COMPLETION_CRITERIA_TYPE_COURSE', 8);
// define('COMPLETION_CRITERIA_TYPE_ACTIVITY', 9);
// These are references to the mdl_course_completion_criteria table and are defined in the completionlib.php file.
$criteria = array_merge(
    $completion->get_criteria(COMPLETION_CRITERIA_TYPE_COURSE),
    $completion->get_criteria(COMPLETION_CRITERIA_TYPE_ACTIVITY),
    array_filter(
        $completion->get_criteria(),
        fn($criterion) => !in_array($criterion->criteriatype, [
            COMPLETION_CRITERIA_TYPE_COURSE, COMPLETION_CRITERIA_TYPE_ACTIVITY,
        ])
    )
);

// Generate where clause,
// The following variables will be used as a part of a sql query within the get_progress_all function.
// This function gets parts of the query as parameters and returns a list of users with their completion status.
$sifirst = optional_param('sifirst', 'all', PARAM_NOTAGS);
$silast  = optional_param('silast', 'all', PARAM_NOTAGS);
$preferences = ['ifirst' => $sifirst, 'ilast' => $silast];
array_map( fn($key, $value) => $value !== 'all'
            ? set_user_preference($key, $value)
            : null,
            array_keys($preferences),
        $preferences);

$sifirst = $USER->preference['ifirst'] ?? 'all';
$silast  = $USER->preference['ilast'] ?? 'all';

$where = [];
$whereparams = [];

$fields = [
    'sifirst' => 'u.firstname',
    'silast' => 'u.lastname',
];

// Iterate through the fields array and assign values.
array_walk($fields, function($column, $param) use (&$where, &$whereparams, $sifirst, $silast, $DB) {
    $paramvalue = ($param === 'sifirst') ? $sifirst : $silast;
    if ($paramvalue !== 'all') {
        $where[] = $DB->sql_like($column, ":$param", false, false);
        $whereparams[$param] = $paramvalue . '%';
    }
});

// Get user match count.
$total = $completion->get_num_tracked_users(implode(' AND ', $where), $whereparams, $group);
// Total user count.
$grandtotal = $completion->get_num_tracked_users('', [], $group);
$totalheader = ($total == $grandtotal) ? $total : "{$total}/{$grandtotal}";

// Get user data.
// Obtains progress information across a course for all users on that course.
// Or for all users in a specific group. Intended for use when displaying progress.
$progress = ($total) ? $completion->get_progress_all(
                            implode(' AND ', $where), // AND LIKE u.firstname = :sifirst AND LIKE u.lastname = :silast.
                            $whereparams, // Placeholders: firstname% and lastname%.
                            $group, // Active group in course.
                            'u.lastname ASC',
                            0,
                            0,
                            $context)
            : [];

// CREATE DATA TO EXPORT TO TEMPLATE.
$extrafields = \core_user\fields::get_identity_fields($context, true);
$leftcols = 1 + count($extrafields);

// TYPE.
$hasagg = [
    COMPLETION_CRITERIA_TYPE_COURSE,
    COMPLETION_CRITERIA_TYPE_ACTIVITY,
    COMPLETION_CRITERIA_TYPE_ROLE,
];

$engine = new engine;

// CRITERIA TYPES
$criteriaheaders = $engine->criteria_types($criteria, $completion, $hasagg);
// END CRITERIA HEADERS

// CRITERIA METHODS.
$criteriamethodheaders = $engine->criteria_methods($criteria, $completion, $hasagg);
// END CRITERIA METHODS HEADERS

// SECTIONS HEADERS.
$sectionheaders = $engine->section_headers($criteria, $modinfo);
// END SECTION HEADERS.

// CRITERIA ICONS.
$criteriaicons = $engine->criteria_icons($criteria, $modinfo);
// END CRITERIA ICONS.

/* foreach ($criteria as $criterion) {
    // Generate icon details
    $iconlink = '';
    $iconalt = ''; // Required
    $iconattributes = ['class' => 'icon'];
        switch ($criterion->criteriatype) {
            case COMPLETION_CRITERIA_TYPE_ACTIVITY:
                // Display icon
                $iconlink = $CFG->wwwroot.'/mod/'.$criterion->module.'/view.php?id='.$criterion->moduleinstance;
                $iconattributes['title'] = $modinfo->cms[$criterion->moduleinstance]->get_formatted_name();
                $iconalt = get_string('modulename', $criterion->module);
                break;

            case COMPLETION_CRITERIA_TYPE_COURSE:
                // Load course
                $crs = $DB->get_record('course', array('id' => $criterion->courseinstance));

                // Display icon
                $iconlink = $CFG->wwwroot.'/course/view.php?id='.$criterion->courseinstance;
                $iconattributes['title'] = format_string($crs->fullname, true, array('context' => context_course::instance($crs->id, MUST_EXIST)));
                $iconalt = format_string($crs->shortname, true, array('context' => context_course::instance($crs->id)));
                break;

            case COMPLETION_CRITERIA_TYPE_ROLE:
                // Load role
                $role = $DB->get_record('role', array('id' => $criterion->role));

                // Display icon
                $iconalt = $role->name;
                break;
        }

        // Create icon alt if not supplied
        if (!$iconalt) {
            $iconalt = $criterion->get_title();
        }

        $criteriaicons[] = $OUTPUT->render($criterion->get_icon($iconalt, $iconattributes));

} */

// Print criteria titles.
if (COMPLETION_REPORT_COL_TITLES) {
    $criteriatitles = array_map(function($criterion) {
        return $criterion->get_title_detailed();
    }, $criteria);
}

// USERS PROGRESS.
// We will loop through the users progress results and display them in rows and columns accordingl to the criteria.
// We will return the following array.
// [ 'fullname' => '',
// 'fields' => [
// 'email' => '',
// 'fullname' => ''
// 'lastname' => ''],
// 'criteria' => [
// ['date' => '',
// 'describe' => '']
// ],
// 'coursecomplete' => [
// 'date' => '',
// 'description' => '']
// ].

$usersarray = array_map(function($user) use($extrafields, $context, $criteria, $completion, $modinfo, $dateformat, $course, $OUTPUT, $format) {
    // Load course completion.
    $coursecompletion = new completion_completion(['userid' => $user->id, 'course'    => $course->id]);
    $coursecompletiontype =  $coursecompletion->is_complete() ? 'y' : 'n';

    $coursedescribe = get_string('completion-'.$coursecompletiontype, 'completion');
    $coursea = new StdClass;
    $coursea->state    = $coursedescribe;
    $coursea->user     = fullname($user);
    $coursea->activity = strip_tags(get_string('coursecomplete', 'completion'));
    $coursefulldescribe = get_string('progress-title', 'completion', $coursea);

    return [
        'fullname' => fullname($user, has_capability('moodle/site:viewfullnames', $context)),
        'fields' => array_map(fn($field) => s($user->{$field}), $extrafields),
        'criteria' => array_map(function($criterion) use($user, $completion, $modinfo, $dateformat, $OUTPUT, $format) {
            $criteriacompletion = $completion->get_user_completion($user->id, $criterion);
            $iscomplete = $criteriacompletion->is_complete();
            // Load activity.
            $activity = $modinfo->cms[$criterion->moduleinstance];
            $state = COMPLETION_INCOMPLETE;
            if (array_key_exists($activity->id, $user->progress)) {
                $state = $user->progress[$activity->id]->completionstate;
            } else if ($iscomplete) {
                $state = COMPLETION_COMPLETE;
            }
            $date = $iscomplete
                        ? userdate($criteriacompletion->timecompleted, $dateformat)
                        :'';

            switch($state){
                case COMPLETION_INCOMPLETE: $completiontype = 'n'; break;
                case COMPLETION_COMPLETE: $completiontype = 'y'; break;
                case COMPLETION_COMPLETE_PASS: $completiontype = 'pass'; break;
                case COMPLETION_COMPLETE_FAIL: $completiontype = 'fail'; break;
                default: throw new \UnexpectedValueException('Unexpected state value');
            }
            $auto = $activity->completion == COMPLETION_TRACKING_AUTOMATIC;
            $completionicon = 'completion-'.($auto ? 'auto' : 'manual').'-'.$completiontype;
            $describe = get_string('completion-'.$completiontype, 'completion');
            
            $a = new StdClass();
            $a->state     = $describe;
            $a->date      = $date;
            $a->user      = fullname($user);
            $a->activity  = $activity->get_formatted_name();
            $fulldescribe = get_string('progress-title', 'completion', $a);

            $returnarray = ['date' => $date];
            //$returnarray['describe'] = $OUTPUT->render($criterion->get_icon('alt', ['title'=> 'title']));
            $returnarray['describe'] = $OUTPUT->pix_icon('i/' . $completionicon, $fulldescribe);
            if($format == "pdf")
                $returnarray['describe'] = ($completiontype == 'n') ? '6' : '3';
            return $returnarray;
        }, $criteria),
        'coursecomplete' => [
            'date' => $coursecompletion->is_complete() ?
                userdate($ccoursecompletion->timecompleted, $dateformat) : '',
            'description' => ($format == "pdf")
            ? $coursecompletion->is_complete() ? '3' : '6'
            : $OUTPUT->pix_icon('i/completion-auto-' . $coursecompletiontype, $coursefulldescribe),
        ],
    ];
}, $progress);
$fieldsarray = array_map(function($field) {
    return \core_user\fields::get_display_name($field);
}, $extrafields);

if($csv) {
    $row = [];
    $row[] = get_string('id', 'report_completion');
    $row[] = get_string('name', 'report_completion');
    foreach ($extrafields as $field) {
        $row[] = \core_user\fields::get_display_name($field);
    }
    require_once("{$CFG->libdir}/csvlib.class.php");
    $shortname = format_string($course->shortname, true, array('context' => $context));
    $shortname = preg_replace('/[^a-z0-9-]/', '_',core_text::strtolower(strip_tags($shortname)));
    $export = new csv_export_writer('comma', '"', 'application/download', $excel);
    $export->set_filename('completion-'.$shortname);
    
    foreach ($criteria as $criterion) {
        // Handle activity completion differently
        if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {

            // Load activity
            $mod = $criterion->get_mod_instance();

            $activity = $modinfo->cms[$criterion->moduleinstance];
            $sectionname = get_section_name($activity->course, $activity->sectionnum);
            $formattedname = format_string($mod->name, true,
                    array('context' => context_module::instance($criterion->moduleinstance)));
            $row[] = $formattedname . ' - ' . $sectionname;
            // ws_custom_e
            // ws_enhancement_e
            $row[] = $formattedname . ' - ' . get_string('completiondate', 'report_completion');
        } else {
            // Handle all other criteria
            $row[] = strip_tags($criterion->get_title_detailed());
        }
    }

    $row[] = get_string('coursecomplete', 'completion');
    
    $export->add_data($row);
    
    foreach ($progress as $user) {
        $row = [];
        $row[] = $user->id;
        $row[] = fullname($user, has_capability('moodle/site:viewfullnames', $context));
        foreach ($extrafields as $field) {
            $row[] = $user->{$field};
        }
        foreach ($criteria as $criterion) {
            $criteria_completion = $completion->get_user_completion($user->id, $criterion);
            if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                // Get progress information and state
                $activity = $modinfo->cms[$criterion->moduleinstance];
                $state = COMPLETION_INCOMPLETE;
                if (array_key_exists($activity->id, $user->progress)) {
                    $state = $user->progress[$activity->id]->completionstate;
                } else if ($is_complete) {
                    $state = COMPLETION_COMPLETE;
                }
                $completiontype = match ($state) {
                    COMPLETION_INCOMPLETE    => 'n',
                    COMPLETION_COMPLETE      => 'y',
                    COMPLETION_COMPLETE_PASS => 'pass',
                    COMPLETION_COMPLETE_FAIL => 'fail',
                };
                $row[] = get_string('completion-'.$completiontype, 'completion');
                $is_complete = $criteria_completion->is_complete();
                $row[] = $is_complete ? userdate($criteria_completion->timecompleted, $dateformat) : '';
            }
        }
        // Load course completion
        $params = [
            'userid'    => $user->id,
            'course'    => $course->id
        ];
        $ccompletion = new completion_completion($params);
        $row[] = $ccompletion->is_complete()
                    ? userdate($ccompletion->timecompleted, $dateformat)
                    : '';
        $export->add_data($row);
    }
    $export->download_file();
    exit;
}
// END CSV.

// CREATE HTML.
$a = $course->fullname;
$html = $OUTPUT->render_from_template(
    'report_completion/table',
    (object) [
        'title' => get_string('coursecompletion'),
        'totalparticipants' => get_string('allparticipants').": {$totalheader}",
        'leftcols' => $leftcols,
        'criteriaheaders' => array_values($criteriaheaders),
        'criteriamethodheaders' => array_values($criteriamethodheaders),
        'courseaggregationheader' => $completion->get_aggregation_method() == 1 ? get_string('all') : get_string('any'),
        'fields' => $fieldsarray,
        'criteria' => $criteriatitles,
        'criteriaicons' => $criteriaicons,
        'sectionheaders' => (array) $sectionheaders,
        'users' => array_values($usersarray),
        'ishtml' => ($format != 'csv' && $format != 'pdf' && $format !='excelcsv') ? true: false,
        'csvurl' => (new moodle_url('/report/completion/index.php', ['course' => $course->id, 'format' => 'csv']))->out(),
        'excelurl' => new moodle_url('/report/completion/index.php', ['course' => $course->id, 'format' => 'excelcsv']),
        'pdfurl' => new moodle_url('/report/completion/index.php', ['course' => $course->id, 'format' => 'pdf']),
        'coursecompleteicon' => $OUTPUT->pix_icon('i/course', get_string('coursecomplete', 'completion')),
]);
if($format == 'pdf') {
    require_once("{$CFG->libdir}/pdflib.php");
    // SEND TO PDF OUTPUT.
    $pdf = new course_report_pdf();
    $pdf->writeHTML($html);
    $pdf->Output('completion.pdf', 'I');
    exit;
}
// PAGE SETUP
// If no users in this course what-so-ever
if (!$grandtotal) {
    echo $OUTPUT->container(get_string('err_nousers', 'completion'), 'errorbox errorboxcontent');
    echo $OUTPUT->footer();
    exit;
}
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_context($context);
$PAGE->set_title(get_string('coursecompletion'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

//echo $OUTPUT->heading(get_string('pluginname', 'report_completion'));
$pluginname = get_string('pluginname', 'report_completion');
report_helper::print_report_selector($pluginname);

echo $engine->pagingbar($course, $sort, $sifirst, $silast,$total, $url);
if (!$total) {
        echo $OUTPUT->notification(get_string('nothingtodisplay'), 'info', false);
        echo $OUTPUT->footer();
        exit;
    }

echo $html;
echo $OUTPUT->footer($course);
