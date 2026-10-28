<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

final class privacy_test extends \core_privacy\tests\provider_testcase {
    public function test_metadata_declares_personal_records_and_message_link(): void {
        $this->resetAfterTest();
        $collection = new \core_privacy\local\metadata\collection('local_reviewworkflow');
        $result = \local_reviewworkflow\privacy\provider::get_metadata($collection);
        $this->assertCount(8, $result->get_collection());
        $this->assertInstanceOf(\core_privacy\local\metadata\provider::class,
            new \local_reviewworkflow\privacy\provider());
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
