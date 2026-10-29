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
$formdata = $cfg ? clone $cfg : (object)['cmid' => $cmid, 'enabled' => 1];
foreach (\local_reviewworkflow\email_template::defaults() as $field => $default) {
    if (!trim($formdata->$field ?? '')) { $formdata->$field = $default; }
}
$form->set_data($formdata);
$preview = [];
if ($form->is_submitted() && optional_param('restoreemail', false, PARAM_BOOL)) {
    require_sesskey();
    $form->restore_email_defaults();
}
if ($data = $form->get_data()) {
    $templates = [];
    foreach (\local_reviewworkflow\email_template::FIELDS as $field) { $templates[$field] = $data->$field; }
    if (!empty($data->previewemail)) {
        foreach (['initial', 'completion'] as $kind) {
            $preview[$kind] = \local_reviewworkflow\email_template::render((object)$templates,
                $kind, \local_reviewworkflow\email_template::example_values());
        }
    } else {
        \local_reviewworkflow\workflow::configure($cm, $cfg ? $cfg->backlog : $data->backlog,
            $data->instructions, $data->certification, !empty($data->enabled), $templates);
        redirect($back, get_string('saved', 'local_reviewworkflow'));
    }
}
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($cm->name) . ': ' . get_string('configure', 'local_reviewworkflow'));
$adapter = new \local_reviewworkflow\adapter\assignment();
if (!$adapter->supports($cm)) { echo $OUTPUT->notification(get_string('unsupported', 'local_reviewworkflow'), 'warning'); }
else {
    $count = iterator_count($adapter->submissions($cm));
    echo html_writer::tag('p', get_string('backlogcount', 'local_reviewworkflow', $count));
    if ($preview) {
        echo $OUTPUT->heading(get_string('emailpreviewheading', 'local_reviewworkflow'), 3);
        echo html_writer::tag('p', get_string('emailpreviewhelp', 'local_reviewworkflow'));
        foreach ($preview as $kind => $mail) {
            echo $OUTPUT->heading(get_string($kind === 'completion' ? 'completionemail' : 'requestemail', 'local_reviewworkflow'), 4);
            echo html_writer::tag('pre', s($mail->subject . "\n\n" . $mail->body), ['class' => 'text-wrap']);
        }
    }
    $form->display();
}
echo $OUTPUT->footer();
