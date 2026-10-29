<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

/** Shared database-filtered selection for dashboard and export. */
final class report_query {
    public const STATES = ['', 'reviewed', 'unreviewed', 'pending', 'claimed', 'completed', 'superseded'];
    public const DECISIONS = ['acceptable', 'concerns', 'investigate'];

    public static function normalise(array $input): array {
        $decisions = $input['decisions'] ?? [];
        if (!is_array($decisions) || count(array_filter($decisions, 'is_string')) !== count($decisions)) { self::invalid(); }
        $f = ['cmid' => max(0, (int)($input['cmid'] ?? 0)), 'state' => (string)($input['state'] ?? ''),
            'history' => empty($input['history']) ? 0 : 1, 'decisions' => array_values(array_unique($decisions))];
        if (!in_array($f['state'], self::STATES, true) || array_diff($f['decisions'], self::DECISIONS)) { self::invalid(); }
        foreach (['student', 'reviewer'] as $field) {
            $f[$field] = trim((string)($input[$field] ?? ''));
            if (\core_text::strlen($f[$field]) > 128) { self::invalid(); }
        }
        foreach (['from', 'to'] as $field) {
            $f[$field] = (string)($input[$field] ?? '');
            if ($f[$field]) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $f[$field], new \DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d') !== $f[$field]) { self::invalid(); }
            }
        }
        if ($f['from'] && $f['to'] && $f['from'] > $f['to']) { self::invalid(); }
        return $f;
    }

    private static function invalid(): void { throw new \moodle_exception('invalidfilters', 'local_reviewworkflow'); }

    /** A null page streams all matches; permissions are applied before page boundaries. */
    public static function rows(int $courseid, array $input, ?int $page = null, ?int $userid = null,
            bool $exportonly = false): \Generator {
        global $DB, $USER;
        $userid = $userid ?? (int)$USER->id;
        $f = self::normalise($input);
        $ctx = \context_course::instance($courseid);
        if (!is_siteadmin($userid) && !is_enrolled($ctx, $userid, '', true)
                && !has_capability('moodle/course:view', $ctx, $userid)) { return; }
        $allowed = []; $manager = [];
        foreach (get_fast_modinfo($courseid, $userid)->get_instances_of('assign') as $info) {
            if (!$info->uservisible || ($f['cmid'] && $info->id != $f['cmid'])) { continue; }
            $cm = workflow::cm($info->id);
            if ($exportonly) {
                if (!exporter::allowed($cm, $userid)) { continue; }
                $manager[$cm->id] = true;
            } else if (access::manager($cm, $userid) || exporter::allowed($cm, $userid)) { $manager[$cm->id] = true; }
            else if (!has_capability('local/reviewworkflow:review', \context_module::instance($cm->id), $userid)) { continue; }
            $allowed[$cm->id] = $cm;
        }
        if (!$allowed) { return; }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($allowed), SQL_PARAMS_NAMED, 'cm');
        $where = ['r.cmid ' . $insql];
        if (!$f['history']) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM {local_reviewworkflow_req} newer
                WHERE newer.cmid = r.cmid AND newer.studentid = r.studentid AND newer.id > r.id)';
        }
        if ($f['state']) {
            $states = $f['state'] === 'reviewed' ? ['completed']
                : ($f['state'] === 'unreviewed' ? ['pending', 'claimed'] : [$f['state']]);
            [$sql, $p] = $DB->get_in_or_equal($states, SQL_PARAMS_NAMED, 'st'); $params += $p;
            $where[] = 'r.state ' . $sql;
        }
        if ($f['decisions']) {
            [$sql, $p] = $DB->get_in_or_equal($f['decisions'], SQL_PARAMS_NAMED, 'dc'); $params += $p;
            $where[] = 'r.decision ' . $sql;
        }
        foreach (['student' => 's', 'reviewer' => 'v'] as $field => $alias) {
            if (!$f[$field]) { continue; }
            $name = $DB->sql_concat("$alias.firstname", "' '", "$alias.lastname", "' '", "$alias.username");
            $where[] = $DB->sql_like('LOWER(' . $name . ')', ':' . $field, false);
            $params[$field] = '%' . $DB->sql_like_escape(\core_text::strtolower($f[$field])) . '%';
        }
        $timezone = new \DateTimeZone(self::timezone($userid));
        foreach (['from' => '>=', 'to' => '<'] as $field => $op) {
            if (!$f[$field]) { continue; }
            $date = new \DateTimeImmutable($f[$field], $timezone);
            if ($field === 'to') { $date = $date->modify('+1 day'); }
            $where[] = 'r.timecompleted > 0 AND r.timecompleted ' . $op . ' :' . $field;
            $params[$field] = $date->getTimestamp();
        }
        $sql = 'SELECT r.*, a.name AS assignmentname,
            (SELECT COUNT(1) FROM {local_reviewworkflow_req} older WHERE older.cmid = r.cmid
              AND older.studentid = r.studentid AND older.id <= r.id) AS versionnumber
            FROM {local_reviewworkflow_req} r JOIN {course_modules} cm ON cm.id = r.cmid
            JOIN {assign} a ON a.id = cm.instance
            LEFT JOIN {user} s ON s.id = r.studentid
            LEFT JOIN {user} v ON v.id = CASE WHEN r.completedby > 0 THEN r.completedby ELSE r.claimedby END
            WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC';
        $records = $DB->get_recordset_sql($sql, $params);
        $skip = $page === null ? 0 : max(0, $page) * 50; $shown = 0;
        try {
            foreach ($records as $r) {
                if (empty($manager[$r->cmid]) && !access::mapped($allowed[$r->cmid], $r->studentid, $userid)) { continue; }
                if ($skip) { $skip--; continue; }
                yield $r;
                if ($page !== null && ++$shown === 50) { break; }
            }
        } finally { $records->close(); }
    }

    public static function data(\stdClass $r, int $userid): array {
        $student = \core_user::get_user($r->studentid);
        $reviewer = \core_user::get_user($r->completedby ?: $r->claimedby);
        $cm = workflow::cm($r->cmid); $course = get_course($cm->course);
        $timezone = self::timezone($userid);
        return (array)$r + ['studentname' => $student ? fullname($student) : get_string('deleteduser', 'local_reviewworkflow'),
            'reviewername' => $reviewer ? fullname($reviewer) : '',
            'coursename' => trim(html_to_text(format_string($course->fullname), 0, false)),
            'timezone' => $timezone, 'completeddate' => $r->timecompleted ? userdate($r->timecompleted, '', $timezone) : '',
            'detecteddate' => userdate($r->timecreated, '', $timezone)];
    }

    private static function timezone(int $userid): string {
        global $USER;
        return \core_date::get_user_timezone($userid == $USER->id ? $USER : \core_user::get_user($userid));
    }
}
