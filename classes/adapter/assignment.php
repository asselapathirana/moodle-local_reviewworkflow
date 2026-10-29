<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\adapter;
defined('MOODLE_INTERNAL') || die();

/** Read-only adapter for individual standard assignment file evidence. */
final class assignment implements activity_adapter {
    public function supports(\stdClass $cm): bool {
        global $DB;
        if ($cm->modname !== 'assign' || groups_get_activity_groupmode($cm) != NOGROUPS) {
            return false;
        }
        $a = $DB->get_record('assign', ['id' => $cm->instance]);
        return $a && !$a->teamsubmission && (bool)$DB->get_field('assign_plugin_config', 'value',
            ['assignment' => $a->id, 'plugin' => 'file', 'subtype' => 'assignsubmission', 'name' => 'enabled']);
    }

    public function submissions(\stdClass $cm, ?int $studentid = null): \Generator {
        global $DB;
        if (!$this->supports($cm)) { return; }
        $context = \context_module::instance($cm->id);
        $where = 'assignment = :assignment AND latest = 1 AND status = :status AND userid > 0 AND groupid = 0';
        $params = ['assignment' => $cm->instance, 'status' => 'submitted'];
        if ($studentid !== null) { $where .= ' AND userid = :student'; $params['student'] = $studentid; }
        $records = $DB->get_recordset_select('assign_submission', $where, $params, 'id');
        try {
            foreach ($records as $s) {
                $files = get_file_storage()->get_area_files($context->id, 'assignsubmission_file',
                    'submission_files', $s->id, 'filepath, filename', false);
                if (!$files) { continue; }
                $manifest = [];
                foreach ($files as $f) {
                    $manifest[] = [$f->get_filepath(), $f->get_filename(), $f->get_contenthash()];
                }
                yield (object)[
                    'submissionid' => (int)$s->id, 'studentid' => (int)$s->userid,
                    'attempt' => (int)$s->attemptnumber, 'files' => $files,
                    'versionhash' => hash('sha256', json_encode([$s->id, $s->attemptnumber, $manifest])),
                ];
            }
        } finally { $records->close(); }
    }
}
