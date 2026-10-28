<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();

function local_reviewworkflow_extend_navigation_course($navigation, $course, $context): void {
    if (has_any_capability(['local/reviewworkflow:manage', 'local/reviewworkflow:review'], $context)) {
        $navigation->add(get_string('pluginname', 'local_reviewworkflow'),
            new moodle_url('/local/reviewworkflow/index.php', ['courseid' => $course->id]), navigation_node::TYPE_CUSTOM);
    }
}

function local_reviewworkflow_extend_settings_navigation($settingsnav, $context): void {
    global $PAGE;
    if ($context->contextlevel != CONTEXT_MODULE || empty($PAGE->cm) || $PAGE->cm->modname !== 'assign') { return; }
    $node = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if ($node && has_any_capability(['local/reviewworkflow:manage', 'local/reviewworkflow:review'], $context)) {
        $node->add(get_string('pluginname', 'local_reviewworkflow'),
            new moodle_url('/local/reviewworkflow/index.php', ['courseid' => $PAGE->course->id, 'cmid' => $PAGE->cm->id]), navigation_node::TYPE_SETTING);
    }
}
