<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

/** Exact file evidence, copied transactionally when a review completes. */
final class evidence {
    public static function can_view(\stdClass $request, ?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int)$USER->id;
        $user = \core_user::get_user($userid);
        $cm = get_coursemodule_from_id('assign', $request->cmid);
        if (!$user || $user->deleted || $user->suspended || !$cm) { return false; }
        return has_capability('mod/assign:grade', \context_module::instance($cm->id), $userid)
            && get_fast_modinfo($cm->course, $userid)->get_cm($cm->id)->uservisible
            && (access::manager($cm, $userid) || exporter::allowed($cm, $userid)
                || access::mapped($cm, $request->studentid, $userid));
    }

    public static function capture(\stdClass $request): void {
        if (!empty($request->evidencecaptured)) { return; }
        $evidence = self::files($request);
        if ($evidence['provenance'] !== 'current' || !$evidence['files']) {
            throw new \moodle_exception('stale', 'local_reviewworkflow');
        }
        $fs = get_file_storage();
        foreach ($evidence['files'] as $file) {
            $fs->create_file_from_storedfile(['contextid' => $file->get_contextid(),
                'component' => 'local_reviewworkflow', 'filearea' => 'evidence', 'itemid' => $request->id,
                'filepath' => $file->get_filepath(), 'filename' => $file->get_filename(),
                'userid' => $request->studentid], $file);
        }
        if (!workflow::current($request)) { throw new \moodle_exception('stale', 'local_reviewworkflow'); }
        $request->evidencecaptured = time();
    }

    /** Caller is responsible for request-level authorisation. Never substitutes new evidence. */
    public static function files(\stdClass $request): array {
        $cm = get_coursemodule_from_id('assign', $request->cmid);
        if (!$cm) { return ['files' => [], 'provenance' => 'unavailable']; }
        $fs = get_file_storage(); $context = \context_module::instance($cm->id);
        if (!empty($request->evidencecaptured)) {
            $files = $fs->get_area_files($context->id, 'local_reviewworkflow', 'evidence', $request->id, 'filepath, filename', false);
            return ['files' => $files, 'provenance' => $files ? 'snapshot' : 'unavailable'];
        }
        foreach ((new adapter\assignment())->submissions($cm, (int)$request->studentid) as $s) {
            if ($s->studentid == $request->studentid && hash_equals($request->versionhash, $s->versionhash)) {
                return ['files' => $s->files, 'provenance' => 'current'];
            }
        }
        return ['files' => [], 'provenance' => 'unavailable'];
    }
}
