<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT);
$cm = \local_reviewworkflow\workflow::cm($cmid); $course = get_course($cm->course);
require_login($course, false, $cm);
if (!\local_reviewworkflow\access::manager($cm)) { throw new moodle_exception('forbidden', 'local_reviewworkflow'); }
$PAGE->set_context(context_module::instance($cmid));
$PAGE->set_url('/local/reviewworkflow/import.php', ['cmid' => $cmid]);
$PAGE->set_title(get_string('importreviewers', 'local_reviewworkflow')); $PAGE->set_heading($course->fullname);
$back = new moodle_url('/local/reviewworkflow/reviewers.php', ['cmid' => $cmid]);
$token = optional_param('token', '', PARAM_ALPHANUM);
$previews = $SESSION->local_reviewworkflow_previews ?? [];
foreach ($previews as $key => $value) {
    if (($value['created'] ?? 0) < time() - 1800) { unset($previews[$key]); }
}
$SESSION->local_reviewworkflow_previews = $previews;
$preview = null; $confirmation = null; $upload = null;
if ($token) {
    $preview = $previews[$token] ?? null;
    if (!$preview || $preview['actorid'] != $USER->id || $preview['cmid'] != $cmid) {
        throw new moodle_exception('importexpired', 'local_reviewworkflow');
    }
    $confirmation = new \local_reviewworkflow\form\import_confirm(null, ['claims' => $preview['claims']]);
    $confirmation->set_data(['cmid' => $cmid, 'token' => $token]);
    if ($confirmation->is_cancelled()) { unset($SESSION->local_reviewworkflow_previews[$token]); redirect($back); }
    if ($data = $confirmation->get_data()) {
        $id = \local_reviewworkflow\importer::apply($cm, $preview, !empty($data->releaseclaims));
        unset($SESSION->local_reviewworkflow_previews[$token]);
        redirect(new moodle_url('/local/reviewworkflow/history.php', ['cmid' => $cmid, 'id' => $id]),
            get_string('importapplied', 'local_reviewworkflow'));
    }
} else {
    $upload = new \local_reviewworkflow\form\import(); $upload->set_data(['cmid' => $cmid]);
    if ($upload->is_cancelled()) { redirect($back); }
    if ($data = $upload->get_data()) {
        $preview = \local_reviewworkflow\importer::preview($cm, $upload->get_file_content('csvfile'), $data->idtype, $data->mode);
        if (!$preview['errors']) {
            $previews[$preview['nonce']] = $preview;
            $SESSION->local_reviewworkflow_previews = array_slice($previews, -5, null, true);
            redirect(new moodle_url($PAGE->url, ['token' => $preview['nonce']]));
        }
    }
}
echo $OUTPUT->header(); echo $OUTPUT->heading(format_string($cm->name) . ': ' . get_string('importreviewers', 'local_reviewworkflow'));
echo html_writer::tag('p', get_string('importhelp', 'local_reviewworkflow'));
if ($preview) {
    foreach ($preview['errors'] as $error) { echo $OUTPUT->notification(s($error), 'error'); }
    foreach ($preview['warnings'] as $warning) { echo $OUTPUT->notification(s($warning), 'warning'); }
    echo $OUTPUT->heading(get_string('importpreview', 'local_reviewworkflow'), 3);
    echo html_writer::tag('p', get_string('importidentifier', 'local_reviewworkflow') . ': '
        . get_string($preview['idtype']) . ' | ' . get_string($preview['mode'] === 'replace' ? 'importreplacemode' : 'importaddmode', 'local_reviewworkflow'));
    if ($preview['mode'] === 'replace') { echo $OUTPUT->notification(get_string('importreplacewarning', 'local_reviewworkflow'), 'warning'); }
    $table = new html_table(); $table->head = [get_string('importrow', 'local_reviewworkflow'), get_string('student', 'local_reviewworkflow'), get_string('reviewers', 'local_reviewworkflow')];
    foreach ($preview['rows'] as $row) {
        $names = array_map(fn($t) => s($t['name'] . ' (' . $t['identifier'] . ')'), $row['teachers']);
        $s = $row['student'];
        $table->data[] = [$row['line'], $s ? s($s['name'] . ' (' . $s['identifier'] . ')') : '—', implode('<br>', $names) ?: '—'];
    }
    echo html_writer::table($table);
    foreach (['additions', 'removals', 'claims'] as $kind) {
        echo $OUTPUT->heading(get_string('import_' . $kind, 'local_reviewworkflow') . ': ' . count($preview[$kind]), 3);
        $table = new html_table(); $table->head = [get_string('student', 'local_reviewworkflow'), get_string('reviewer', 'local_reviewworkflow')];
        foreach ($preview[$kind] as $pair) {
            $s = core_user::get_user($pair['studentid']); $t = core_user::get_user($pair['reviewerid']);
            $table->data[] = [s($s ? fullname($s) : get_string('deleteduser', 'local_reviewworkflow')),
                s($t ? fullname($t) : get_string('deleteduser', 'local_reviewworkflow'))];
        }
        echo html_writer::table($table);
    }
}
if ($confirmation) { $confirmation->display(); }
if ($upload) { $upload->display(); }
echo html_writer::link($back, get_string('reviewers', 'local_reviewworkflow'));
echo $OUTPUT->footer();
