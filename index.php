<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
$courseid = required_param('courseid', PARAM_INT); $course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
$PAGE->set_context($context); $PAGE->set_url('/local/reviewworkflow/index.php', ['courseid' => $courseid]);
$PAGE->set_title(get_string('dashboard', 'local_reviewworkflow')); $PAGE->set_heading($course->fullname);
$manager = has_capability('local/reviewworkflow:manage', $context);
if (!$manager && !has_capability('local/reviewworkflow:review', $context)) {
    throw new moodle_exception('forbidden', 'local_reviewworkflow');
}
if (optional_param('action', '', PARAM_ALPHA) === 'scan') {
    require_sesskey();
    if (!$manager || !data_submitted()) { throw new moodle_exception('forbidden', 'local_reviewworkflow'); }
    \local_reviewworkflow\workflow::scan(true, $courseid);
    redirect($PAGE->url, get_string('scancomplete', 'local_reviewworkflow'));
}
$cmid = optional_param('cmid', 0, PARAM_INT); $state = optional_param('state', '', PARAM_ALPHA);
$page = max(0, optional_param('page', 0, PARAM_INT));
if (!in_array($state, ['', 'pending', 'claimed', 'completed', 'superseded'])) { $state = ''; }
$modinfo = get_fast_modinfo($course);
$activities = [0 => get_string('allassignments', 'local_reviewworkflow')];
foreach ($modinfo->get_instances_of('assign') as $info) {
    if ($info->uservisible) { $activities[$info->id] = format_string($info->name); }
}
echo $OUTPUT->header(); echo $OUTPUT->heading(get_string('dashboard', 'local_reviewworkflow'));
if ($manager) {
    echo $OUTPUT->single_button(new moodle_url($PAGE->url, ['action' => 'scan']), get_string('scannow', 'local_reviewworkflow'), 'post');
    $table = new html_table();
    $table->head = [get_string('assignment', 'local_reviewworkflow'), get_string('monitoring', 'local_reviewworkflow'), get_string('actions')];
    foreach ($activities as $aid => $name) {
        if (!$aid) { continue; }
        $cm = \local_reviewworkflow\workflow::cm($aid);
        $cfg = $DB->get_record('local_reviewworkflow_cfg', ['cmid' => $aid]);
        $links = html_writer::link(new moodle_url('/local/reviewworkflow/configure.php', ['cmid' => $aid]), get_string('configure', 'local_reviewworkflow'))
            . ' | ' . html_writer::link(new moodle_url('/local/reviewworkflow/reviewers.php', ['cmid' => $aid]), get_string('reviewers', 'local_reviewworkflow'));
        $table->data[] = [s($name), get_string($cfg && $cfg->enabled ? 'enabled' : 'disabled', 'local_reviewworkflow'), $links];
    }
    echo html_writer::table($table);
    echo html_writer::tag('p', get_string('schedulehelp', 'local_reviewworkflow'));
}
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::label(get_string('assignment', 'local_reviewworkflow'), 'filter-cmid');
echo html_writer::select($activities, 'cmid', $cmid, false, ['id' => 'filter-cmid']);
$states = ['' => get_string('allstates', 'local_reviewworkflow')];
foreach (['pending', 'claimed', 'completed', 'superseded'] as $s) { $states[$s] = get_string('state_' . $s, 'local_reviewworkflow'); }
echo html_writer::label(get_string('status', 'local_reviewworkflow'), 'filter-state');
echo html_writer::select($states, 'state', $state, false, ['id' => 'filter-state']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter', 'local_reviewworkflow'), 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');
$summary = [];
foreach (\local_reviewworkflow\workflow::visible_counts($courseid, $cmid) as $status => $count) {
    $summary[] = get_string('state_' . $status, 'local_reviewworkflow') . ': ' . $count;
}
echo html_writer::tag('p', implode(' | ', $summary));
$rows = \local_reviewworkflow\workflow::visible_requests($courseid, $cmid, $state, $page);
$table = new html_table();
$table->head = [get_string('student', 'local_reviewworkflow'), get_string('assignment', 'local_reviewworkflow'),
    get_string('status', 'local_reviewworkflow'), get_string('reviewer', 'local_reviewworkflow'),
    get_string('notifications', 'local_reviewworkflow'), get_string('review', 'local_reviewworkflow')];
foreach ($rows as $r) {
    $student = core_user::get_user($r->studentid); $reviewer = core_user::get_user($r->completedby ?: $r->claimedby);
    $notices = [];
    foreach ($DB->get_records('local_reviewworkflow_msg', ['requestid' => $r->id]) as $n) {
        $u = core_user::get_user($n->recipientid);
        $notices[] = s(($u ? fullname($u) : get_string('deleteduser', 'local_reviewworkflow')) . ': ' . get_string('notice_' . $n->state, 'local_reviewworkflow'));
    }
    $table->data[] = [s($student ? fullname($student) : get_string('deleteduser', 'local_reviewworkflow')),
        s($activities[$r->cmid] ?? ''), get_string('state_' . $r->state, 'local_reviewworkflow'),
        s($reviewer ? fullname($reviewer) : '—'), $notices ? implode('<br>', $notices) : get_string('reviewerneeded', 'local_reviewworkflow'),
        html_writer::link(new moodle_url('/local/reviewworkflow/review.php', ['id' => $r->id]), get_string('review', 'local_reviewworkflow'))];
}
echo html_writer::table($table);
if (!$rows) { echo html_writer::tag('p', get_string('noreviews', 'local_reviewworkflow')); }
if ($page) { echo html_writer::link(new moodle_url($PAGE->url, ['cmid' => $cmid, 'state' => $state, 'page' => $page - 1]), get_string('previous')); }
if (count($rows) == 50) { echo html_writer::link(new moodle_url($PAGE->url, ['cmid' => $cmid, 'state' => $state, 'page' => $page + 1]), get_string('next')); }
echo $OUTPUT->footer();
