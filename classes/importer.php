<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

/** Assignment-scoped, preview-first mapping import and export. */
final class importer {
    private static function authorise(\stdClass $cm): void {
        if (!access::manager($cm)) { throw new \moodle_exception('forbidden', 'local_reviewworkflow'); }
        if (!(new adapter\assignment())->supports($cm)) { throw new \moodle_exception('unsupported', 'local_reviewworkflow'); }
    }

    private static function problem(string $key, $value = null): string {
        return get_string($key, 'local_reviewworkflow', $value);
    }

    /** Reject invalid quote structure which fgetcsv alone would tolerate. */
    private static function valid_csv(string $csv): bool {
        $quoted = false; $start = true; $after = false; $length = strlen($csv);
        for ($i = 0; $i < $length; $i++) {
            $c = $csv[$i];
            if ($quoted) {
                if ($c === '"') {
                    if ($i + 1 < $length && $csv[$i + 1] === '"') { $i++; }
                    else { $quoted = false; $after = true; }
                }
                continue;
            }
            if ($after && ($c === ' ' || $c === "\t")) { continue; }
            if ($c === ',' || $c === "\r" || $c === "\n") { $start = true; $after = false; continue; }
            if ($after) { return false; }
            if ($c === '"') { if (!$start) { return false; } $quoted = true; }
            $start = false;
        }
        return !$quoted;
    }

    private static function decode(string $value): string {
        $value = trim($value);
        if (str_starts_with($value, "''") || preg_match("/^'[=+@\\-\\t\\r]/", $value)) { $value = substr($value, 1); }
        return $value;
    }

    private static function resolve(string $identifier, string $idtype, array &$errors, int $line): ?\stdClass {
        global $DB;
        $users = $DB->get_records_select('user', $DB->sql_equal($idtype, ':identifier', false) . ' AND deleted = 0',
            ['identifier' => $identifier], 'id', '*', 0, 2);
        if (count($users) !== 1) {
            $reason = self::problem($users ? 'importambiguous' : 'importunknown', $identifier);
            $errors[] = self::problem('importrowerror', (object)['line' => $line, 'reason' => $reason]);
            return null;
        }
        return reset($users);
    }

    private static function pair(int $studentid, int $reviewerid): array {
        return ['studentid' => $studentid, 'reviewerid' => $reviewerid];
    }

    private static function mappings(int $cmid): array {
        global $DB;
        $pairs = [];
        foreach ($DB->get_records('local_reviewworkflow_map', ['cmid' => $cmid]) as $m) {
            $pairs[$m->studentid . ':' . $m->reviewerid] = self::pair((int)$m->studentid, (int)$m->reviewerid);
        }
        ksort($pairs); return $pairs;
    }

