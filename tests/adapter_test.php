<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

final class adapter_test extends \advanced_testcase {
    public function test_ready_files_and_evidence_versions(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $a = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'assignsubmission_file_enabled' => 1, 'assignsubmission_file_maxfiles' => 5, 'assignsubmission_file_maxsizebytes' => 0, 'submissiondrafts' => 0,
        ]);
        $cm = get_coursemodule_from_instance('assign', $a->id);
        $adapter = new \local_reviewworkflow\adapter\assignment();
        $this->assertTrue($adapter->supports($cm));
        $id = $DB->insert_record('assign_submission', (object)[
            'assignment' => $a->id, 'userid' => $student->id, 'status' => 'draft',
            'timecreated' => time(), 'timemodified' => time(), 'latest' => 1, 'attemptnumber' => 0,
        ]);
        $file = ['contextid' => \context_module::instance($cm->id)->id,
            'component' => 'assignsubmission_file', 'filearea' => 'submission_files',
            'itemid' => $id, 'filepath' => '/', 'filename' => 'paper.txt'];
        $fs = get_file_storage();
        $f = $fs->create_file_from_string($file, 'Synthetic first draft');
        $this->assertCount(0, iterator_to_array($adapter->submissions($cm)));
        $DB->set_field('assign_submission', 'status', 'submitted', ['id' => $id]);
        $ready = iterator_to_array($adapter->submissions($cm));
        $this->assertCount(1, $ready);
        $hash = $ready[0]->versionhash;
        $DB->set_field('assign_submission', 'timemodified', time()+10, ['id' => $id]);
        $this->assertSame($hash, iterator_to_array($adapter->submissions($cm))[0]->versionhash);
        $f->delete();
        $this->assertCount(0, iterator_to_array($adapter->submissions($cm)));
        $fs->create_file_from_string($file, 'Replacement evidence');
        $this->assertNotSame($hash, iterator_to_array($adapter->submissions($cm))[0]->versionhash);
    }

    public function test_rejects_team_and_group_mode_assignments(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'assignsubmission_file_enabled' => 1, 'assignsubmission_file_maxfiles' => 5, 'assignsubmission_file_maxsizebytes' => 0, 'teamsubmission' => 1,
        ]);
        $adapter = new \local_reviewworkflow\adapter\assignment();
        $this->assertFalse($adapter->supports(get_coursemodule_from_instance('assign', $a->id)));
        $a = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'assignsubmission_file_enabled' => 1, 'assignsubmission_file_maxfiles' => 5, 'assignsubmission_file_maxsizebytes' => 0, 'groupmode' => 1,
        ]);
        $this->assertFalse($adapter->supports(get_coursemodule_from_instance('assign', $a->id)));
    }
}
