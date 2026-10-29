<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/export_fixture.php');

final class privacy_test extends \core_privacy\tests\provider_testcase {
    public function test_metadata_declares_personal_records_and_message_link(): void {
        $this->resetAfterTest();
        $collection = new \core_privacy\local\metadata\collection('local_reviewworkflow');
        $result = \local_reviewworkflow\privacy\provider::get_metadata($collection);
        $this->assertCount(11, $result->get_collection());
        $this->assertInstanceOf(\core_privacy\local\metadata\provider::class,
            new \local_reviewworkflow\privacy\provider());
    }

    public function test_snapshot_and_export_erasure_invalidates_entire_related_package(): void {
        global $DB;
        $this->resetAfterTest(); $this->setAdminUser();
        [$course, $student, $teacher, $cm, , $r] = export_fixture($this->getDataGenerator());
        $this->setUser($teacher); workflow::claim($r->id);
        workflow::save($r->id, 'acceptable', 'Synthetic review', true, true);
        $this->setAdminUser(); $id = exporter::enqueue($course->id, []); exporter::build($id);
        $ctx = \context_module::instance($cm->id); $coursectx = \context_course::instance($course->id);
        $contexts = \local_reviewworkflow\privacy\provider::get_contexts_for_userid($student->id);
        $this->assertContains((int)$coursectx->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist($student,
            'local_reviewworkflow', [$ctx->id, $coursectx->id]);
        \local_reviewworkflow\privacy\provider::export_user_data($approved);
        $data = \core_privacy\local\request\writer::with_context($coursectx)->get_data([]);
        $this->assertCount(1, $data->exportitems);
        \local_reviewworkflow\privacy\provider::delete_data_for_user($approved);
        $this->assertSame('expired', $DB->get_field('local_reviewworkflow_exp', 'state', ['id' => $id]));
        $this->assertEquals(0, $DB->count_records('local_reviewworkflow_exi', ['exportid' => $id]));
        foreach ([[$ctx->id, 'evidence', $r->id], [$coursectx->id, 'export', $id]] as [$cid, $area, $item]) {
            $this->assertCount(0, get_file_storage()->get_area_files($cid, 'local_reviewworkflow', $area, $item, '', false));
        }
    }

    public function test_export_owner_without_review_mapping_is_discoverable_and_erasable(): void {
        global $DB, $USER;
        $this->resetAfterTest(); $this->setAdminUser();
        [$course] = export_fixture($this->getDataGenerator());
        $id = exporter::enqueue($course->id, []);
        $ctx = \context_course::instance($course->id);
        $contexts = \local_reviewworkflow\privacy\provider::get_contexts_for_userid($USER->id);
        $this->assertContains((int)$ctx->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist($USER, 'local_reviewworkflow', [$ctx->id]);
        \local_reviewworkflow\privacy\provider::delete_data_for_user($approved);
        $this->assertFalse($DB->record_exists('local_reviewworkflow_exp', ['id' => $id]));
    }

    public function test_context_discovery_and_user_erasure(): void {
        global $DB;
        $this->resetAfterTest();
        $g = $this->getDataGenerator(); $c = $g->create_course();
        $a = $g->create_module('assign', ['course' => $c->id]);
        $cm = get_coursemodule_from_instance('assign', $a->id);
        $u = $g->create_user();
        $DB->insert_record('local_reviewworkflow_map', (object)[
            'cmid' => $cm->id, 'studentid' => $u->id, 'reviewerid' => 2,
        ]);
        $contexts = \local_reviewworkflow\privacy\provider::get_contexts_for_userid($u->id);
        $ctx = \context_module::instance($cm->id);
        $this->assertContains((int)$ctx->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist($u,
            'local_reviewworkflow', [$ctx->id]);
        \local_reviewworkflow\privacy\provider::delete_data_for_user($approved);
        $this->assertEquals(0, $DB->count_records('local_reviewworkflow_map', ['studentid' => $u->id]));
    }

    public function test_import_history_discovery_export_and_erasure(): void {
        global $DB;
        $this->resetAfterTest(); $g = $this->getDataGenerator();
        $course = $g->create_course(); $a = $g->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $a->id); $ctx = \context_module::instance($cm->id);
        $actor = $g->create_user(); $student = $g->create_user(); $teacher = $g->create_user();
        $id = $DB->insert_record('local_reviewworkflow_imp', (object)[
            'cmid' => $cm->id, 'actorid' => $actor->id, 'mode' => 'replace', 'idtype' => 'email',
            'sourcehash' => str_repeat('a',64), 'tokenhash' => str_repeat('b',64), 'timecreated' => time(),
        ]);
        $DB->insert_record('local_reviewworkflow_chg', (object)[
            'importid' => $id, 'studentid' => $student->id, 'reviewerid' => $teacher->id, 'action' => 'removed',
        ]);
        foreach ([$actor, $student, $teacher] as $u) {
            $list = \local_reviewworkflow\privacy\provider::get_contexts_for_userid($u->id);
            $this->assertContains((int)$ctx->id, array_map('intval', $list->get_contextids()));
        }
        $list = new \core_privacy\local\request\userlist($ctx, 'local_reviewworkflow');
        \local_reviewworkflow\privacy\provider::get_users_in_context($list);
        $this->assertContains((int)$actor->id, array_map('intval', $list->get_userids()));
        $approved = new \core_privacy\local\request\approved_contextlist($student, 'local_reviewworkflow', [$ctx->id]);
        \local_reviewworkflow\privacy\provider::export_user_data($approved);
        $data = \core_privacy\local\request\writer::with_context($ctx)->get_data([]);
        $this->assertCount(1, $data->importchanges);
        \local_reviewworkflow\privacy\provider::delete_data_for_user($approved);
        $this->assertEquals(0, $DB->count_records('local_reviewworkflow_chg', ['studentid' => $student->id]));
        $approved = new \core_privacy\local\request\approved_contextlist($actor, 'local_reviewworkflow', [$ctx->id]);
        \local_reviewworkflow\privacy\provider::delete_data_for_user($approved);
        $this->assertEquals(0, $DB->get_field('local_reviewworkflow_imp', 'actorid', ['id' => $id]));
    }
}
