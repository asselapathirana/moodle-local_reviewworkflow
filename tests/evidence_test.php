<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/export_fixture.php');

final class evidence_test extends \advanced_testcase {
    public function test_completion_preserves_original_bytes_after_native_files_change(): void {
        global $DB;
        $this->resetAfterTest(); $this->setAdminUser();
        [, , $teacher, , $file, $r] = export_fixture($this->getDataGenerator());
        $this->assertSame('current', evidence::files($r)['provenance']);
        $this->setUser($teacher); workflow::claim($r->id);
        workflow::save($r->id, 'acceptable', 'Examined original files', true, true);
        $r = $DB->get_record('local_reviewworkflow_req', ['id' => $r->id]);
        $this->assertGreaterThan(0, $r->evidencecaptured);
        get_file_storage()->delete_area_files($file['contextid'], $file['component'], $file['filearea'], $file['itemid']);
        get_file_storage()->create_file_from_string($file, 'New files must not replace examined evidence');
        $evidence = evidence::files($r);
        $this->assertSame('snapshot', $evidence['provenance']);
        $this->assertCount(1, $evidence['files']);
        $this->assertSame('Original synthetic evidence', reset($evidence['files'])->get_content());
        $this->assertSame('submitted', $DB->get_field('assign_submission', 'status', ['id' => $file['itemid']]));
        $this->assertEquals(0, $DB->count_records('assign_grades'));
    }

    public function test_old_changed_evidence_is_explicitly_unavailable(): void {
        $this->resetAfterTest(); $this->setAdminUser();
        [, , , , $file, $r] = export_fixture($this->getDataGenerator());
        get_file_storage()->delete_area_files($file['contextid'], $file['component'], $file['filearea'], $file['itemid']);
        get_file_storage()->create_file_from_string($file, 'Different evidence');
        $this->assertSame(['files' => [], 'provenance' => 'unavailable'], evidence::files($r));
    }

    public function test_snapshot_access_is_submission_scoped(): void {
        $this->resetAfterTest(); $this->setAdminUser(); $g = $this->getDataGenerator();
        [$course, $student, $teacher, , , $r] = export_fixture($g);
        $other = $g->create_user(); $g->enrol_user($other->id, $course->id, 'teacher');
        $this->assertTrue(evidence::can_view($r, $teacher->id));
        $this->assertFalse(evidence::can_view($r, $student->id));
        $this->assertFalse(evidence::can_view($r, $other->id));
    }

    public function test_failed_completion_does_not_leave_snapshot_files(): void {
        global $DB;
        $this->resetAfterTest(); $this->setAdminUser(); $this->preventResetByRollback();
        [, , $teacher, $cm, , $r] = export_fixture($this->getDataGenerator());
        $this->setUser($teacher); workflow::claim($r->id);
        $DB->insert_record('local_reviewworkflow_log', (object)['requestid' => $r->id,
            'actorid' => $teacher->id, 'action' => 'completed', 'timecreated' => time()]);
        $DB->execute('CREATE UNIQUE INDEX rw_evidence_failure ON {local_reviewworkflow_log} (requestid, action)');
        try {
            try { workflow::save($r->id, 'acceptable', '', true, true); $this->fail('Audit failure expected'); }
            catch (\dml_write_exception $e) {
                $this->assertEquals(0, $DB->get_field('local_reviewworkflow_req', 'evidencecaptured', ['id' => $r->id]));
                $files = get_file_storage()->get_area_files(\context_module::instance($cm->id)->id,
                    'local_reviewworkflow', 'evidence', $r->id, '', false);
                $this->assertCount(0, $files);
            }
        } finally { $DB->execute('DROP INDEX rw_evidence_failure'); }
    }
}
