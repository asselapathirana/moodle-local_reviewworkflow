<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

/** Synthetic fixture shared by evidence and export integration tests. */
function export_fixture(\testing_data_generator $g, array $courseoptions = []): array {
    global $DB;
    $course = $g->create_course($courseoptions + ['fullname' => 'Synthetic export course']);
    $student = $g->create_user(['firstname' => 'Sam', 'lastname' => 'Student']);
    $teacher = $g->create_user(['firstname' => 'Alex', 'lastname' => 'Teacher']);
    $g->enrol_user($student->id, $course->id, 'student');
    $g->enrol_user($teacher->id, $course->id, 'teacher');
    $a = $g->create_module('assign', ['course' => $course->id, 'name' => 'Synthetic file assignment',
        'assignsubmission_file_enabled' => 1, 'assignsubmission_file_maxfiles' => 5,
        'assignsubmission_file_maxsizebytes' => 0, 'submissiondrafts' => 0]);
    $cm = get_coursemodule_from_instance('assign', $a->id);
    $id = $DB->insert_record('assign_submission', (object)['assignment' => $a->id, 'userid' => $student->id,
        'status' => 'submitted', 'timecreated' => time(), 'timemodified' => time(), 'latest' => 1, 'attemptnumber' => 0]);
    $file = ['contextid' => \context_module::instance($cm->id)->id, 'component' => 'assignsubmission_file',
        'filearea' => 'submission_files', 'itemid' => $id, 'filepath' => '/', 'filename' => 'paper.txt'];
    get_file_storage()->create_file_from_string($file, 'Original synthetic evidence');
    workflow::configure($cm, 'include', 'Read the files', 'I examined the submitted files.');
    workflow::map($cm, [$student->id], [$teacher->id]); workflow::scan(false, (int)$course->id);
    $request = $DB->get_record('local_reviewworkflow_req', ['cmid' => $cm->id], '*', MUST_EXIST);
    return [$course, $student, $teacher, $cm, $file, $request];
}
