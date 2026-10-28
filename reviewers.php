<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT);
$cm = \local_reviewworkflow\workflow::cm($cmid); $course = get_course($cm->course);
require_login($course, false, $cm);
$context = context_module::instance($cmid);
if (!\local_reviewworkflow\access::manager($cm)) { throw new moodle_exception('forbidden', 'local_reviewworkflow'); }
$PAGE->set_context($context); $PAGE->set_url('/local/reviewworkflow/reviewers.php', ['cmid' => $cmid]);
$PAGE->set_title(get_string('reviewers', 'local_reviewworkflow')); $PAGE->set_heading($course->fullname);
$students = []; $teachers = [];
foreach (get_enrolled_users($context, 'mod/assign:submit', 0, 'u.*', 'u.lastname, u.firstname', 0, 0, true) as $u) {
    if (!$u->suspended && !$u->deleted) { $students[$u->id] = fullname($u); }
}
foreach (get_enrolled_users($context, 'mod/assign:grade', 0, 'u.*', 'u.lastname, u.firstname', 0, 0, true) as $u) {
    if (!$u->suspended && !$u->deleted && has_capability('local/reviewworkflow:review', $context, $u)) {
        $teachers[$u->id] = fullname($u);
    }
}
$form = new \local_reviewworkflow\form\reviewers(null, ['students' => $students, 'teachers' => $teachers]);
$form->set_data(['cmid' => $cmid]);
$back = new moodle_url('/local/reviewworkflow/index.php', ['courseid' => $course->id]);
if ($form->is_cancelled()) { redirect($back); }
if ($data = $form->get_data()) {
    \local_reviewworkflow\workflow::map($cm, $data->students, $data->teachers, $data->operation === 'remove');
    redirect($PAGE->url, get_string('saved', 'local_reviewworkflow'));
}
echo $OUTPUT->header(); echo $OUTPUT->heading(format_string($cm->name) . ': ' . get_string('reviewers', 'local_reviewworkflow'));
echo html_writer::tag('p', get_string('mappinghelp', 'local_reviewworkflow'));
$form->display();
$table = new html_table(); $table->head = [get_string('student', 'local_reviewworkflow'), get_string('reviewers', 'local_reviewworkflow')];
foreach ($students as $id => $name) {
    $names = [];
    foreach ($DB->get_records('local_reviewworkflow_map', ['cmid' => $cmid, 'studentid' => $id]) as $m) {
        $names[] = isset($teachers[$m->reviewerid]) ? s($teachers[$m->reviewerid]) : get_string('ineligiblereviewer', 'local_reviewworkflow');
    }
    $table->data[] = [s($name), $names ? implode(', ', $names) : get_string('reviewerneeded', 'local_reviewworkflow')];
}
echo html_writer::table($table); echo $OUTPUT->footer();
