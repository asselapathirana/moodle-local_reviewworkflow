<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

final class importer_test extends \advanced_testcase {
    private function fixture(): array {
        $this->resetAfterTest(); $g = $this->getDataGenerator();
        $c = $g->create_course();
        $s1 = $g->create_user(['username' => 'import.student1', 'email' => 'import.student1@example.test']);
        $s2 = $g->create_user(['username' => 'import.student2', 'email' => 'import.student2@example.test']);
        $t1 = $g->create_user(['username' => 'import.teacher1', 'email' => 'import.teacher1@example.test']);
        $t2 = $g->create_user(['username' => 'import.teacher2', 'email' => 'import.teacher2@example.test']);
        foreach ([$s1, $s2] as $u) { $g->enrol_user($u->id, $c->id, 'student'); }
        foreach ([$t1, $t2] as $u) { $g->enrol_user($u->id, $c->id, 'teacher'); }
        $a = $g->create_module('assign', ['course' => $c->id, 'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 5, 'assignsubmission_file_maxsizebytes' => 0]);
        $cm = workflow::cm(get_coursemodule_from_instance('assign', $a->id)->id);
        $this->setAdminUser();
        workflow::map($cm, [$s1->id], [$t1->id]);
        return [$cm, $s1, $s2, $t1, $t2];
    }

    public function test_preview_add_and_multiple_teachers_without_writes(): void {
        global $DB;
        [$cm, $s1, $s2, $t1, $t2] = $this->fixture();
        $csv = "\xEF\xBB\xBFstudent_email,teacher1_email,teacher2_email,teacher3_email\r\n"
            . "$s1->email,$t1->email,$t2->email,$t2->email\r\n$s2->email,$t1->email,,\r\n";
        $p = importer::preview($cm, $csv, 'email', 'add');
        $this->assertEmpty($p['errors']); $this->assertCount(2, $p['additions']);
        $this->assertNotEmpty($p['warnings']); $this->assertCount(2, $p['rows'][0]['teachers']);
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_map'));
        $this->assertEquals(0, $DB->count_records('local_reviewworkflow_imp'));
        $id = importer::apply($cm, $p);
        $this->assertEquals(3, $DB->count_records('local_reviewworkflow_map'));
        $this->assertSame($id, importer::apply($cm, $p));
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_imp'));
        $this->assertEquals(2, $DB->count_records('local_reviewworkflow_chg', ['action' => 'added']));
    }

    public function test_username_mode_and_round_trip_export(): void {
        global $DB;
        [$cm, $s1, $s2, $t1, $t2] = $this->fixture();
        $csv = "student,teacher1,teacher2\n$s1->username,$t1->username,$t2->username\n$s2->username,$t2->username,\n";
        importer::apply($cm, importer::preview($cm, $csv, 'username'));
        foreach (['email', 'username'] as $kind) {
            $export = importer::export($cm, $kind);
            $p = importer::preview($cm, $export, $kind, 'replace');
            $this->assertEmpty($p['errors']); $this->assertEmpty($p['additions']); $this->assertEmpty($p['removals']);
        }
        $this->assertEquals(3, $DB->count_records('local_reviewworkflow_map'));
    }

    public function test_invalid_identifiers_ambiguity_and_roles_block_every_change(): void {
        global $DB;
        [$cm, $s1, $s2, $t1, $t2] = $this->fixture();
        $this->getDataGenerator()->create_user(['email' => $t2->email]);
        $csv = "student_email,teacher1_email\n$s1->email,missing@example.test\n$s2->email,$t2->email\n";
        $p = importer::preview($cm, $csv, 'email'); $this->assertCount(2, $p['errors']);
        try { importer::apply($cm, $p); $this->fail('Invalid import must be atomic'); }
        catch (\moodle_exception $e) { $this->assertSame('importinvalid', $e->errorcode); }
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_map'));
        $outsider = $this->getDataGenerator()->create_user();
        foreach ([$outsider->email, $s2->email] as $badteacher) {
            $this->assertNotEmpty(importer::preview($cm, "student,teacher1\n$s1->email,$badteacher\n", 'email')['errors']);
        }
        $this->assertNotEmpty(importer::preview($cm, "student,teacher1\n$outsider->email,$t1->email\n", 'email')['errors']);
    }

    public function test_malformed_duplicate_students_empty_and_mismatched_headers(): void {
        [$cm, $s1, , $t1] = $this->fixture();
        foreach (["student_email,teacher1_email\n", "student,teacher1\n$s1->email,$t1->email\n$s1->email,$t1->email\n",
            "student_username,teacher1_username\n$s1->email,$t1->email\n", "student,teacher1\n$s1->email,\"unterminated",
            "student,teacher1\n$s1->email,$t1->email,extra\n"] as $csv) {
            $this->assertNotEmpty(importer::preview($cm, $csv, 'email', 'replace')['errors']);
        }
    }

    public function test_replace_scope_and_stale_preview_rejection(): void {
        global $DB;
        [$cm, $s1, $s2, $t1, $t2] = $this->fixture();
        workflow::map($cm, [$s2->id], [$t2->id]);
        $p = importer::preview($cm, "student,teacher1\n$s1->email,$t2->email\n", 'email', 'replace');
        $this->assertCount(2, $p['removals']); $this->assertCount(1, $p['additions']);
        workflow::map($cm, [$s2->id], [$t1->id]);
        try { importer::apply($cm, $p); $this->fail('Stale preview must fail'); }
        catch (\moodle_exception $e) { $this->assertSame('importstale', $e->errorcode); }
        $this->assertEquals(3, $DB->count_records('local_reviewworkflow_map'));
        $p = importer::preview($cm, "student,teacher1\n$s1->email,$t2->email\n", 'email', 'replace');
        importer::apply($cm, $p);
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_map'));
        $this->assertTrue($DB->record_exists('local_reviewworkflow_map', ['studentid' => $s1->id, 'reviewerid' => $t2->id]));
    }

    public function test_claim_protection_and_unchanged_completed_history(): void {
        global $DB;
        [$cm, $s1, , $t1, $t2] = $this->fixture();
        $record = (object)['cmid' => $cm->id, 'studentid' => $s1->id, 'submissionid' => 1,
            'versionhash' => str_repeat('a',64), 'state' => 'claimed', 'claimedby' => $t1->id,
            'comments' => 'In-progress draft', 'certification' => '', 'timecreated' => time(), 'timemodified' => time()];
        $id = $DB->insert_record('local_reviewworkflow_req', $record);
        $record->versionhash = str_repeat('b',64); $record->state = 'completed'; $record->completedby = $t1->id;
        $record->certification = 'Original completed certification';
        $completedid = $DB->insert_record('local_reviewworkflow_req', $record);
        $before = $DB->get_record('local_reviewworkflow_req', ['id' => $completedid]);
        $p = importer::preview($cm, "student,teacher1\n$s1->email,$t2->email\n", 'email', 'replace');
        $this->assertCount(1, $p['claims']);
        try { importer::apply($cm, $p); $this->fail('Claim removal requires confirmation'); }
        catch (\moodle_exception $e) { $this->assertSame('importclaims', $e->errorcode); }
        $this->assertTrue($DB->record_exists('local_reviewworkflow_map', ['reviewerid' => $t1->id]));
        importer::apply($cm, $p, true);
        $this->assertSame('pending', $DB->get_field('local_reviewworkflow_req', 'state', ['id' => $id]));
        $this->assertEquals($before, $DB->get_record('local_reviewworkflow_req', ['id' => $completedid]));
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_chg', ['action' => 'claimreleased']));
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_log', ['action' => 'released']));
    }

    public function test_expired_wrong_actor_and_revoked_eligibility(): void {
        global $DB;
        [$cm, $s1, , , $t2] = $this->fixture();
        $csv = "student,teacher1\n$s1->email,$t2->email\n";
        $p = importer::preview($cm, $csv, 'email'); $p['created'] -= 1801;
        try { importer::apply($cm, $p); $this->fail('Expired preview accepted'); }
        catch (\moodle_exception $e) { $this->assertSame('importexpired', $e->errorcode); }
        $p = importer::preview($cm, $csv, 'email');
        $DB->set_field('user', 'suspended', 1, ['id' => $t2->id]);
        try { importer::apply($cm, $p); $this->fail('Suspended reviewer accepted'); }
        catch (\moodle_exception $e) { $this->assertSame('importinvalid', $e->errorcode); }
        $this->setUser($s1);
        $this->expectException(\moodle_exception::class); importer::preview($cm, $csv, 'email');
    }

    public function test_manual_removal_cannot_strand_active_claim(): void {
        global $DB;
        [$cm, $s1, , $t1] = $this->fixture();
        $DB->insert_record('local_reviewworkflow_req', (object)[
            'cmid' => $cm->id, 'studentid' => $s1->id, 'versionhash' => str_repeat('c',64),
            'state' => 'claimed', 'claimedby' => $t1->id, 'comments' => '', 'certification' => '',
        ]);
        $this->expectException(\moodle_exception::class); workflow::map($cm, [$s1->id], [$t1->id], true);
    }

    public function test_export_formula_protection_and_scope_ownership(): void {
        global $DB;
        [$cm, $s1, , $t1, $t2] = $this->fixture();
        $DB->set_field('user', 'username', '=SUM(1,1)', ['id' => $t1->id]);
        $csv = importer::export($cm, 'username');
        $this->assertStringContainsString("'=SUM(1,1)", $csv);
        $p = importer::preview($cm, $csv, 'username', 'replace');
        $this->assertEmpty($p['errors']); $this->assertEmpty($p['additions']); $this->assertEmpty($p['removals']);
        $p['actorid'] = $t2->id;
        $this->expectException(\moodle_exception::class); importer::apply($cm, $p);
    }

    public function test_new_claim_after_preview_requires_new_preview(): void {
        global $DB;
        [$cm, $s1, , $t1, $t2] = $this->fixture();
        $p = importer::preview($cm, "student,teacher1\n$s1->email,$t2->email\n", 'email', 'replace');
        $DB->insert_record('local_reviewworkflow_req', (object)[
            'cmid' => $cm->id, 'studentid' => $s1->id, 'versionhash' => str_repeat('d',64),
            'state' => 'claimed', 'claimedby' => $t1->id, 'comments' => '', 'certification' => '',
        ]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('importstale', 'local_reviewworkflow'));
        importer::apply($cm, $p, true);
    }

    public function test_oversized_files_and_unprivileged_export_history_are_rejected(): void {
        [$cm, $s1] = $this->fixture();
        $p = importer::preview($cm, str_repeat('a', 1048577), 'email');
        $this->assertNotEmpty($p['errors']);
        $this->setUser($s1);
        foreach (['email', 'username'] as $kind) {
            try { importer::export($cm, $kind); $this->fail('Student exported mappings'); }
            catch (\moodle_exception $e) { $this->assertSame('forbidden', $e->errorcode); }
        }
        $this->expectException(\moodle_exception::class); importer::history($cm);
    }

    public function test_upgrade_adds_history_without_changing_existing_mappings(): void {
        global $DB, $CFG;
        [$cm, $s1, , $t1] = $this->fixture();
        $this->preventResetByRollback();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/reviewworkflow/db/upgrade.php');
        $manager = $DB->get_manager();
        $manager->drop_table(new \xmldb_table('local_reviewworkflow_chg'));
        $manager->drop_table(new \xmldb_table('local_reviewworkflow_imp'));
        set_config('version', 2026102802, 'local_reviewworkflow');
        $this->assertTrue(xmldb_local_reviewworkflow_upgrade(2026102802));
        $this->assertTrue($manager->table_exists('local_reviewworkflow_imp'));
        $this->assertTrue($manager->table_exists('local_reviewworkflow_chg'));
        $this->assertTrue($DB->record_exists('local_reviewworkflow_map', [
            'cmid' => $cm->id, 'studentid' => $s1->id, 'reviewerid' => $t1->id,
        ]));
    }

    public function test_export_preserves_identifiers_that_already_start_with_apostrophe(): void {
        global $DB;
        [$cm, , , $t1] = $this->fixture();
        $DB->set_field('user', 'email', "'+reviewer@example.test", ['id' => $t1->id]);
        $p = importer::preview($cm, importer::export($cm, 'email'), 'email', 'replace');
        $this->assertEmpty($p['errors']);
        $this->assertEmpty($p['additions']); $this->assertEmpty($p['removals']);
    }
}
