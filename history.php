<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT); $id = optional_param('id', 0, PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT)); $changespage = max(0, optional_param('changespage', 0, PARAM_INT));
$cm = \local_reviewworkflow\workflow::cm($cmid); $course = get_course($cm->course);
require_login($course, false, $cm);
if (!\local_reviewworkflow\access::manager($cm)) { throw new moodle_exception('forbidden', 'local_reviewworkflow'); }
$PAGE->set_context(context_module::instance($cmid)); $PAGE->set_url('/local/reviewworkflow/history.php', ['cmid' => $cmid]);
$PAGE->set_title(get_string('importhistory', 'local_reviewworkflow')); $PAGE->set_heading($course->fullname);
echo $OUTPUT->header(); echo $OUTPUT->heading(format_string($cm->name) . ': ' . get_string('importhistory', 'local_reviewworkflow'));
if ($id) {
    $batch = $DB->get_record('local_reviewworkflow_imp', ['id' => $id, 'cmid' => $cmid], '*', MUST_EXIST);
    $actor = core_user::get_user($batch->actorid);
    echo html_writer::tag('p', s($actor ? fullname($actor) : get_string('deleteduser', 'local_reviewworkflow')) . ' | '
        . userdate($batch->timecreated) . ' | ' . get_string($batch->mode === 'replace' ? 'importreplacemode' : 'importaddmode', 'local_reviewworkflow'));
    echo html_writer::tag('p', get_string('importfilehash', 'local_reviewworkflow') . ': ' . s($batch->sourcehash));
    $table = new html_table(); $table->head = [get_string('operation', 'local_reviewworkflow'), get_string('student', 'local_reviewworkflow'), get_string('reviewer', 'local_reviewworkflow')];
    foreach ($DB->get_records('local_reviewworkflow_chg', ['importid' => $id], 'id', '*', $changespage * 100, 100) as $c) {
        $student = core_user::get_user($c->studentid); $reviewer = core_user::get_user($c->reviewerid);
        $table->data[] = [get_string('importaction_' . $c->action, 'local_reviewworkflow'),
            s($student ? fullname($student) : get_string('deleteduser', 'local_reviewworkflow')),
            s($reviewer ? fullname($reviewer) : get_string('deleteduser', 'local_reviewworkflow'))];
    }
    echo html_writer::table($table);
    $total = $DB->count_records('local_reviewworkflow_chg', ['importid' => $id]);
    echo $OUTPUT->paging_bar($total, $changespage, 100, new moodle_url($PAGE->url, ['id' => $id]), 'changespage');
}
$table = new html_table(); $table->head = [get_string('date'), get_string('importactor', 'local_reviewworkflow'),
    get_string('importmode', 'local_reviewworkflow'), get_string('import_additions', 'local_reviewworkflow'),
    get_string('import_removals', 'local_reviewworkflow'), get_string('import_claims', 'local_reviewworkflow')];
foreach (\local_reviewworkflow\importer::history($cm, $page) as $b) {
    $u = core_user::get_user($b->actorid);
    $table->data[] = [html_writer::link(new moodle_url($PAGE->url, ['id' => $b->id]), userdate($b->timecreated)),
        s($u ? fullname($u) : get_string('deleteduser', 'local_reviewworkflow')),
        get_string($b->mode === 'replace' ? 'importreplacemode' : 'importaddmode', 'local_reviewworkflow') . ' (' . get_string($b->idtype) . ')',
        $b->added, $b->removed, $b->released];
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($DB->count_records('local_reviewworkflow_imp', ['cmid' => $cmid]), $page, 20, $PAGE->url);
echo html_writer::link(new moodle_url('/local/reviewworkflow/reviewers.php', ['cmid' => $cmid]), get_string('reviewers', 'local_reviewworkflow'));
echo $OUTPUT->footer();
