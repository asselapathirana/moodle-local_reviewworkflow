<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

final class privacy_test extends \advanced_testcase {
    public function test_metadata_declares_personal_records_and_message_link(): void {
        $this->resetAfterTest();
        $collection = new \core_privacy\local\metadata\collection('local_reviewworkflow');
        $result = \local_reviewworkflow\privacy\provider::get_metadata($collection);
        $this->assertCount(6, $result->get_collection());
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
}
