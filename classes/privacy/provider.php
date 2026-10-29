<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\privacy;
defined('MOODLE_INTERNAL') || die();

/** Privacy metadata, discovery, export and erasure for students and staff. */
final class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        foreach (['cfg' => ['instructions', 'certification', 'requestsubject', 'requestbody', 'completesubject', 'completebody'],
                'map' => ['studentid', 'reviewerid'],
                'req' => ['studentid', 'claimedby', 'completedby', 'decision', 'comments', 'certification', 'timecompleted'],
                'msg' => ['recipientid', 'state', 'messageid', 'timemodified'],
                'log' => ['actorid', 'action', 'timecreated'],
                'imp' => ['actorid', 'mode', 'timecreated'],
                'chg' => ['studentid', 'reviewerid', 'action', 'requestid']] as $table => $fields) {
            $metadata = [];
            foreach ($fields as $field) { $metadata[$field] = 'privacy:field'; }
            $collection->add_database_table('local_reviewworkflow_' . $table, $metadata, 'privacy:metadata');
        }
        $collection->add_subsystem_link('core_message', [], 'privacy:messages');
        return $collection;
    }

    private static function user_cmid_sql(): string {
        return 'SELECT cmid FROM {local_reviewworkflow_map} WHERE studentid = :u1 OR reviewerid = :u2
            UNION SELECT cmid FROM {local_reviewworkflow_req} WHERE studentid = :u3 OR claimedby = :u4 OR completedby = :u5
            UNION SELECT r.cmid FROM {local_reviewworkflow_req} r JOIN {local_reviewworkflow_msg} n ON n.requestid = r.id WHERE n.recipientid = :u6
            UNION SELECT r.cmid FROM {local_reviewworkflow_req} r JOIN {local_reviewworkflow_log} l ON l.requestid = r.id WHERE l.actorid = :u7
            UNION SELECT cmid FROM {local_reviewworkflow_imp} WHERE actorid = :u8
            UNION SELECT i.cmid FROM {local_reviewworkflow_imp} i JOIN {local_reviewworkflow_chg} c ON c.importid = i.id
                WHERE c.studentid = :u9 OR c.reviewerid = :u10';
    }

    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        $list = new \core_privacy\local\request\contextlist();
        $params = ['level' => CONTEXT_MODULE];
        for ($i = 1; $i <= 10; $i++) { $params['u' . $i] = $userid; }
        $list->add_from_sql('SELECT ctx.id FROM {context} ctx WHERE ctx.contextlevel = :level
            AND ctx.instanceid IN (' . self::user_cmid_sql() . ')', $params);
        return $list;
    }

    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
        $ctx = $userlist->get_context();
        if ($ctx->contextlevel != CONTEXT_MODULE) { return; }
        foreach (['studentid', 'reviewerid'] as $field) {
            $userlist->add_from_sql($field, "SELECT $field FROM {local_reviewworkflow_map} WHERE cmid = :cm", ['cm' => $ctx->instanceid]);
        }
        foreach (['studentid', 'claimedby', 'completedby'] as $field) {
            $userlist->add_from_sql($field, "SELECT $field FROM {local_reviewworkflow_req} WHERE cmid = :cm AND $field > 0", ['cm' => $ctx->instanceid]);
        }
        foreach (['msg' => 'recipientid', 'log' => 'actorid'] as $table => $field) {
            $userlist->add_from_sql($field, "SELECT x.$field FROM {local_reviewworkflow_$table} x
                JOIN {local_reviewworkflow_req} r ON r.id = x.requestid WHERE r.cmid = :cm AND x.$field > 0", ['cm' => $ctx->instanceid]);
        }
        $userlist->add_from_sql('actorid', 'SELECT actorid FROM {local_reviewworkflow_imp}
            WHERE cmid = :cm AND actorid > 0', ['cm' => $ctx->instanceid]);
        foreach (['studentid', 'reviewerid'] as $field) {
            $userlist->add_from_sql($field, "SELECT c.$field FROM {local_reviewworkflow_chg} c
                JOIN {local_reviewworkflow_imp} i ON i.id = c.importid WHERE i.cmid = :cm AND c.$field > 0",
                ['cm' => $ctx->instanceid]);
        }
    }

    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist): void {
        global $DB;
        $uid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $ctx) {
            if ($ctx->contextlevel != CONTEXT_MODULE) { continue; }
            $data = new \stdClass();
            $data->mappings = array_values($DB->get_records_select('local_reviewworkflow_map',
                'cmid = :cm AND (studentid = :s OR reviewerid = :r)', ['cm' => $ctx->instanceid, 's' => $uid, 'r' => $uid]));
            $data->reviews = array_values($DB->get_records_select('local_reviewworkflow_req',
                'cmid = :cm AND (studentid = :s OR claimedby = :c OR completedby = :r)',
                ['cm' => $ctx->instanceid, 's' => $uid, 'c' => $uid, 'r' => $uid]));
            $data->notifications = array_values($DB->get_records_sql('SELECT n.* FROM {local_reviewworkflow_msg} n
                JOIN {local_reviewworkflow_req} r ON r.id = n.requestid WHERE r.cmid = :cm AND n.recipientid = :u',
                ['cm' => $ctx->instanceid, 'u' => $uid]));
            $data->audit = array_values($DB->get_records_sql('SELECT l.* FROM {local_reviewworkflow_log} l
                JOIN {local_reviewworkflow_req} r ON r.id = l.requestid WHERE r.cmid = :cm AND l.actorid = :u',
                ['cm' => $ctx->instanceid, 'u' => $uid]));
            $data->imports = array_values($DB->get_records_sql('SELECT i.* FROM {local_reviewworkflow_imp} i
                WHERE i.cmid = :cm AND (i.actorid = :actor OR i.id IN (SELECT importid FROM {local_reviewworkflow_chg}
                WHERE studentid = :student OR reviewerid = :reviewer))',
                ['cm' => $ctx->instanceid, 'actor' => $uid, 'student' => $uid, 'reviewer' => $uid]));
            $data->importchanges = array_values($DB->get_records_sql('SELECT c.* FROM {local_reviewworkflow_chg} c
                JOIN {local_reviewworkflow_imp} i ON i.id = c.importid WHERE i.cmid = :cm
                AND (c.studentid = :student OR c.reviewerid = :reviewer)',
                ['cm' => $ctx->instanceid, 'student' => $uid, 'reviewer' => $uid]));
            \core_privacy\local\request\writer::with_context($ctx)->export_data([], $data);
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel != CONTEXT_MODULE) { return; }
        $DB->delete_records_select('local_reviewworkflow_chg',
            'importid IN (SELECT id FROM {local_reviewworkflow_imp} WHERE cmid = :cm)', ['cm' => $context->instanceid]);
        $DB->delete_records('local_reviewworkflow_imp', ['cmid' => $context->instanceid]);
        $select = 'requestid IN (SELECT id FROM {local_reviewworkflow_req} WHERE cmid = :cm)';
        foreach (['msg', 'log'] as $t) { $DB->delete_records_select('local_reviewworkflow_' . $t, $select, ['cm' => $context->instanceid]); }
        foreach (['req', 'map', 'cfg'] as $t) { $DB->delete_records('local_reviewworkflow_' . $t, ['cmid' => $context->instanceid]); }
    }

    private static function erase(int $uid, int $cmid): void {
        global $DB;
        $DB->delete_records_select('local_reviewworkflow_chg',
            '(studentid = :student OR reviewerid = :reviewer) AND importid IN
            (SELECT id FROM {local_reviewworkflow_imp} WHERE cmid = :cm)',
            ['student' => $uid, 'reviewer' => $uid, 'cm' => $cmid]);
        $DB->set_field('local_reviewworkflow_imp', 'actorid', 0, ['actorid' => $uid, 'cmid' => $cmid]);
        $select = 'requestid IN (SELECT id FROM {local_reviewworkflow_req} WHERE cmid = :cm AND studentid = :u)';
        foreach (['msg', 'log'] as $t) { $DB->delete_records_select('local_reviewworkflow_' . $t, $select, ['cm' => $cmid, 'u' => $uid]); }
        $DB->delete_records('local_reviewworkflow_req', ['cmid' => $cmid, 'studentid' => $uid]);
        $DB->delete_records_select('local_reviewworkflow_map', 'cmid = :cm AND (studentid = :s OR reviewerid = :r)',
            ['cm' => $cmid, 's' => $uid, 'r' => $uid]);
        foreach (['msg' => 'recipientid', 'log' => 'actorid'] as $t => $field) {
            $DB->delete_records_select('local_reviewworkflow_' . $t,
                "$field = :u AND requestid IN (SELECT id FROM {local_reviewworkflow_req} WHERE cmid = :cm)", ['u' => $uid, 'cm' => $cmid]);
        }
        foreach ($DB->get_records('local_reviewworkflow_req', ['cmid' => $cmid]) as $r) {
            $changed = false;
            if ($r->claimedby == $uid) {
                $r->claimedby = 0;
                if ($r->state === 'claimed') { $r->state = 'pending'; }
                $r->comments = ''; $r->decision = ''; $changed = true;
            }
            if ($r->completedby == $uid) {
                $r->completedby = 0; $r->comments = ''; $r->decision = ''; $r->certification = '';
                $r->certified = 0; $changed = true;
            }
            if ($changed) { $DB->update_record('local_reviewworkflow_req', $r); }
        }
    }

    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist): void {
        foreach ($contextlist->get_contexts() as $ctx) {
            if ($ctx->contextlevel == CONTEXT_MODULE) { self::erase($contextlist->get_user()->id, $ctx->instanceid); }
        }
    }

    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
        $ctx = $userlist->get_context();
        if ($ctx->contextlevel != CONTEXT_MODULE) { return; }
        foreach ($userlist->get_userids() as $uid) { self::erase($uid, $ctx->instanceid); }
    }
}
