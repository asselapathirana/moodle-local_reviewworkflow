<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

/** Persistent review lifecycle. All mutations share a cross-process Moodle lock. */
final class workflow {
    private static function locked(callable $callback) {
        $lock = \core\lock\lock_config::get_lock_factory('local_reviewworkflow')->get_lock('workflow', 10);
        if (!$lock) { throw new \moodle_exception('busy', 'local_reviewworkflow'); }
        try { return $callback(); } finally { $lock->release(); }
    }

    public static function cm(int $id): \stdClass {
        return get_coursemodule_from_id('assign', $id, 0, false, MUST_EXIST);
    }

    public static function configure(\stdClass $cm, string $backlog, string $instructions,
            string $certification, bool $enabled = true): void {
        global $DB;
        if (!access::manager($cm)) { throw new \moodle_exception('forbidden', 'local_reviewworkflow'); }
        if (!in_array($backlog, ['include', 'exclude']) || !trim($certification)) {
            throw new \moodle_exception('invalidconfig', 'local_reviewworkflow');
        }
        if (!(new adapter\assignment())->supports($cm)) {
            throw new \moodle_exception('unsupported', 'local_reviewworkflow');
        }
        self::locked(function() use ($DB, $cm, $backlog, $instructions, $certification, $enabled) {
            $cfg = $DB->get_record('local_reviewworkflow_cfg', ['cmid' => $cm->id]);
            $new = !$cfg;
            $cfg = $cfg ?: (object)['cmid' => $cm->id, 'timecreated' => time()];
            $cfg->backlog = $new ? $backlog : $cfg->backlog;
            $cfg->instructions = $instructions; $cfg->certification = $certification;
            $cfg->enabled = (int)$enabled; $cfg->timemodified = time();
            if ($new) { $cfg->id = $DB->insert_record('local_reviewworkflow_cfg', $cfg); }
            else { $DB->update_record('local_reviewworkflow_cfg', $cfg); }
            if ($new && $backlog === 'exclude') { self::detect($cm, $cfg, true); }
        });
    }

    public static function map(\stdClass $cm, array $students, array $reviewers, bool $remove = false): void {
        global $DB;
        if (!access::manager($cm)) { throw new \moodle_exception('forbidden', 'local_reviewworkflow'); }
        foreach ($students as $s) {
            foreach ($reviewers as $t) {
                if (!$remove && !access::eligible($cm, (int)$s, (int)$t)) {
                    throw new \moodle_exception('invalidreviewer', 'local_reviewworkflow');
                }
            }
        }
        self::locked(function() use ($DB, $cm, $students, $reviewers, $remove) {
            foreach ($students as $s) {
                foreach ($reviewers as $t) {
                    $conditions = ['cmid' => $cm->id, 'studentid' => (int)$s, 'reviewerid' => (int)$t];
                    if ($remove) { $DB->delete_records('local_reviewworkflow_map', $conditions); }
                    else if (!$DB->record_exists('local_reviewworkflow_map', $conditions)) {
                        $DB->insert_record('local_reviewworkflow_map', (object)$conditions);
                    }
                }
            }
        });
    }

