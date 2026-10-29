<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

/** Owner-bound, expiring export jobs. Uses Moodle files, tasks and locks. */
final class exporter {
    public static function course_allowed(int $courseid, ?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int)$USER->id;
        $user = \core_user::get_user($userid);
        if (!$user || $user->deleted || $user->suspended || isguestuser($user)) { return false; }
        $ctx = \context_course::instance($courseid);
        return has_capability('local/reviewworkflow:export', $ctx, $userid)
            && (is_siteadmin($userid) || is_enrolled($ctx, $userid, '', true)
                || has_capability('moodle/course:view', $ctx, $userid));
    }

    public static function allowed(\stdClass $cm, ?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int)$USER->id;
        $ctx = \context_module::instance($cm->id);
        return self::course_allowed((int)$cm->course, $userid)
            && has_capability('local/reviewworkflow:export', $ctx, $userid)
            && has_capability('mod/assign:grade', $ctx, $userid)
            && get_fast_modinfo($cm->course, $userid)->get_cm($cm->id)->uservisible
            && (new adapter\assignment())->supports($cm);
    }

    public static function require_course(int $courseid, ?int $userid = null): void {
        if (!self::course_allowed($courseid, $userid)) { throw new \moodle_exception('forbidden', 'local_reviewworkflow'); }
    }

    public static function headers(): array {
        return ['Review ID', 'Student', 'Assignment', 'Moodle attempt', 'Submission version', 'Status',
            'Decision', 'Reviewer', 'Completed', 'Timezone', 'Comments', 'Certification', 'Evidence'];
    }

    public static function describe_filters(array $input): string {
        $f = report_query::normalise($input);
        $parts = [get_string($f['history'] ? 'includehistory' : 'latestonly', 'local_reviewworkflow')];
        if ($f['cmid']) {
            $cm = get_coursemodule_from_id('assign', $f['cmid']);
            $parts[] = get_string('assignment', 'local_reviewworkflow') . ': ' . ($cm ? $cm->name : '#' . $f['cmid']);
        }
        if ($f['state']) {
            $parts[] = get_string(in_array($f['state'], ['reviewed', 'unreviewed']) ? $f['state'] : 'state_' . $f['state'], 'local_reviewworkflow');
        }
        if ($f['decisions']) {
            $parts[] = implode(' / ', array_map(fn($d) => get_string($d, 'local_reviewworkflow'), $f['decisions']));
        }
        foreach (['student', 'reviewer'] as $field) {
            if ($f[$field]) { $parts[] = get_string($field, 'local_reviewworkflow') . ': ' . $f[$field]; }
        }
        foreach (['from', 'to'] as $field) {
            if ($f[$field]) { $parts[] = get_string('filter' . $field, 'local_reviewworkflow') . ': ' . $f[$field]; }
        }
        return implode(' · ', $parts);
    }

    public static function csv_row($stream, array $data): void {
        $cells = [$data['id'], $data['studentname'], $data['assignmentname'], $data['attempt'] + 1,
            $data['versionnumber'], get_string('state_' . $data['state'], 'local_reviewworkflow'),
            $data['decision'] ? get_string($data['decision'], 'local_reviewworkflow') : '',
            $data['reviewername'], $data['completeddate'], $data['timezone'], $data['comments'], $data['certification'],
            get_string('evidence_' . $data['provenance'], 'local_reviewworkflow')];
        foreach ($cells as &$cell) {
            $cell = (string)$cell;
            if (preg_match('/^(?:[\t\r\n]|[\x00-\x20]*[=+@-])/', $cell)) { $cell = "'" . $cell; }
        }
        unset($cell);
        fputcsv($stream, $cells, ',', '"', '');
    }

    public static function csv(int $courseid, array $filters, $stream): void {
        global $USER;
        self::require_course($courseid);
        fwrite($stream, "\xEF\xBB\xBF"); fputcsv($stream, self::headers(), ',', '"', '');
        foreach (report_query::rows($courseid, $filters, null, null, true) as $r) {
            $data = report_query::data($r, $USER->id);
            $data['provenance'] = evidence::files($r)['provenance'];
            self::csv_row($stream, $data);
        }
    }

    public static function enqueue(int $courseid, array $filters): int {
        global $DB, $USER;
        self::require_course($courseid); $filters = report_query::normalise($filters);
        return workflow::locked(function() use ($DB, $USER, $courseid, $filters) {
            $tx = $DB->start_delegated_transaction();
            try {
                $now = time();
                $id = $DB->insert_record('local_reviewworkflow_exp', (object)['courseid' => $courseid,
                    'userid' => $USER->id, 'filters' => json_encode($filters), 'state' => 'queued',
                    'total' => 0, 'processed' => 0, 'error' => '', 'timecreated' => $now,
                    'timemodified' => $now, 'expires' => $now + 7 * DAYSECS]);
                $count = 0;
                foreach (report_query::rows($courseid, $filters, null, null, true) as $r) {
                    $DB->insert_record('local_reviewworkflow_exi', (object)['exportid' => $id,
                        'requestid' => $r->id, 'cmid' => $r->cmid, 'studentid' => $r->studentid,
                        'reviewerid' => $r->completedby ?: $r->claimedby,
                        'payload' => json_encode(report_query::data($r, $USER->id), JSON_THROW_ON_ERROR)]);
                    $count++;
                }
                if (!$count) { throw new \moodle_exception('exportempty', 'local_reviewworkflow'); }
                $DB->set_field('local_reviewworkflow_exp', 'total', $count, ['id' => $id]);
                $task = new task\build_export(); $task->set_userid($USER->id);
                $task->set_custom_data(['exportid' => $id]);
                \core\task\manager::queue_adhoc_task($task);
                $tx->allow_commit(); return (int)$id;
            } catch (\Throwable $e) { $tx->rollback($e); }
        });
    }

    private static function check_items(\stdClass $job): void {
        global $DB;
        self::require_course($job->courseid, $job->userid);
        foreach ($DB->get_fieldset_sql('SELECT DISTINCT cmid FROM {local_reviewworkflow_exi} WHERE exportid = ?', [$job->id]) as $id) {
            $cm = get_coursemodule_from_id('assign', $id);
            if (!$cm || !self::allowed($cm, $job->userid)) { throw new \moodle_exception('forbidden', 'local_reviewworkflow'); }
        }
    }

    public static function job(int $id): \stdClass {
        global $DB, $USER;
        $job = $DB->get_record('local_reviewworkflow_exp', ['id' => $id], '*', MUST_EXIST);
        if ($job->userid != $USER->id) { throw new \moodle_exception('forbidden', 'local_reviewworkflow'); }
        self::require_course($job->courseid); return $job;
    }

    public static function download(int $id): \stored_file {
        $job = self::job($id);
        if ($job->state !== 'ready' || $job->expires <= time()) { throw new \moodle_exception('exportnotready', 'local_reviewworkflow'); }
        self::check_items($job);
        $file = get_file_storage()->get_file(\context_course::instance($job->courseid)->id,
            'local_reviewworkflow', 'export', $job->id, '/', 'review-export-' . $job->id . '.zip');
        if (!$file) { throw new \moodle_exception('exportnotready', 'local_reviewworkflow'); }
        return $file;
    }

    public static function build(int $id): void {
        global $DB, $CFG;
        $lock = \core\lock\lock_config::get_lock_factory('local_reviewworkflow')->get_lock('export-' . $id, 0);
        if (!$lock) { return; }
        $dir = null;
        try {
            $job = $DB->get_record('local_reviewworkflow_exp', ['id' => $id]);
            if (!$job || in_array($job->state, ['ready', 'expired', 'failed']) || $job->expires <= time()) { return; }
            self::check_items($job);
            $DB->update_record('local_reviewworkflow_exp', (object)['id' => $id, 'state' => 'building',
                'processed' => 0, 'timemodified' => time()]);
            $dir = make_temp_directory('local_reviewworkflow/exports/' . $id);
            $files = []; $rows = []; $processed = 0;
            $csvpath = $dir . '/results.csv'; $csv = fopen($csvpath, 'w');
            if (!$csv) { throw new \RuntimeException('CSV creation failed'); }
            try {
                fwrite($csv, "\xEF\xBB\xBF"); fputcsv($csv, self::headers(), ',', '"', '');
                $items = $DB->get_recordset('local_reviewworkflow_exi', ['exportid' => $id], 'id');
                try {
                    foreach ($items as $item) {
                        if (!$DB->record_exists('local_reviewworkflow_req', ['id' => $item->requestid])) {
                            throw new \moodle_exception('forbidden', 'local_reviewworkflow');
                        }
                        $data = json_decode($item->payload, true, 512, JSON_THROW_ON_ERROR);
                        $evidence = evidence::files((object)$data);
                        $data['provenance'] = $evidence['provenance']; $data['links'] = [];
                        $prefix = 'reviews/review-' . (int)$item->requestid . '/';
                        foreach ($evidence['files'] as $file) {
                            $safe = clean_param($file->get_filename(), PARAM_FILE) ?: 'document';
                            $path = $prefix . 'files/' . $file->get_id() . '-' . $safe;
                            $files[$path] = $file;
                            $data['links'][] = ['path' => $path, 'name' => $file->get_filepath() . $file->get_filename()];
                        }
                        $pdfpath = $dir . '/review-' . $item->requestid . '.pdf';
                        export_document::pdf($data, $pdfpath);
                        $data['pdf'] = $prefix . 'review.pdf'; $files[$data['pdf']] = $pdfpath;
                        $rows[] = $data; self::csv_row($csv, $data);
                        $DB->update_record('local_reviewworkflow_exp', (object)['id' => $id,
                            'processed' => ++$processed, 'timemodified' => time()]);
                    }
                } finally { $items->close(); }
            } finally { fclose($csv); }
            $index = $dir . '/index.html'; file_put_contents($index, export_document::index($rows, $job));
            $files['index.html'] = $index; $files['results.csv'] = $csvpath;
            $files['style.css'] = $CFG->dirroot . '/local/reviewworkflow/export_assets/style.css';
            $files['table.js'] = $CFG->dirroot . '/local/reviewworkflow/export_assets/table.js';
            $archive = $dir . '/export.zip';
            if (!get_file_packer('application/zip')->archive_to_pathname($files, $archive, false)) {
                throw new \RuntimeException('ZIP creation failed');
            }
            workflow::locked(function() use ($DB, $job, $id, $processed, $archive) {
                $current = $DB->get_record('local_reviewworkflow_exp', ['id' => $id], '*', MUST_EXIST);
                if ($current->state !== 'building' || $current->expires <= time()
                        || $DB->count_records('local_reviewworkflow_exi', ['exportid' => $id]) != $job->total
                        || $processed != $job->total) { throw new \moodle_exception('exportnotready', 'local_reviewworkflow'); }
                self::check_items($current);
                $fs = get_file_storage(); $ctx = \context_course::instance($job->courseid);
                $fs->delete_area_files($ctx->id, 'local_reviewworkflow', 'export', $id);
                $fs->create_file_from_pathname(['contextid' => $ctx->id, 'component' => 'local_reviewworkflow',
                    'filearea' => 'export', 'itemid' => $id, 'filepath' => '/',
                    'filename' => 'review-export-' . $id . '.zip', 'userid' => $job->userid], $archive);
                $DB->update_record('local_reviewworkflow_exp', (object)['id' => $id, 'state' => 'ready',
                    'processed' => $processed, 'error' => '', 'timemodified' => time()]);
            });
        } catch (\Throwable $e) {
            $job = $DB->get_record('local_reviewworkflow_exp', ['id' => $id]);
            if ($job && $job->state !== 'expired') {
                $DB->update_record('local_reviewworkflow_exp', (object)['id' => $id,
                    'state' => 'failed', 'error' => 'exportfailed', 'timemodified' => time()]);
            }
        } finally {
            if ($dir) { remove_dir($dir); }
            $lock->release();
        }
    }

    /** Caller holds the workflow lock. Used by TTL cleanup and privacy erasure. */
    public static function expire(\stdClass $job): void {
        global $DB, $CFG;
        get_file_storage()->delete_area_files(\context_course::instance($job->courseid)->id,
            'local_reviewworkflow', 'export', $job->id);
        $DB->delete_records('local_reviewworkflow_exi', ['exportid' => $job->id]);
        $DB->update_record('local_reviewworkflow_exp', (object)['id' => $job->id, 'state' => 'expired',
            'filters' => '', 'error' => '', 'timemodified' => time()]);
        $lock = \core\lock\lock_config::get_lock_factory('local_reviewworkflow')->get_lock('export-' . $job->id, 0);
        if ($lock) {
            try { remove_dir($CFG->tempdir . '/local_reviewworkflow/exports/' . $job->id); }
            finally { $lock->release(); }
        }
    }

    public static function cleanup(): void {
        global $DB, $CFG;
        workflow::locked(function() use ($DB) {
            foreach ($DB->get_records_select('local_reviewworkflow_exp', 'expires <= ? AND state <> ?', [time(), 'expired']) as $job) {
                self::expire($job);
            }
        });
        foreach ($DB->get_records('local_reviewworkflow_exp', ['state' => 'expired']) as $job) {
            $lock = \core\lock\lock_config::get_lock_factory('local_reviewworkflow')->get_lock('export-' . $job->id, 0);
            if ($lock) {
                try { remove_dir($CFG->tempdir . '/local_reviewworkflow/exports/' . $job->id); }
                finally { $lock->release(); }
            }
        }
    }
}
