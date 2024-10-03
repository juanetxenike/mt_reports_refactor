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

namespace report_completion;

/**
 * Class engine
 *
 * @package    report_completion
 * @copyright  2024 onwards WIDE Services {@link https://www.wideservices.gr}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {

    public function criteria_types($criteria, $completion, $hasagg) {
        // Filter out repeated "type" values, keeping only the last occurrence.
        // Criteria types will have a list of arrays only with unique values.
        $criteriatypes =  array_values(array_reduce($criteria, function($acc, $criterion) use ($completion, $hasagg) {
                    $acc[$criterion->criteriatype] = [
                        'type' => $criterion->criteriatype,
                        'title' => $criterion->get_type_title(),
                    ];
                    return $acc;
                }, []));
        // Since we forced the former array to have unique values, we can now get the count of each "type" value.
        $typecounts = array_map(function($criterion) use($completion, $hasagg) {
            return [
                'type' => $criterion->criteriatype,
            ];
        }, $criteria);
        $typecountsarray = array_count_values(array_column($typecounts, 'type'));
        // Map the unique "type" values to include the colcount.
        // The colcount will be the number of times the "type" value appears in the $criteria array.
        // This will allow us to span a column for each "type" value along the number of times it appears.
        return array_map(function($item, $key) use ($typecounts,  $typecountsarray) {
            return [
                'colcount' => $typecountsarray[$item['type']],
                'currentgrouptypetitle' => $item['title'],
            ];
        }, $criteriatypes, array_keys($criteriatypes));
    }

    public function criteria_methods($criteria, $completion, $hasagg) {
        // Filter out repeated "method" values, keeping only the last occurrence.
        // Criteria types will have a list of arrays only with unique values.
        $criteriamethods = array_reduce($criteria, function($carry, $criterion) use ($completion, $hasagg) {
            // Try load a aggregation method.
            $carry[$criterion->criteriatype] = [
                'method' => (in_array($criterion->criteriatype, $hasagg)) ?
                        ($completion->get_aggregation_method($criterion->criteriatype) == 1 ?
                            get_string('all')
                            : get_string('any'))
                        : '-',
            ];
            return $carry;
        }, []);

        // Since we forced the former array to have unique values, we can now get the count of each "method" value.
        $methodcounts = array_map(function($criterion) use($completion, $hasagg) {
            return  [ 'method' => (in_array($criterion->criteriatype, $hasagg)) ?
                        ($completion->get_aggregation_method($criterion->criteriatype) == 1 ?
                            get_string('all')
                            : get_string('any'))
                        : '-'];
        }, $criteria);
        $methodcountsarray = array_count_values(array_column($methodcounts, 'method'));

        // Map the unique "method" values to include the colcount.
        // The colcount will be the number of times the "method" value appears in the $criteriamethods array.
        // This will allow us to span a column for each "method" value along the number of times it appears.
        return array_map(function($item, $key) use ($methodcountsarray) {
            return [
                'colcount' => $methodcountsarray[$item['method']],
                'method' => $item['method'],
            ];
        }, $criteriamethods, array_keys($criteriamethods));
    }

    public function section_headers($criteria, $modinfo){
        // Filter out repeated section values, keeping only the last occurrence.

        $sectionsarray = array_values(array_reduce($criteria, function ($acc, $criterion) use($modinfo) {
            if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                $activity = $modinfo->cms[$criterion->moduleinstance];
                $sectionname = get_section_name($activity->course, $activity->sectionnum);
                $acc[$sectionname] = [
                    'section' => $activity->section,
                    'sectionname' => $sectionname,
                ];
            }
            return $acc;
        }, []));

        // Count the occurrences of each "section".
        // Since we forced the former array to have unique values, we can now get the count of each "method" value.
        $sectioncounts = array_map(function($criterion) use($modinfo) {
            if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                $activity = $modinfo->cms[$criterion->moduleinstance];
                return [
                    'type' => get_section_name($activity->course, $activity->sectionnum),
                ];
            }
        }, $criteria);

        $sectioncountsarray = array_count_values(array_column($sectioncounts, 'type'));
        // Map the unique "section" values to include the colcount.
        // The colcount will be the number of times the "section" value appears in the $criteriamethods array.
        // This will allow us to span a column for each "section" value along the number of times it appears.
        return array_map(function($section) use ($sectioncountsarray) {
            return [
                'sectionname' => $section['sectionname'],
                'colcount' => $sectioncountsarray[$section['sectionname']],
            ];
        }, array_values($sectionsarray));
    }

    public function criteria_icons($criteria, $modinfo) {
        global $DB, $CFG, $OUTPUT;
        $criteriaicons = [];
        foreach ($criteria as $criterion) {
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

            $criteriaicons[] = [
                'icon' => $OUTPUT->render($criterion->get_icon($iconalt, $iconattributes)),
                'url' => $iconlink,
                'title' => $iconattributes['title']
            ];
        }
        return $criteriaicons;
    }

    public function pagingbar($course, $sort, $sifirst, $silast,$total, $url) {
        global $CFG, $OUTPUT;
        // Build link for paging
        $link = $CFG->wwwroot.'/report/completion/index.php?course='.$course->id;
        if (strlen($sort)) {
            $link .= '&amp;sort='.$sort;
        }
        $link .= '&amp;start=';

        $pagingbar = '';

        // Initials bar.
        $prefixfirst = 'sifirst';
        $prefixlast = 'silast';
        $pagingbar .= $OUTPUT->initials_bar($sifirst, 'firstinitial', get_string('firstname'), $prefixfirst, $url);
        $pagingbar .= $OUTPUT->initials_bar($silast, 'lastinitial', get_string('lastname'), $prefixlast, $url);

        // Do we need a paging bar?
        if ($total > COMPLETION_REPORT_PAGE) {
            // Paging bar
            $pagingbar .= '<div class="paging">';
            $pagingbar .= get_string('page').': ';

            $sistrings = array();
            if ($sifirst != 'all') {
                $sistrings[] =  "sifirst={$sifirst}";
            }
            if ($silast != 'all') {
                $sistrings[] =  "silast={$silast}";
            }
            $sistring = !empty($sistrings) ? '&amp;'.implode('&amp;', $sistrings) : '';

            // Display previous link
            if ($start > 0) {
                $pstart = max($start - COMPLETION_REPORT_PAGE, 0);
                $pagingbar .= "(<a class=\"previous\" href=\"{$link}{$pstart}{$sistring}\">".get_string('previous').'</a>)&nbsp;';
            }

            // Create page links
            $curstart = 0;
            $curpage = 0;
            while ($curstart < $total) {
                $curpage++;

                if ($curstart == $start) {
                    $pagingbar .= '&nbsp;'.$curpage.'&nbsp;';
                }
                else {
                    $pagingbar .= "&nbsp;<a href=\"{$link}{$curstart}{$sistring}\">$curpage</a>&nbsp;";
                }

                $curstart += COMPLETION_REPORT_PAGE;
            }

            // Display next link
            $nstart = $start + COMPLETION_REPORT_PAGE;
            if ($nstart < $total) {
                $pagingbar .= "&nbsp;(<a class=\"next\" href=\"{$link}{$nstart}{$sistring}\">".get_string('next').'</a>)';
            }

            $pagingbar .= '</div>';
        }
        return $pagingbar;
    }
}