    public static function preview(\stdClass $cm, string $csv, string $idtype, string $mode = 'add'): array {
        global $DB, $USER;
        self::authorise($cm);
        $p = ['cmid' => (int)$cm->id, 'actorid' => (int)$USER->id, 'created' => time(),
            'nonce' => bin2hex(random_bytes(16)), 'csv' => $csv, 'idtype' => $idtype, 'mode' => $mode,
            'rows' => [], 'errors' => [], 'warnings' => [], 'additions' => [], 'removals' => [], 'claims' => []];
        if (!in_array($idtype, ['email', 'username']) || !in_array($mode, ['add', 'replace'])) {
            $p['errors'][] = self::problem('importoptions'); return $p;
        }
        $input = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (strlen($csv) > 1048576 || !mb_check_encoding($input, 'UTF-8') || !self::valid_csv($input)) {
            $p['errors'][] = self::problem('importformat'); return $p;
        }
        $stream = fopen('php://temp', 'r+'); fwrite($stream, $input); rewind($stream);
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            $header = $header ? array_map('trim', $header) : [];
            $valid = count($header) >= 2 && count($header) <= 101
                && in_array($header[0], ['student', 'student_' . $idtype]);
            $numbers = [];
            foreach (array_slice($header, 1) as $h) {
                if (!preg_match('/^teacher([1-9][0-9]*)(?:_' . $idtype . ')?$/', $h, $match)
                        || (int)$match[1] > 100 || isset($numbers[$match[1]])) { $valid = false; }
                else { $numbers[$match[1]] = true; }
            }
            if (!$valid) { $p['errors'][] = self::problem('importheaders', $idtype); return $p; }
            $wanted = []; $students = []; $line = 1;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $line++;
                $cells = array_map(fn($v) => self::decode((string)$v), $cells);
                if (!array_filter($cells, fn($v) => $v !== '')) { continue; }
                if (count($p['rows']) >= 10000) { $p['errors'][] = self::problem('importtoomany'); break; }
                if (count($cells) > count($header) || $cells[0] === '') {
                    $p['errors'][] = self::problem('importrowerror', (object)['line' => $line, 'reason' => self::problem('importcolumns')]);
                    continue;
                }
                $row = ['line' => $line, 'student' => null, 'teachers' => []];
                $student = self::resolve($cells[0], $idtype, $p['errors'], $line);
                if (!$student) { $p['rows'][] = $row; continue; }
                $row['student'] = ['id' => (int)$student->id, 'name' => fullname($student), 'identifier' => $cells[0]];
                if (isset($students[$student->id])) {
                    $p['errors'][] = self::problem('importrowerror', (object)['line' => $line, 'reason' => self::problem('importduplicatestudent', $cells[0])]);
                }
                $students[$student->id] = true;
                $context = \context_module::instance($cm->id);
                if ($student->suspended || !is_enrolled($context, $student, '', true)
                        || !has_capability('mod/assign:submit', $context, $student)) {
                    $p['errors'][] = self::problem('importrowerror', (object)['line' => $line, 'reason' => self::problem('importstudent', $cells[0])]);
                }
                $seen = [];
                foreach (array_slice($cells, 1) as $value) {
                    if ($value === '') { continue; }
                    $teacher = self::resolve($value, $idtype, $p['errors'], $line);
                    if (!$teacher) { continue; }
                    if (isset($seen[$teacher->id])) {
                        $p['warnings'][] = self::problem('importduplicate', $value); continue;
                    }
                    $seen[$teacher->id] = true;
                    $row['teachers'][] = ['id' => (int)$teacher->id, 'name' => fullname($teacher), 'identifier' => $value];
                    if (!access::eligible($cm, (int)$student->id, (int)$teacher->id)) {
                        $p['errors'][] = self::problem('importrowerror', (object)['line' => $line, 'reason' => self::problem('importteacher', $value)]);
                    }
                    $wanted[$student->id . ':' . $teacher->id] = self::pair((int)$student->id, (int)$teacher->id);
                }
                $p['rows'][] = $row;
            }
            if (!$p['rows']) { $p['errors'][] = self::problem('importempty'); }
        } finally { fclose($stream); }
        $before = self::mappings((int)$cm->id); ksort($wanted);
        $p['additions'] = array_values(array_diff_key($wanted, $before));
        $removed = $mode === 'replace' ? array_diff_key($before, $wanted) : [];
        $p['removals'] = array_values($removed);
        foreach ($DB->get_records('local_reviewworkflow_req', ['cmid' => $cm->id, 'state' => 'claimed'], 'id') as $r) {
            if (isset($removed[$r->studentid . ':' . $r->claimedby])) {
                $p['claims'][] = ['id' => (int)$r->id, 'studentid' => (int)$r->studentid,
                    'reviewerid' => (int)$r->claimedby, 'timemodified' => (int)$r->timemodified];
            }
        }
        $p['fingerprint'] = hash('sha256', json_encode([$cm->id, $idtype, $mode, hash('sha256', $csv), $wanted, $before, $p['claims']]));
        return $p;
    }

    public static function apply(\stdClass $cm, array $p, bool $releaseclaims = false): int {
        global $DB, $USER;
        self::authorise($cm);
        if (($p['actorid'] ?? 0) != $USER->id || ($p['cmid'] ?? 0) != $cm->id) {
            throw new \moodle_exception('forbidden', 'local_reviewworkflow');
        }
        if (($p['created'] ?? 0) < time() - 1800 || ($p['created'] ?? 0) > time()) {
            throw new \moodle_exception('importexpired', 'local_reviewworkflow');
        }
        if (!empty($p['errors']) || empty($p['fingerprint']) || empty($p['nonce'])) {
            throw new \moodle_exception('importinvalid', 'local_reviewworkflow');
        }
        return workflow::locked(function() use ($DB, $USER, $cm, $p, $releaseclaims): int {
            $tokenhash = hash('sha256', $p['nonce']);
            $existing = $DB->get_record('local_reviewworkflow_imp', ['tokenhash' => $tokenhash]);
            if ($existing) {
                if ($existing->actorid != $USER->id || $existing->cmid != $cm->id) { throw new \moodle_exception('forbidden', 'local_reviewworkflow'); }
                return (int)$existing->id;
            }
            $fresh = self::preview($cm, $p['csv'], $p['idtype'], $p['mode']);
            if ($fresh['errors']) { throw new \moodle_exception('importinvalid', 'local_reviewworkflow'); }
            if (!hash_equals($p['fingerprint'], $fresh['fingerprint'])) { throw new \moodle_exception('importstale', 'local_reviewworkflow'); }
            if ($fresh['claims'] && !$releaseclaims) { throw new \moodle_exception('importclaims', 'local_reviewworkflow'); }
            $transaction = $DB->start_delegated_transaction();
            $id = (int)$DB->insert_record('local_reviewworkflow_imp', (object)[
                'cmid' => $cm->id, 'actorid' => $USER->id, 'mode' => $p['mode'], 'idtype' => $p['idtype'],
                'sourcehash' => hash('sha256', $p['csv']), 'tokenhash' => $tokenhash,
                'added' => count($fresh['additions']), 'removed' => count($fresh['removals']),
                'released' => count($fresh['claims']), 'timecreated' => time(),
            ]);
            foreach ($fresh['claims'] as $claim) {
                $DB->update_record('local_reviewworkflow_req', (object)['id' => $claim['id'],
                    'state' => 'pending', 'claimedby' => 0, 'timemodified' => time()]);
                $DB->insert_record('local_reviewworkflow_log', (object)[
                    'requestid' => $claim['id'], 'actorid' => $USER->id, 'action' => 'released', 'timecreated' => time(),
                ]);
                self::change($id, $claim, 'claimreleased', $claim['id']);
            }
            foreach ($fresh['removals'] as $pair) {
                $DB->delete_records('local_reviewworkflow_map', ['cmid' => $cm->id] + $pair);
                self::change($id, $pair, 'removed');
            }
            foreach ($fresh['additions'] as $pair) {
                $DB->insert_record('local_reviewworkflow_map', (object)(['cmid' => $cm->id] + $pair));
                self::change($id, $pair, 'added');
            }
            $transaction->allow_commit(); return $id;
        });
    }

    private static function change(int $importid, array $pair, string $action, int $requestid = 0): void {
        global $DB;
        $DB->insert_record('local_reviewworkflow_chg', (object)[
            'importid' => $importid, 'studentid' => $pair['studentid'], 'reviewerid' => $pair['reviewerid'],
            'action' => $action, 'requestid' => $requestid,
        ]);
    }

    private static function safe_cell(string $value): string {
        return (str_starts_with($value, "'") || preg_match('/^[=+@\-\t\r]/', $value)) ? "'" . $value : $value;
    }

    public static function export(\stdClass $cm, string $idtype): string {
        global $DB;
        self::authorise($cm);
        if (!in_array($idtype, ['email', 'username'])) { throw new \moodle_exception('importinvalid', 'local_reviewworkflow'); }
        $context = \context_module::instance($cm->id); $rows = []; $width = 1;
        foreach (get_enrolled_users($context, 'mod/assign:submit', 0, 'u.*', 'u.lastname, u.firstname', 0, 0, true) as $u) {
            if ($u->suspended || $u->deleted) { continue; }
            $row = [self::safe_cell($u->$idtype)];
            foreach ($DB->get_records('local_reviewworkflow_map', ['cmid' => $cm->id, 'studentid' => $u->id], 'reviewerid') as $m) {
                $teacher = \core_user::get_user($m->reviewerid);
                if ($teacher && !$teacher->deleted) { $row[] = self::safe_cell($teacher->$idtype); }
            }
            $width = max($width, count($row) - 1); $rows[] = $row;
        }
        $out = fopen('php://temp', 'r+'); fwrite($out, "\xEF\xBB\xBF");
        $header = ['student_' . $idtype];
        for ($i = 1; $i <= $width; $i++) { $header[] = 'teacher' . $i . '_' . $idtype; }
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) { fputcsv($out, array_pad($row, $width + 1, ''), ',', '"', ''); }
        rewind($out); $csv = stream_get_contents($out); fclose($out); return $csv;
    }

    public static function history(\stdClass $cm, int $page = 0): array {
        global $DB; self::authorise($cm);
        return $DB->get_records('local_reviewworkflow_imp', ['cmid' => $cm->id], 'id DESC', '*', max(0, $page) * 20, 20);
    }
}