    private static function detect(\stdClass $cm, \stdClass $cfg, bool $baseline = false): void {
        global $DB;
        $seen = [];
        foreach ((new adapter\assignment())->submissions($cm) as $s) {
            $r = $DB->get_record('local_reviewworkflow_req', [
                'cmid' => $cm->id, 'studentid' => $s->studentid, 'versionhash' => $s->versionhash,
            ]);
            if (!$r) {
                $r = (object)[
                    'cmid' => $cm->id, 'studentid' => $s->studentid, 'submissionid' => $s->submissionid,
                    'attempt' => $s->attempt, 'versionhash' => $s->versionhash,
                    'state' => $baseline ? 'superseded' : 'pending', 'claimedby' => 0,
                    'decision' => '', 'comments' => '', 'certification' => '', 'certified' => 0,
                    'completedby' => 0, 'timecompleted' => 0, 'timecreated' => time(), 'timemodified' => time(),
                ];
                $r->id = $DB->insert_record('local_reviewworkflow_req', $r);
            }
            $seen[] = $r->id;
            if ($baseline || !in_array($r->state, ['pending', 'claimed'])) { continue; }
            foreach ($DB->get_records('local_reviewworkflow_map', ['cmid' => $cm->id, 'studentid' => $s->studentid]) as $m) {
                if (!access::mapped($cm, $s->studentid, $m->reviewerid)) { continue; }
                $key = ['requestid' => $r->id, 'recipientid' => $m->reviewerid, 'kind' => 'initial'];
                if (!$DB->record_exists('local_reviewworkflow_msg', $key)) {
                    $DB->insert_record('local_reviewworkflow_msg', (object)($key + [
                        'state' => 'queued', 'messageid' => 0, 'error' => '',
                        'timecreated' => time(), 'timemodified' => time(),
                    ]));
                }
            }
        }
        foreach ($DB->get_records('local_reviewworkflow_req', ['cmid' => $cm->id]) as $old) {
            if (in_array($old->state, ['pending', 'claimed']) && !in_array($old->id, $seen)) {
                $old->state = 'superseded'; $old->timemodified = time();
                $DB->update_record('local_reviewworkflow_req', $old);
                self::audit($old->id, 'superseded', 0);
            }
        }
    }

    public static function scan(bool $send = true, int $courseid = 0): void {
        global $DB;
        self::locked(function() use ($DB, $courseid) {
            foreach ($DB->get_records('local_reviewworkflow_cfg', ['enabled' => 1]) as $cfg) {
                $cm = get_coursemodule_from_id('assign', $cfg->cmid);
                if ($cm && (!$courseid || $cm->course == $courseid) && (new adapter\assignment())->supports($cm)) { self::detect($cm, $cfg); }
            }
        });
        if ($send) { self::deliver(null, $courseid); }
    }

