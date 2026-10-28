<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT);
$cm = \local_reviewworkflow\workflow::cm($cmid);
$course = get_course($cm->course);
require_login($course, false, $cm);
if (!\local_reviewworkflow\access::manager($cm)) { throw new moodle_exception('forbidden', 'local_reviewworkflow'); }
$PAGE->set_context(context_module::instance($cmid));
$PAGE->set_url('/local/reviewworkflow/configure.php', ['cmid' => $cmid]);
$PAGE->set_title(get_string('configure', 'local_reviewworkflow'));
$PAGE->set_heading($course->fullname);
$back = new moodle_url('/local/reviewworkflow/index.php', ['courseid' => $course->id]);
$cfg = $DB->get_record('local_reviewworkflow_cfg', ['cmid' => $cmid]);
$form = new \local_reviewworkflow\form\configure(null, ['configured' => (bool)$cfg]);
if ($form->is_cancelled()) { redirect($back); }
$form->set_data($cfg ? (object)((array)$cfg + ['cmid' => $cmid]) : (object)['cmid' => $cmid, 'enabled' => 1]);
if ($data = $form->get_data()) {
    \local_reviewworkflow\workflow::configure($cm, $cfg ? $cfg->backlog : $data->backlog,
        $data->instructions, $data->certification, !empty($data->enabled));
    redirect($back, get_string('saved', 'local_reviewworkflow'));
}
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($cm->name) . ': ' . get_string('configure', 'local_reviewworkflow'));
$adapter = new \local_reviewworkflow\adapter\assignment();
if (!$adapter->supports($cm)) { echo $OUTPUT->notification(get_string('unsupported', 'local_reviewworkflow'), 'warning'); }
else {
    $count = iterator_count($adapter->submissions($cm));
    echo html_writer::tag('p', get_string('backlogcount', 'local_reviewworkflow', $count));
    $form->display();
}
echo $OUTPUT->footer();
