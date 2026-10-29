<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/export_fixture.php');

final class exporter_test extends \advanced_testcase {
    public function test_export_roles_are_configurable_and_not_inferred_from_teacher_role(): void {
        $this->resetAfterTest(); $this->setAdminUser(); $g = $this->getDataGenerator();
        [$course, , $teacher, $cm] = export_fixture($g);
        $editor = $g->create_user(); $manager = $g->create_user();
        $g->enrol_user($editor->id, $course->id, 'editingteacher');
        $g->enrol_user($manager->id, $course->id, 'manager');
        $this->assertTrue(exporter::allowed($cm));
        $this->assertFalse(exporter::allowed($cm, $teacher->id));
        $this->assertFalse(exporter::allowed($cm, $editor->id));
        $this->assertTrue(exporter::allowed($cm, $manager->id));
        $role = $g->create_role(['shortname' => 'modulecoordinator', 'name' => 'Module Coordinator']);
        assign_capability('local/reviewworkflow:export', CAP_ALLOW, $role, \context_course::instance($course->id));
        role_assign($role, $teacher->id, \context_course::instance($course->id));
        $this->assertTrue(exporter::allowed($cm, $teacher->id));
    }

    public function test_csv_contains_all_matching_rows_and_neutralises_formulas(): void {
        global $DB;
        $this->resetAfterTest(); $this->setAdminUser();
        [$course, $student, , , , $r] = export_fixture($this->getDataGenerator());
        $DB->set_field('user', 'firstname', '=FORMULA', ['id' => $student->id]);
        unset($r->id);
        for ($i = 1; $i < 60; $i++) {
            $r->versionhash = hash('sha256', 'csv-' . $i); $DB->insert_record('local_reviewworkflow_req', $r);
        }
        $stream = fopen('php://temp', 'w+');
        exporter::csv($course->id, ['history' => 1], $stream);
        rewind($stream); $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) { $rows[] = $row; }
        fclose($stream);
        $this->assertCount(61, $rows);
        $this->assertStringStartsWith("'=FORMULA", $rows[1][1]);
    }

    public function test_background_archive_contains_frozen_results_pdf_and_original_files(): void {
        global $DB;
        $this->resetAfterTest(); $this->setAdminUser();
        [$course, , $teacher, , , $r] = export_fixture($this->getDataGenerator());
        $this->setUser($teacher); workflow::claim($r->id);
        workflow::save($r->id, 'acceptable', 'Original reviewed comments', true, true);
        $this->setAdminUser();
        $id = exporter::enqueue($course->id, []);
        $this->assertEquals(1, $DB->count_records('local_reviewworkflow_exi', ['exportid' => $id]));
        $this->assertTrue($DB->record_exists('task_adhoc', ['classname' => '\\local_reviewworkflow\\task\\build_export']));
        $DB->set_field('local_reviewworkflow_req', 'comments', 'Later text is not selected', ['id' => $r->id]);
        exporter::build($id); exporter::build($id);
        $job = $DB->get_record('local_reviewworkflow_exp', ['id' => $id]);
        $this->assertSame('ready', $job->state); $this->assertEquals(1, $job->processed);
        $file = exporter::download($id);
        $path = make_request_directory() . '/test.zip'; $file->copy_content_to($path);
        $zip = new \ZipArchive(); $this->assertTrue($zip->open($path));
        $index = $zip->getFromName('index.html'); $csv = $zip->getFromName('results.csv');
        $this->assertStringContainsString('Sam Student', $index);
        $this->assertStringContainsString('Original reviewed comments', $csv);
        $this->assertStringNotContainsString('Later text is not selected', $csv);
        preg_match_all('/href="([^"]+)"/', $index, $links);
        foreach ($links[1] as $link) {
            $this->assertNotFalse($zip->locateName(rawurldecode($link)), $link);
        }
        $pdf = $zip->getFromName('reviews/review-' . $r->id . '/review.pdf');
        $this->assertStringStartsWith('%PDF-', $pdf);
        $found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with($zip->getNameIndex($i), '-paper.txt')) {
                $this->assertSame('Original synthetic evidence', $zip->getFromIndex($i)); $found = true;
            }
        }
        $this->assertTrue($found); $zip->close();
    }

    public function test_jobs_are_owner_only_and_expire_after_seven_days(): void {
        global $DB;
        $this->resetAfterTest(); $this->setAdminUser();
        [$course, , $teacher, $cm, , $r] = export_fixture($this->getDataGenerator());
        $this->setUser($teacher); workflow::claim($r->id);
        workflow::save($r->id, 'acceptable', '', true, true);
        $this->setAdminUser();
        $id = exporter::enqueue($course->id, []); exporter::build($id);
        $job = $DB->get_record('local_reviewworkflow_exp', ['id' => $id]);
        $this->assertEquals(7 * DAYSECS, $job->expires - $job->timecreated);
        $this->setUser($teacher);
        try { exporter::download($id); $this->fail('Another user must not download'); }
        catch (\moodle_exception $e) { $this->assertSame('forbidden', $e->errorcode); }
        $this->setAdminUser();
        $DB->set_field('local_reviewworkflow_exp', 'expires', time() - 1, ['id' => $id]);
        try { exporter::download($id); $this->fail('Expired file must not download'); }
        catch (\moodle_exception $e) { $this->assertSame('exportnotready', $e->errorcode); }
        exporter::cleanup();
        $this->assertSame('expired', $DB->get_field('local_reviewworkflow_exp', 'state', ['id' => $id]));
        $this->assertEquals(0, $DB->count_records('local_reviewworkflow_exi', ['exportid' => $id]));
        $files = get_file_storage()->get_area_files(\context_course::instance($course->id)->id,
            'local_reviewworkflow', 'export', $id, '', false);
        $this->assertCount(0, $files);
        $this->assertCount(1, get_file_storage()->get_area_files(\context_module::instance($cm->id)->id,
            'local_reviewworkflow', 'evidence', $r->id, '', false));
    }

    public function test_revoked_export_permission_blocks_build_and_download(): void {
        global $DB;
        $this->resetAfterTest(); $this->setAdminUser(); $g = $this->getDataGenerator();
        [$course, , $teacher] = export_fixture($g);
        $role = $g->create_role(['shortname' => 'exportcoordinator']);
        $ctx = \context_course::instance($course->id);
        assign_capability('local/reviewworkflow:export', CAP_ALLOW, $role, $ctx);
        role_assign($role, $teacher->id, $ctx);
        $this->setUser($teacher); $id = exporter::enqueue($course->id, []);
        unassign_capability('local/reviewworkflow:export', $role, $ctx);
        exporter::build($id);
        $this->assertSame('failed', $DB->get_field('local_reviewworkflow_exp', 'state', ['id' => $id]));
        $this->assertEquals(0, $DB->get_field('local_reviewworkflow_exp', 'processed', ['id' => $id]));
        try { exporter::download($id); $this->fail('Revoked actor must not download'); }
        catch (\moodle_exception $e) { $this->assertSame('forbidden', $e->errorcode); }
    }

    public function test_upgrade_from_email_release_preserves_existing_records_and_schedule(): void {
        global $DB, $CFG;
        $this->resetAfterTest(); $this->setAdminUser(); $this->preventResetByRollback();
        [, , , , , $r] = export_fixture($this->getDataGenerator());
        $notice = $DB->get_record('local_reviewworkflow_msg', ['requestid' => $r->id]);
        $taskkey = ['classname' => '\\local_reviewworkflow\\task\\scan'];
        $DB->set_field('task_scheduled', 'minute', '*', $taskkey);
        $DB->set_field('task_scheduled', 'customised', 1, $taskkey);
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/reviewworkflow/db/upgrade.php');
        $dbman = $DB->get_manager();
        $dbman->drop_table(new \xmldb_table('local_reviewworkflow_exi'));
        $dbman->drop_table(new \xmldb_table('local_reviewworkflow_exp'));
        $dbman->drop_field(new \xmldb_table('local_reviewworkflow_req'), new \xmldb_field('evidencecaptured'));
        set_config('version', 2026102900, 'local_reviewworkflow');
        $this->assertTrue(xmldb_local_reviewworkflow_upgrade(2026102900));
        $this->assertEquals($r, $DB->get_record('local_reviewworkflow_req', ['id' => $r->id]));
        $this->assertEquals($notice, $DB->get_record('local_reviewworkflow_msg', ['id' => $notice->id]));
        $this->assertSame('*', $DB->get_field('task_scheduled', 'minute', $taskkey));
        $this->assertTrue($dbman->table_exists('local_reviewworkflow_exp'));
        $this->assertTrue($dbman->table_exists('local_reviewworkflow_exi'));
    }
}
