<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

final class workflow_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB;
        $this->resetAfterTest();
        $g = $this->getDataGenerator();
        $course = $g->create_course();
        $student = $g->create_user(); $t1 = $g->create_user(); $t2 = $g->create_user();
        $g->enrol_user($student->id, $course->id, 'student');
        $g->enrol_user($t1->id, $course->id, 'teacher');
        $g->enrol_user($t2->id, $course->id, 'teacher');
        $a = $g->create_module('assign', [
            'course' => $course->id, 'assignsubmission_file_enabled' => 1, 'assignsubmission_file_maxfiles' => 5, 'assignsubmission_file_maxsizebytes' => 0, 'submissiondrafts' => 0,
        ]);
        $cm = get_coursemodule_from_instance('assign', $a->id);
        $id = $DB->insert_record('assign_submission', (object)[
            'assignment' => $a->id, 'userid' => $student->id, 'status' => 'submitted',
            'timecreated' => time(), 'timemodified' => time(), 'latest' => 1, 'attemptnumber' => 0,
        ]);
        $file = ['contextid' => \context_module::instance($cm->id)->id,
            'component' => 'assignsubmission_file', 'filearea' => 'submission_files',
            'itemid' => $id, 'filepath' => '/', 'filename' => 'paper.txt'];
        get_file_storage()->create_file_from_string($file, 'Synthetic assessment evidence');
        $this->setAdminUser();
        return [$course, $student, $t1, $t2, $cm, $file];
    }

    public function test_one_notice_per_reviewer_after_repeated_scans(): void {
        global $DB;
        [$course, $student, $t1, $t2, $cm] = $this->fixture();
        workflow::configure($cm, 'include', 'Examine the files.', 'I have examined the files.');
        workflow::map($cm, [$student->id], [$t1->id, $t2->id]);
        $sink = $this->redirectMessages();
        for ($i = 0; $i < 25; $i++) { workflow::scan(); }
        $this->assertCount(2, $sink->get_messages());
        $this->assertStringContainsString(fullname($student), $sink->get_messages()[0]->fullmessage);
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_req'));
        $this->assertEquals(2, $DB->count_records('local_reviewworkflow_msg', ['state' => 'accepted']));
        $this->assertEquals('submitted', $DB->get_field('assign_submission', 'status', ['assignment' => $cm->instance]));
        $this->assertEquals(0, $DB->count_records('assign_grades'));
    }

    public function test_assignment_templates_are_saved_and_personalised_per_teacher(): void {
        global $DB;
        [, $student, $t1, $t2, $cm] = $this->fixture();
        $templates = email_template::defaults();
        $templates['requestsubject'] = 'Please review {student_name}';
        $templates['requestbody'] = 'Hello {reviewer_firstname}, please check {assignment_name}.';
        workflow::configure($cm, 'include', '', 'Certification', true, $templates);
        $this->assertSame($templates['requestbody'], $DB->get_field('local_reviewworkflow_cfg', 'requestbody', ['cmid' => $cm->id]));
        workflow::map($cm, [$student->id], [$t1->id, $t2->id]);
        $sink = $this->redirectMessages(); workflow::scan();
        $messages = $sink->get_messages();
        $this->assertCount(2, $messages);
        $this->assertSame('Please review ' . fullname($student), $messages[0]->subject);
        foreach ($messages as $message) {
            $teacher = $message->useridto == $t1->id ? $t1 : $t2;
            $this->assertStringContainsString('Hello ' . $teacher->firstname, $message->fullmessage);
            $this->assertStringContainsString('[Optional:', $message->fullmessage);
        }
        // Old callers changing unrelated settings must retain email customisations.
        workflow::configure($cm, 'include', 'New instructions', 'Certification');
        $this->assertSame($templates['requestbody'], $DB->get_field('local_reviewworkflow_cfg', 'requestbody', ['cmid' => $cm->id]));
    }

    public function test_invalid_templates_do_not_change_configuration(): void {
        global $DB;
        [, , , , $cm] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        $before = $DB->get_record('local_reviewworkflow_cfg', ['cmid' => $cm->id]);
        try {
            workflow::configure($cm, 'include', 'Changed', 'Changed', true, ['requestbody' => '{not_a_field}']);
            $this->fail('Invalid template should reject the entire configuration save');
        } catch (\moodle_exception $e) { $this->assertSame('invalidconfig', $e->errorcode); }
        $this->assertEquals($before, $DB->get_record('local_reviewworkflow_cfg', ['cmid' => $cm->id]));
    }

    public function test_completion_notifies_all_assigned_teachers_once_with_reviewer_name(): void {
        global $DB;
        [, $student, $t1, $t2, $cm] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification', true,
            ['completebody' => 'Dear {reviewer_firstname}, {completed_by} reviewed {student_name}.']);
        workflow::map($cm, [$student->id], [$t1->id, $t2->id]);
        $sink = $this->redirectMessages(); workflow::scan(); $sink->clear();
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($t1); workflow::claim($r->id);
        workflow::save($r->id, 'acceptable', 'Private assessment comments', false, false);
        $this->assertEquals(0, $DB->count_records('local_reviewworkflow_msg', ['kind' => 'completion']));
        workflow::save($r->id, 'acceptable', 'Private assessment comments', true, true);
        $this->assertEquals(2, $DB->count_records('local_reviewworkflow_msg', ['kind' => 'completion', 'state' => 'queued']));
        for ($i = 0; $i < 25; $i++) { workflow::scan(); }
        $messages = $sink->get_messages();
        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $teacher = $message->useridto == $t1->id ? $t1 : $t2;
            $this->assertStringContainsString('Dear ' . $teacher->firstname, $message->fullmessage);
            $this->assertStringContainsString(fullname($t1) . ' reviewed ' . fullname($student), $message->fullmessage);
            $this->assertStringNotContainsString('Private assessment comments', $message->fullmessage);
            $this->assertSame('review_completed', $message->eventtype);
        }
        $this->assertEquals(2, $DB->count_records('local_reviewworkflow_msg', ['kind' => 'completion', 'state' => 'accepted']));
        try {
            workflow::save($r->id, 'acceptable', '', true, true);
            $this->fail('Completed review cannot complete twice');
        } catch (\moodle_exception $e) { $this->assertSame('claimed', $e->errorcode); }
        $this->assertEquals(2, $DB->count_records('local_reviewworkflow_msg', ['kind' => 'completion']));
    }

    public function test_completion_rechecks_recipients_and_retains_old_attempt_notice(): void {
        global $DB;
        [, $student, $t1, $t2, $cm, $file] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$t1->id, $t2->id]); workflow::scan(false);
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($t1); workflow::claim($r->id);
        workflow::save($r->id, 'acceptable', '', true, true);
        $this->setAdminUser(); workflow::map($cm, [$student->id], [$t2->id], true);
        get_file_storage()->delete_area_files($file['contextid'], $file['component'], $file['filearea'], $file['itemid']);
        get_file_storage()->create_file_from_string($file, 'New evidence after completion');
        $sink = $this->redirectMessages(); workflow::deliver();
        $this->assertCount(1, $sink->get_messages());
        $this->assertEquals($t1->id, $sink->get_messages()[0]->useridto);
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_msg', ['kind' => 'completion', 'state' => 'skipped']));
        workflow::scan();
        $this->assertCount(2, $sink->get_messages()); // A separate initial notice for the new version.
    }

    public function test_completion_uncertainty_is_not_retried_or_backfilled(): void {
        global $DB;
        [, $student, $t1, , $cm] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$t1->id]); workflow::scan(false);
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($t1); workflow::claim($r->id);
        workflow::save($r->id, 'acceptable', '', true, true);
        workflow::deliver(function($message) { return false; });
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_msg', ['kind' => 'completion', 'state' => 'uncertain']));
        $sink = $this->redirectMessages(); workflow::scan();
        $this->assertCount(0, $sink->get_messages());
        // Simulate a pre-upgrade completion: scanner must not backfill notices.
        $DB->delete_records('local_reviewworkflow_msg', ['kind' => 'completion']);
        workflow::scan();
        $this->assertEquals(0, $DB->count_records('local_reviewworkflow_msg', ['kind' => 'completion']));
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_failed_completion_audit_rolls_back_review_and_notification_queue(): void {
        global $DB;
        [, $student, $t1, , $cm] = $this->fixture();
        $this->preventResetByRollback();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$t1->id]); workflow::scan(false);
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($t1); workflow::claim($r->id);
        $DB->insert_record('local_reviewworkflow_log', (object)[
            'requestid' => $r->id, 'actorid' => $t1->id, 'action' => 'completed', 'timecreated' => time()]);
        // Inject a real persistence failure in the isolated test DB only.
        $DB->execute('CREATE UNIQUE INDEX rw_test_audit_failure ON {local_reviewworkflow_log} (requestid, action)');
        try {
            try {
                workflow::save($r->id, 'acceptable', '', true, true);
                $this->fail('Expected an injected audit write failure');
            } catch (\dml_write_exception $e) {
                $this->assertSame('claimed', $DB->get_field('local_reviewworkflow_req', 'state', ['id' => $r->id]));
                $this->assertEquals(0, $DB->count_records('local_reviewworkflow_msg', ['kind' => 'completion']));
            }
        } finally { $DB->execute('DROP INDEX rw_test_audit_failure'); }
    }

    public function test_template_upgrade_preserves_reviews_notifications_and_custom_schedule(): void {
        global $DB, $CFG;
        [, $student, $t1, , $cm] = $this->fixture();
        $this->preventResetByRollback();
        workflow::configure($cm, 'include', 'Instructions', 'Certification');
        workflow::map($cm, [$student->id], [$t1->id]); workflow::scan(false);
        $DB->set_field('local_reviewworkflow_req', 'state', 'completed', []);
        $before = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $notice = $DB->get_record('local_reviewworkflow_msg', [], '*', MUST_EXIST);
        $DB->set_field('task_scheduled', 'minute', '*', ['component' => 'local_reviewworkflow', 'classname' => '\\local_reviewworkflow\\task\\scan']);
        $DB->set_field('task_scheduled', 'customised', 1, ['component' => 'local_reviewworkflow', 'classname' => '\\local_reviewworkflow\\task\\scan']);
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/reviewworkflow/db/upgrade.php');
        $table = new \xmldb_table('local_reviewworkflow_cfg');
        foreach (email_template::FIELDS as $field) {
            $DB->get_manager()->drop_field($table, new \xmldb_field($field));
        }
        set_config('version', 2026102804, 'local_reviewworkflow');
        $this->assertTrue(xmldb_local_reviewworkflow_upgrade(2026102804));
        $cfg = $DB->get_record('local_reviewworkflow_cfg', ['cmid' => $cm->id]);
        foreach (email_template::FIELDS as $field) { $this->assertNull($cfg->$field); }
        $this->assertSame('Instructions', $cfg->instructions);
        $this->assertSame('Certification', $cfg->certification);
        $this->assertEquals($before, $DB->get_record('local_reviewworkflow_req', ['id' => $before->id]));
        $this->assertEquals($notice, $DB->get_record('local_reviewworkflow_msg', ['id' => $notice->id]));
        $this->assertSame('*', $DB->get_field('task_scheduled', 'minute', ['component' => 'local_reviewworkflow', 'classname' => '\\local_reviewworkflow\\task\\scan']));
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_map'));
        $sink = $this->redirectMessages(); workflow::scan();
        $this->assertEquals(0, $DB->count_records('local_reviewworkflow_msg', ['kind' => 'completion']));
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_backlog_exclusion_and_changed_files(): void {
        global $DB;
        [, $student, $t1, , $cm, $file] = $this->fixture();
        workflow::configure($cm, 'exclude', '', 'Checked files');
        workflow::map($cm, [$student->id], [$t1->id]);
        $sink = $this->redirectMessages(); workflow::scan();
        $this->assertCount(0, $sink->get_messages());
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_req', ['state' => 'superseded']));
        get_file_storage()->delete_area_files($file['contextid'], $file['component'], $file['filearea'], $file['itemid']);
        get_file_storage()->create_file_from_string($file, 'Changed file evidence');
        workflow::scan();
        $this->assertCount(1, $sink->get_messages());
        $this->assertEquals(2, $DB->count_records('local_reviewworkflow_req'));
    }

    public function test_shared_claim_completion_and_frozen_certification(): void {
        global $DB;
        [, $student, $t1, $t2, $cm] = $this->fixture();
        workflow::configure($cm, 'include', 'Read all files', 'Original certification');
        workflow::map($cm, [$student->id], [$t1->id, $t2->id]);
        workflow::scan(false);
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($t1); workflow::claim($r->id);
        $this->setUser($t2);
        try { workflow::claim($r->id); $this->fail('Second teacher must not steal claim'); }
        catch (\moodle_exception $e) { $this->assertSame('claimed', $e->errorcode); }
        $this->setUser($t1);
        try { workflow::save($r->id, 'acceptable', '', true, false); $this->fail('Certification is mandatory'); }
        catch (\moodle_exception $e) { $this->assertSame('certifyrequired', $e->errorcode); }
        workflow::save($r->id, 'acceptable', 'Reviewed synthetic evidence', true, true);
        $this->setUser($t2);
        $view = workflow::get_request($r->id);
        $this->assertSame('completed', $view->state);
        $this->assertEquals($t1->id, $view->completedby);
        $this->assertSame('Original certification', $view->certification);
        $this->setAdminUser(); workflow::configure($cm, 'include', 'New instructions', 'Changed certification');
        $this->assertSame('Original certification', workflow::get_request($r->id)->certification);
        $this->assertCount(1, workflow::visible_requests($cm->course));
        $this->setUser($student);
        $this->expectException(\moodle_exception::class); workflow::get_request($r->id);
    }

    public function test_uncertain_delivery_is_not_retried_and_completed_requests_are_skipped(): void {
        global $DB;
        [, $student, $t1, , $cm] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$t1->id]); workflow::scan(false);
        workflow::deliver(function($message) { throw new \RuntimeException('Uncertain transport result'); });
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_msg', ['state' => 'uncertain']));
        $sink = $this->redirectMessages(); workflow::scan();
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_access_and_dashboard_hide_unmapped_requests(): void {
        global $DB;
        [$course, $student, $t1, $t2, $cm] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$t1->id]); workflow::scan(false);
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($t1); $this->assertCount(1, workflow::visible_requests($course->id));
        $this->setUser($t2); $this->assertCount(0, workflow::visible_requests($course->id));
        $this->expectException(\moodle_exception::class); workflow::claim($r->id);
    }

    public function test_revoked_mapping_suppresses_queued_notice(): void {
        global $DB;
        [, $student, $t1, , $cm] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$t1->id]); workflow::scan(false);
        workflow::map($cm, [$student->id], [$t1->id], true);
        $sink = $this->redirectMessages(); workflow::deliver();
        $this->assertCount(0, $sink->get_messages());
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_msg', ['state' => 'skipped']));
    }

    public function test_changed_certification_cannot_be_silently_confirmed(): void {
        global $DB;
        [, $student, $t1, , $cm] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Original wording');
        workflow::map($cm, [$student->id], [$t1->id]); workflow::scan(false);
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($t1); workflow::claim($r->id);
        $this->setAdminUser(); workflow::configure($cm, 'include', '', 'Changed wording');
        $this->setUser($t1);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('certificationchanged', 'local_reviewworkflow'));
        workflow::save($r->id, 'acceptable', '', true, true, hash('sha256', 'Original wording'));
    }

    public function test_evidence_changes_block_completion_and_release_is_audited(): void {
        global $DB;
        [, $student, $t1, , $cm, $file] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$t1->id]); workflow::scan(false);
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($t1); workflow::claim($r->id);
        $this->setAdminUser(); workflow::release($r->id);
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_log', ['action' => 'released']));
        $this->setUser($t1); workflow::claim($r->id);
        get_file_storage()->delete_area_files($file['contextid'], $file['component'], $file['filearea'], $file['itemid']);
        $this->expectException(\moodle_exception::class);
        workflow::save($r->id, 'acceptable', '', true, true);
    }

    public function test_completed_review_suppresses_pending_mail(): void {
        global $DB;
        [, $student, $t1, $t2, $cm] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$t1->id, $t2->id]); workflow::scan(false);
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($t1); workflow::claim($r->id);
        workflow::save($r->id, 'acceptable', '', true, true);
        $sink = $this->redirectMessages(); workflow::deliver();
        $this->assertCount(2, $sink->get_messages());
        foreach ($sink->get_messages() as $message) { $this->assertSame('review_completed', $message->eventtype); }
        $this->assertEquals(2, $DB->count_records('local_reviewworkflow_msg', ['kind' => 'initial', 'state' => 'skipped']));
    }

    public function test_manual_scan_is_confined_to_one_course(): void {
        global $DB;
        [$course1, $student1, $teacher1, , $cm1] = $this->fixture();
        workflow::configure($cm1, 'include', '', 'Certification');
        workflow::map($cm1, [$student1->id], [$teacher1->id]);
        [, $student2, $teacher2, , $cm2] = $this->fixture();
        workflow::configure($cm2, 'include', '', 'Certification');
        workflow::map($cm2, [$student2->id], [$teacher2->id]);
        workflow::scan(false, (int)$course1->id);
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_req'));
        $this->assertEquals(0, $DB->count_records('local_reviewworkflow_req', ['cmid' => $cm2->id]));
    }

    public function test_group_mode_change_revokes_existing_mapping_access(): void {
        global $DB;
        [, $student, $t1, , $cm] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$t1->id]); workflow::scan(false);
        $DB->set_field('course_modules', 'groupmode', 1, ['id' => $cm->id]);
        rebuild_course_cache($cm->course, true);
        $fresh = workflow::cm($cm->id);
        $this->assertFalse(access::mapped($fresh, $student->id, $t1->id));
    }

    public function test_existing_grade_and_feedback_are_preserved(): void {
        global $DB;
        [, $student, $teacher, , $cm] = $this->fixture();
        $gradeid = $DB->insert_record('assign_grades', (object)[
            'assignment' => $cm->instance, 'userid' => $student->id,
            'timecreated' => time(), 'timemodified' => time(),
            'grader' => $teacher->id, 'grade' => 73.5, 'attemptnumber' => 0,
        ]);
        $feedbackid = $DB->insert_record('assignfeedback_comments', (object)[
            'assignment' => $cm->instance, 'grade' => $gradeid,
            'commenttext' => 'Existing teacher feedback must stay intact.', 'commentformat' => FORMAT_PLAIN,
        ]);
        $grade = $DB->get_record('assign_grades', ['id' => $gradeid]);
        $feedback = $DB->get_record('assignfeedback_comments', ['id' => $feedbackid]);
        $submission = $DB->get_record('assign_submission', ['assignment' => $cm->instance]);
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$teacher->id]); workflow::scan(false);
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($teacher); workflow::claim($r->id);
        workflow::save($r->id, 'concerns', 'Separate review comments', true, true);
        $this->assertEquals($grade, $DB->get_record('assign_grades', ['id' => $gradeid]));
        $this->assertEquals($feedback, $DB->get_record('assignfeedback_comments', ['id' => $feedbackid]));
        $this->assertEquals($submission, $DB->get_record('assign_submission', ['assignment' => $cm->instance]));
    }

    public function test_dashboard_counts_respect_visibility_and_completion(): void {
        global $DB;
        [$course, $student, $teacher, $unmapped, $cm] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$teacher->id]); workflow::scan(false);
        $this->assertSame(1, workflow::visible_counts((int)$course->id)['pending']);
        $r = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($teacher); workflow::claim($r->id);
        workflow::save($r->id, 'acceptable', '', true, true);
        $this->assertSame(1, workflow::visible_counts((int)$course->id)['completed']);
        $this->assertSame(0, workflow::visible_counts((int)$course->id)['pending']);
        $this->setUser($unmapped);
        $this->assertSame(0, array_sum(workflow::visible_counts((int)$course->id)));
    }

    public function test_new_attempt_preserves_completed_review(): void {
        global $DB;
        [, $student, $teacher, , $cm, $file] = $this->fixture();
        workflow::configure($cm, 'include', '', 'Certification');
        workflow::map($cm, [$student->id], [$teacher->id]); workflow::scan(false);
        $first = $DB->get_record('local_reviewworkflow_req', [], '*', MUST_EXIST);
        $this->setUser($teacher); workflow::claim($first->id);
        workflow::save($first->id, 'acceptable', 'Original completed review', true, true);
        $DB->set_field('assign_submission', 'latest', 0, ['id' => $file['itemid']]);
        $file['itemid'] = $DB->insert_record('assign_submission', (object)[
            'assignment' => $cm->instance, 'userid' => $student->id, 'status' => 'submitted',
            'timecreated' => time(), 'timemodified' => time(), 'latest' => 1, 'attemptnumber' => 1,
        ]);
        get_file_storage()->create_file_from_string($file, 'Second attempt evidence');
        workflow::scan(false);
        $this->assertEquals(2, $DB->count_records('local_reviewworkflow_req'));
        $this->assertSame('completed', $DB->get_field('local_reviewworkflow_req', 'state', ['id' => $first->id]));
        $this->assertSame('Original completed review', $DB->get_field('local_reviewworkflow_req', 'comments', ['id' => $first->id]));
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_req', ['state' => 'pending', 'attempt' => 1]));
    }
}
