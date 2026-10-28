<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

/** Shared authorisation checks, including revalidation for scheduled work. */
final class access {
    public static function manager(\stdClass $cm, ?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? $USER->id;
        $context = \context_module::instance($cm->id);
        return has_capability('local/reviewworkflow:manage', $context, $userid)
            && (is_siteadmin($userid) || is_enrolled($context, $userid, '', true));
    }

    public static function eligible(\stdClass $cm, int $studentid, int $reviewerid): bool {
        global $DB;
        $context = \context_module::instance($cm->id);
        if (!(new adapter\assignment())->supports($cm)) { return false; }
        foreach ([$studentid, $reviewerid] as $id) {
            $u = $DB->get_record('user', ['id' => $id, 'deleted' => 0, 'suspended' => 0]);
            if (!$u || !is_enrolled($context, $id, '', true)) { return false; }
        }
        return $studentid !== $reviewerid
            && has_capability('mod/assign:submit', $context, $studentid)
            && has_capability('mod/assign:grade', $context, $reviewerid)
            && has_capability('local/reviewworkflow:review', $context, $reviewerid)
            && get_fast_modinfo($cm->course, $reviewerid)->get_cm($cm->id)->uservisible;
    }

    public static function mapped(\stdClass $cm, int $studentid, int $reviewerid): bool {
        global $DB;
        return self::eligible($cm, $studentid, $reviewerid) && $DB->record_exists('local_reviewworkflow_map',
            ['cmid' => $cm->id, 'studentid' => $studentid, 'reviewerid' => $reviewerid]);
    }
}
