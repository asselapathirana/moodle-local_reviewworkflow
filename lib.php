<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();

function local_reviewworkflow_extend_navigation_course($navigation, $course, $context): void {
    if (has_any_capability(['local/reviewworkflow:manage', 'local/reviewworkflow:review', 'local/reviewworkflow:export'], $context)) {
        $navigation->add(get_string('pluginname', 'local_reviewworkflow'),
            new moodle_url('/local/reviewworkflow/index.php', ['courseid' => $course->id]), navigation_node::TYPE_CUSTOM);
    }
}

function local_reviewworkflow_extend_settings_navigation($settingsnav, $context): void {
    global $PAGE;
    if ($context->contextlevel != CONTEXT_MODULE || empty($PAGE->cm) || $PAGE->cm->modname !== 'assign') { return; }
    $node = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if ($node && has_any_capability(['local/reviewworkflow:manage', 'local/reviewworkflow:review', 'local/reviewworkflow:export'], $context)) {
        $node->add(get_string('pluginname', 'local_reviewworkflow'),
            new moodle_url('/local/reviewworkflow/index.php', ['courseid' => $PAGE->course->id, 'cmid' => $PAGE->cm->id]), navigation_node::TYPE_SETTING);
    }
}

/** Serve preserved evidence only to staff authorised for this exact submission. */
function local_reviewworkflow_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): bool {
    global $DB;
    if ($context->contextlevel != CONTEXT_MODULE || $filearea !== 'evidence' || !$cm) { return false; }
    require_login($course, false, $cm);
    $id = (int)array_shift($args);
    $request = $DB->get_record('local_reviewworkflow_req', ['id' => $id, 'cmid' => $cm->id]);
    if (!$request || empty($request->evidencecaptured) || !\local_reviewworkflow\evidence::can_view($request) || !$args) { return false; }
    $filename = array_pop($args); $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = get_file_storage()->get_file($context->id, 'local_reviewworkflow', 'evidence', $id, $filepath, $filename);
    if (!$file || $file->is_directory()) { return false; }
    send_stored_file($file, 0, 0, true, $options);
    return true;
}
