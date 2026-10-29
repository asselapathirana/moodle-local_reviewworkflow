<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/export_fixture.php');

final class report_query_test extends \advanced_testcase {
    public function test_latest_selection_precedes_status_and_decisions(): void {
        global $DB;
        $this->resetAfterTest(); $this->setAdminUser();
        [$course, , $teacher, , , $old] = export_fixture($this->getDataGenerator());
        $old->state = 'completed'; $old->decision = 'acceptable'; $old->completedby = $teacher->id;
        $old->timecompleted = time(); $DB->update_record('local_reviewworkflow_req', $old);
        $new = clone $old; unset($new->id); $new->versionhash = hash('sha256', 'new');
        $new->state = 'pending'; $new->decision = ''; $new->completedby = 0; $new->timecompleted = 0;
        $id = $DB->insert_record('local_reviewworkflow_req', $new);
        $rows = iterator_to_array(report_query::rows($course->id, []));
        $this->assertCount(1, $rows); $this->assertEquals($id, reset($rows)->id);
        $this->assertCount(0, iterator_to_array(report_query::rows($course->id, ['state' => 'reviewed'])));
        $this->assertCount(0, iterator_to_array(report_query::rows($course->id, ['decisions' => ['acceptable']])));
        $rows = iterator_to_array(report_query::rows($course->id, ['history' => 1, 'state' => 'reviewed',
            'decisions' => ['acceptable', 'concerns'], 'student' => 'Sam', 'reviewer' => 'Alex']));
        $this->assertCount(1, $rows); $this->assertEquals($old->id, reset($rows)->id);
    }

    public function test_three_hundred_rows_paginate_without_limiting_full_selection(): void {
        global $DB;
        $this->resetAfterTest(); $this->setAdminUser();
        [$course, , , , , $row] = export_fixture($this->getDataGenerator());
        unset($row->id);
        for ($i = 1; $i < 300; $i++) {
            $row->versionhash = hash('sha256', 'version-' . $i);
            $DB->insert_record('local_reviewworkflow_req', $row);
        }
        $filters = ['history' => 1];
        $this->assertCount(300, iterator_to_array(report_query::rows($course->id, $filters)));
        $page1 = iterator_to_array(report_query::rows($course->id, $filters, 0));
        $page2 = iterator_to_array(report_query::rows($course->id, $filters, 1));
        $this->assertCount(50, $page1); $this->assertCount(50, $page2);
        $this->assertEmpty(array_intersect(array_column($page1, 'id'), array_column($page2, 'id')));
        $this->assertEquals(300, reset($page1)->versionnumber);
    }

    public function test_invalid_filters_and_unmapped_access(): void {
        $this->resetAfterTest(); $this->setAdminUser();
        [$course, $student] = export_fixture($this->getDataGenerator());
        foreach ([['from' => '2026-02-30'], ['from' => '2026-10-30', 'to' => '2026-10-01'],
                ['decisions' => ['unknown']], ['decisions' => 'acceptable'],
                ['decisions' => [['acceptable']]], ['state' => 'unknown']] as $filters) {
            try { report_query::normalise($filters); $this->fail('Invalid filter accepted'); }
            catch (\moodle_exception $e) { $this->assertSame('invalidfilters', $e->errorcode); }
        }
        $this->setUser($student);
        $this->assertCount(0, iterator_to_array(report_query::rows($course->id, [])));
    }

    public function test_multiple_decisions_and_inclusive_dates_use_requester_timezone(): void {
        global $DB, $USER;
        $this->resetAfterTest(); $this->setAdminUser();
        [$course, , $teacher, , , $r] = export_fixture($this->getDataGenerator());
        $user = clone $USER; $user->timezone = 'Europe/Amsterdam'; $this->setUser($user);
        $r->state = 'completed'; $r->completedby = $teacher->id; $r->decision = 'acceptable';
        $r->timecompleted = strtotime('2026-10-29 22:30:00 UTC'); $DB->update_record('local_reviewworkflow_req', $r);
        unset($r->id); $r->versionhash = hash('sha256', 'date-next'); $r->decision = 'concerns';
        $r->timecompleted = strtotime('2026-10-29 23:00:00 UTC'); $DB->insert_record('local_reviewworkflow_req', $r);
        $filters = ['history' => 1, 'decisions' => ['acceptable', 'concerns']];
        $this->assertCount(2, iterator_to_array(report_query::rows($course->id, $filters)));
        $filters += ['from' => '2026-10-29', 'to' => '2026-10-29'];
        $rows = iterator_to_array(report_query::rows($course->id, $filters));
        $this->assertCount(1, $rows); $this->assertSame('acceptable', reset($rows)->decision);
    }

    public function test_empty_moodle_multiselect_marker_means_all_decisions(): void {
        $this->resetAfterTest();
        $before = $_GET;
        try {
            $_GET['decisions'] = '_qf__force_multiselect_submission';
            $filters = report_query::normalise(\local_reviewworkflow\form\report_filters::parameters());
            $this->assertSame([], $filters['decisions']);
            $this->assertDebuggingNotCalled();
        } finally { $_GET = $before; }
    }
}
