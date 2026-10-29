<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
$id = required_param('id', PARAM_INT);
$record = $DB->get_record('local_reviewworkflow_req', ['id' => $id], '*', MUST_EXIST);
$cm = \local_reviewworkflow\workflow::cm($record->cmid); $course = get_course($cm->course);
require_login($course, false, $cm);
$r = \local_reviewworkflow\workflow::get_request($id);
$PAGE->set_context(context_module::instance($cm->id));
$PAGE->set_url('/local/reviewworkflow/review.php', ['id' => $id]);
$PAGE->set_title(get_string('review', 'local_reviewworkflow')); $PAGE->set_heading($course->fullname);
$action = optional_param('action', '', PARAM_ALPHA);
if ($action) {
    require_sesskey();
    if (!data_submitted()) { throw new moodle_exception('invalidformdata'); }
    if ($action === 'claim') { \local_reviewworkflow\workflow::claim($id); }
    else if ($action === 'release') { \local_reviewworkflow\workflow::release($id); }
    else { throw new moodle_exception('invalidformdata'); }
    redirect($PAGE->url);
}
$cfg = $DB->get_record('local_reviewworkflow_cfg', ['cmid' => $cm->id], '*', MUST_EXIST);
$back = new moodle_url('/local/reviewworkflow/index.php', ['courseid' => $course->id]);
$form = null;
if ($r->state === 'claimed' && $r->claimedby == $USER->id) {
    $form = new \local_reviewworkflow\form\review(null, ['certification' => $cfg->certification]);
    $form->set_data(['id' => $id, 'decision' => $r->decision, 'comments' => $r->comments]);
    if ($form->is_cancelled()) { redirect($back); }
    if ($data = $form->get_data()) {
        \local_reviewworkflow\workflow::save($id, $data->decision, $data->comments,
            !empty($data->complete), !empty($data->certified), $data->certificationhash);
        redirect($PAGE->url, get_string('saved', 'local_reviewworkflow'));
    }
}
echo $OUTPUT->header();
$student = core_user::get_user($r->studentid);
echo $OUTPUT->heading(format_string($cm->name) . ': ' . ($student ? fullname($student) : get_string('deleteduser', 'local_reviewworkflow')));
echo html_writer::tag('p', get_string('status', 'local_reviewworkflow') . ': ' . get_string('state_' . $r->state, 'local_reviewworkflow'));
echo html_writer::tag('p', get_string('attempt', 'local_reviewworkflow', $r->attempt + 1));
$current = \local_reviewworkflow\workflow::current($r);
$evidence = \local_reviewworkflow\evidence::files($r);
if ($evidence['files'] && \local_reviewworkflow\evidence::can_view($r)) {
    $files = $evidence['files'];
    $links = [];
    foreach ($files as $f) {
        $url = moodle_url::make_pluginfile_url($f->get_contextid(), $f->get_component(), $f->get_filearea(),
            $f->get_itemid(), $f->get_filepath(), $f->get_filename(), true);
        $links[] = html_writer::link($url, s($f->get_filename()));
    }
    echo $OUTPUT->heading(get_string('files', 'local_reviewworkflow'), 3);
    echo html_writer::tag('p', get_string('evidence_' . $evidence['provenance'], 'local_reviewworkflow'));
    echo html_writer::alist($links);
} else {
    echo $OUTPUT->notification(get_string('historicalevidence', 'local_reviewworkflow'), 'warning');
}
echo html_writer::tag('div', format_text($cfg->instructions, FORMAT_PLAIN));
if ($r->state === 'completed') {
    $author = core_user::get_user($r->completedby);
    echo html_writer::tag('p', get_string('completedby', 'local_reviewworkflow', $author ? fullname($author) : get_string('deleteduser', 'local_reviewworkflow'))
        . ' — ' . userdate($r->timecompleted));
    echo html_writer::tag('p', get_string('decision', 'local_reviewworkflow') . ': ' . ($r->decision ? get_string($r->decision, 'local_reviewworkflow') : ''));
    echo format_text($r->comments, FORMAT_PLAIN);
    echo html_writer::tag('p', s($r->certification));
} else if ($form && $current) { $form->display(); }
else if ($r->state === 'pending' && $current && \local_reviewworkflow\access::mapped($cm, $r->studentid, $USER->id)) {
    echo $OUTPUT->single_button(new moodle_url($PAGE->url, ['action' => 'claim']), get_string('startreview', 'local_reviewworkflow'), 'post');
} else if ($r->state === 'claimed') {
    $owner = core_user::get_user($r->claimedby);
    echo html_writer::tag('p', get_string('claimedby', 'local_reviewworkflow', $owner ? fullname($owner) : get_string('deleteduser', 'local_reviewworkflow')));
}
if ($r->state === 'claimed' && \local_reviewworkflow\access::manager($cm)) {
    echo $OUTPUT->single_button(new moodle_url($PAGE->url, ['action' => 'release']), get_string('release', 'local_reviewworkflow'), 'post');
}
echo html_writer::link($back, get_string('dashboard', 'local_reviewworkflow'));
echo $OUTPUT->footer();