    /** Sender injection is used by tests to exercise uncertain processor outcomes. */
    public static function deliver(?callable $sender = null, int $courseid = 0): void {
        global $DB;
        self::locked(function() use ($DB, $sender, $courseid) {
            // No other sender can own processing records while this lock is held.
            if (!$courseid) {
                $DB->set_field('local_reviewworkflow_msg', 'state', 'uncertain', ['state' => 'processing']);
            } else {
                $DB->set_field_select('local_reviewworkflow_msg', 'state', 'uncertain',
                    'state = :state AND requestid IN (SELECT r.id FROM {local_reviewworkflow_req} r
                    JOIN {course_modules} cm ON cm.id = r.cmid WHERE cm.course = :course)',
                    ['state' => 'processing', 'course' => $courseid]);
            }
            foreach ($DB->get_records('local_reviewworkflow_msg', ['state' => 'queued'], 'id') as $n) {
                $r = $DB->get_record('local_reviewworkflow_req', ['id' => $n->requestid]);
                $cm = $r ? get_coursemodule_from_id('assign', $r->cmid) : false;
                if ($courseid && (!$cm || $cm->course != $courseid)) { continue; }
                $cfg = $cm ? $DB->get_record('local_reviewworkflow_cfg', ['cmid' => $cm->id]) : false;
                if (!$r || !$cm || !$cfg || !$cfg->enabled || !in_array($r->state, ['pending', 'claimed'])
                        || !access::mapped($cm, $r->studentid, $n->recipientid) || !self::current($r)) {
                    $n->state = 'skipped'; $n->timemodified = time();
                    $DB->update_record('local_reviewworkflow_msg', $n); continue;
                }
                $n->state = 'processing'; $n->timemodified = time();
                $DB->update_record('local_reviewworkflow_msg', $n);
                try {
                    $message = new \core\message\message();
                    $message->component = 'local_reviewworkflow'; $message->name = 'review_request';
                    $message->userfrom = \core_user::get_noreply_user();
                    $message->userto = \core_user::get_user($n->recipientid);
                    $course = get_course($cm->course);
                    $message->courseid = $course->id; $message->notification = 1;
                    $a = (object)['course' => format_string($course->fullname), 'assignment' => format_string($cm->name),
                        'student' => fullname(\core_user::get_user($r->studentid))];
                    $message->subject = get_string('emailsubject', 'local_reviewworkflow', $a);
                    $url = new \moodle_url('/local/reviewworkflow/review.php', ['id' => $r->id]);
                    $evidence = new \moodle_url('/mod/assign/view.php', ['id' => $cm->id]);
                    $message->fullmessage = get_string('emailbody', 'local_reviewworkflow', $a)
                        . "\n\n" . $url->out(false) . "\n" . $evidence->out(false);
                    $message->fullmessageformat = FORMAT_PLAIN;
                    $message->fullmessagehtml = ''; $message->smallmessage = $message->fullmessage;
                    $message->contexturl = $url->out(false); $message->contexturlname = get_string('review', 'local_reviewworkflow');
                    $id = $sender ? $sender($message) : message_send($message);
                    $n->messageid = $id ? (int)$id : 0;
                    $n->state = $id ? 'accepted' : 'uncertain';
                    $n->error = $id ? '' : 'Moodle did not confirm acceptance; manual investigation required.';
                } catch (\Throwable $e) {
                    $n->state = 'uncertain';
                    // Avoid recording transport payloads, credentials or recipient data in error text.
                    $n->error = 'Message processing interrupted; manual investigation required.';
                }
                $n->timemodified = time(); $DB->update_record('local_reviewworkflow_msg', $n);
            }
        });
    }

    public static function current(\stdClass $r): bool {
        $cm = get_coursemodule_from_id('assign', $r->cmid);
        if (!$cm) { return false; }
        foreach ((new adapter\assignment())->submissions($cm) as $s) {
            if ($s->studentid == $r->studentid && hash_equals($r->versionhash, $s->versionhash)) { return true; }
        }
        return false;
    }

    public static function get_request(int $id): \stdClass {
        global $DB, $USER;
        $r = $DB->get_record('local_reviewworkflow_req', ['id' => $id], '*', MUST_EXIST);
        $cm = self::cm($r->cmid);
        if (!access::manager($cm) && !access::mapped($cm, $r->studentid, $USER->id)) {
            throw new \moodle_exception('forbidden', 'local_reviewworkflow');
        }
        return $r;
    }

    public static function claim(int $id): void {
        global $DB, $USER;
        self::locked(function() use ($id, $DB, $USER) {
            $r = self::get_request($id); $cm = self::cm($r->cmid);
            if (!access::mapped($cm, $r->studentid, $USER->id)) {
                throw new \moodle_exception('forbidden', 'local_reviewworkflow');
            }
            if ($r->state === 'claimed' && $r->claimedby == $USER->id) { return; }
            if ($r->state !== 'pending') { throw new \moodle_exception('claimed', 'local_reviewworkflow'); }
            if (!self::current($r)) { throw new \moodle_exception('stale', 'local_reviewworkflow'); }
            $r->state = 'claimed'; $r->claimedby = $USER->id; $r->timemodified = time();
            $DB->update_record('local_reviewworkflow_req', $r); self::audit($id, 'claimed');
        });
    }

    public static function save(int $id, string $decision, string $comments, bool $complete,
            bool $certified, ?string $certificationhash = null): void {
        global $DB, $USER;
        self::locked(function() use ($id, $decision, $comments, $complete, $certified, $certificationhash, $DB, $USER) {
            $r = self::get_request($id); $cm = self::cm($r->cmid);
            if ($r->state !== 'claimed' || $r->claimedby != $USER->id
                    || !access::mapped($cm, $r->studentid, $USER->id)) {
                throw new \moodle_exception('claimed', 'local_reviewworkflow');
            }
            if (!self::current($r)) { throw new \moodle_exception('stale', 'local_reviewworkflow'); }
            if (!in_array($decision, ['', 'acceptable', 'concerns', 'investigate']) || ($complete && !$decision)) {
                throw new \moodle_exception('decisionrequired', 'local_reviewworkflow');
            }
            if ($complete && !$certified) { throw new \moodle_exception('certifyrequired', 'local_reviewworkflow'); }
            $r->decision = $decision; $r->comments = $comments; $r->timemodified = time();
            if ($complete) {
                $cfg = $DB->get_record('local_reviewworkflow_cfg', ['cmid' => $r->cmid], '*', MUST_EXIST);
                if ($certificationhash !== null && !hash_equals(hash('sha256', $cfg->certification), $certificationhash)) {
                    throw new \moodle_exception('certificationchanged', 'local_reviewworkflow');
                }
                $r->state = 'completed'; $r->certification = $cfg->certification; $r->certified = 1;
                $r->completedby = $USER->id; $r->timecompleted = time();
            }
            $DB->update_record('local_reviewworkflow_req', $r);
            self::audit($id, $complete ? 'completed' : 'draftsaved');
        });
    }

    public static function release(int $id): void {
        global $DB;
        self::locked(function() use ($id, $DB) {
            $r = self::get_request($id);
            if (!access::manager(self::cm($r->cmid))) { throw new \moodle_exception('forbidden', 'local_reviewworkflow'); }
            if ($r->state !== 'claimed') { throw new \moodle_exception('claimed', 'local_reviewworkflow'); }
            $r->state = 'pending'; $r->claimedby = 0; $r->timemodified = time();
            $DB->update_record('local_reviewworkflow_req', $r); self::audit($id, 'released');
        });
    }

    private static function audit(int $requestid, string $action, ?int $actorid = null): void {
        global $DB, $USER;
        $DB->insert_record('local_reviewworkflow_log', (object)[
            'requestid' => $requestid, 'actorid' => $actorid ?? $USER->id, 'action' => $action, 'timecreated' => time(),
        ]);
    }

    public static function visible_requests(int $courseid, int $cmid = 0, string $state = '', int $page = 0): array {
        global $DB, $USER;
        $context = \context_course::instance($courseid);
        if (!is_siteadmin() && !is_enrolled($context, $USER->id, '', true)) { return []; }
        $params = ['course' => $courseid];
        $where = 'cm.course = :course';
        if ($cmid) { $where .= ' AND r.cmid = :cmid'; $params['cmid'] = $cmid; }
        if ($state) { $where .= ' AND r.state = :state'; $params['state'] = $state; }
        // Fetching is capped for the first release; UI exposes pagination.
        $rows = $DB->get_recordset_sql('SELECT r.* FROM {local_reviewworkflow_req} r
            JOIN {course_modules} cm ON cm.id = r.cmid WHERE ' . $where . ' ORDER BY r.id DESC', $params);
        $visible = []; $skip = max(0, $page) * 50;
        try {
            foreach ($rows as $r) {
                $cm = get_coursemodule_from_id('assign', $r->cmid);
                if (!$cm || (!access::manager($cm) && !access::mapped($cm, $r->studentid, $USER->id))) { continue; }
                if ($skip > 0) { $skip--; continue; }
                $visible[] = $r;
                if (count($visible) === 50) { break; }
            }
        } finally { $rows->close(); }
        return $visible;
    }

    /** Counts are computed only over records visible to the current actor. */
    public static function visible_counts(int $courseid, int $cmid = 0): array {
        global $DB, $USER;
        $counts = array_fill_keys(['pending', 'claimed', 'completed', 'superseded'], 0);
        $context = \context_course::instance($courseid);
        if (!is_siteadmin() && !is_enrolled($context, $USER->id, '', true)) { return $counts; }
        $params = ['course' => $courseid];
        $where = 'cm.course = :course';
        if ($cmid) { $where .= ' AND r.cmid = :cmid'; $params['cmid'] = $cmid; }
        $rows = $DB->get_recordset_sql('SELECT r.* FROM {local_reviewworkflow_req} r
            JOIN {course_modules} cm ON cm.id = r.cmid WHERE ' . $where, $params);
        try {
            foreach ($rows as $r) {
                $cm = get_coursemodule_from_id('assign', $r->cmid);
                if ($cm && isset($counts[$r->state])
                        && (access::manager($cm) || access::mapped($cm, $r->studentid, $USER->id))) {
                    $counts[$r->state]++;
                }
            }
        } finally { $rows->close(); }
        return $counts;
    }
}
