<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
$courseid = required_param('courseid', PARAM_INT); $course = get_course($courseid);
require_login($course);
\local_reviewworkflow\exporter::require_course($courseid);
$context = context_course::instance($courseid);
$PAGE->set_context($context); $PAGE->set_url('/local/reviewworkflow/results.php', ['courseid' => $courseid]);
$PAGE->set_title(get_string('exporttitle', 'local_reviewworkflow')); $PAGE->set_heading($course->fullname);
$action = optional_param('action', '', PARAM_ALPHA); $id = optional_param('id', 0, PARAM_INT);
$format = optional_param('format', '', PARAM_ALPHA);
if ($action === 'download') {
    $job = \local_reviewworkflow\exporter::job($id);
    if ($job->courseid != $courseid) { throw new moodle_exception('forbidden', 'local_reviewworkflow'); }
    $file = \local_reviewworkflow\exporter::download($id);
    \core\session\manager::write_close();
    send_stored_file($file, 0, 0, true); exit;
}
if ($format === 'csv') {
    $filters = \local_reviewworkflow\form\report_filters::parameters();
    $path = make_request_directory() . '/results.csv'; $stream = fopen($path, 'w');
    try { \local_reviewworkflow\exporter::csv($courseid, $filters, $stream); }
    finally { fclose($stream); }
    \core\session\manager::write_close();
    send_temp_file($path, 'review-results.csv'); exit;
}
if ($action === 'queue') {
    require_sesskey();
    if (!data_submitted()) { throw new moodle_exception('invalidformdata'); }
    $id = \local_reviewworkflow\exporter::enqueue($courseid, \local_reviewworkflow\form\report_filters::parameters());
    redirect(new moodle_url($PAGE->url, ['id' => $id]), get_string('exportqueued', 'local_reviewworkflow'));
}
echo $OUTPUT->header(); echo $OUTPUT->heading(get_string('exporttitle', 'local_reviewworkflow'));
echo html_writer::tag('p', get_string('exportexpiryhelp', 'local_reviewworkflow'));
if ($id) {
    $job = \local_reviewworkflow\exporter::job($id);
    if ($job->courseid != $courseid) { throw new moodle_exception('forbidden', 'local_reviewworkflow'); }
    echo $OUTPUT->heading('#' . $job->id . ': ' . get_string('exportstate_' . $job->state, 'local_reviewworkflow'), 3);
    echo html_writer::tag('p', get_string('progress', 'local_reviewworkflow') . ': ' . $job->processed . ' / ' . $job->total);
    if ($job->filters) {
        echo html_writer::tag('p', s(\local_reviewworkflow\exporter::describe_filters(json_decode($job->filters, true))));
    }
    if ($job->error) { echo $OUTPUT->notification(get_string('exportfailed', 'local_reviewworkflow'), 'warning'); }
    echo html_writer::link(new moodle_url($PAGE->url, ['id' => $id]), get_string('exportrefresh', 'local_reviewworkflow'));
}
$page = max(0, optional_param('page', 0, PARAM_INT));
$jobs = $DB->get_records('local_reviewworkflow_exp', ['courseid' => $courseid, 'userid' => $USER->id], 'id DESC', '*', $page * 20, 20);
$table = new html_table(); $table->head = ['ID', get_string('status', 'local_reviewworkflow'),
    get_string('progress', 'local_reviewworkflow'), get_string('expires', 'local_reviewworkflow'), get_string('actions')];
foreach ($jobs as $job) {
    $expired = $job->expires <= time();
    $link = html_writer::link(new moodle_url($PAGE->url, ['id' => $job->id]), get_string('exportrefresh', 'local_reviewworkflow'));
    if ($job->state === 'ready' && !$expired) {
        $link .= ' | ' . html_writer::link(new moodle_url($PAGE->url, ['action' => 'download', 'id' => $job->id]), get_string('download'));
    }
    $table->data[] = [$job->id, get_string('exportstate_' . ($expired ? 'expired' : $job->state), 'local_reviewworkflow'),
        $job->processed . ' / ' . $job->total, userdate($job->expires), $link];
}
echo html_writer::table($table);
if (!$jobs) { echo html_writer::tag('p', get_string('noexports', 'local_reviewworkflow')); }
if ($page) { echo html_writer::link(new moodle_url($PAGE->url, ['page' => $page - 1]), get_string('previous')); }
if (count($jobs) === 20) { echo html_writer::link(new moodle_url($PAGE->url, ['page' => $page + 1]), get_string('next')); }
echo html_writer::tag('p', html_writer::link(new moodle_url('/local/reviewworkflow/index.php', ['courseid' => $courseid]), get_string('dashboard', 'local_reviewworkflow')));
echo $OUTPUT->footer();
