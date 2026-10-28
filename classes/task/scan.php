<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\task;
defined('MOODLE_INTERNAL') || die();
final class scan extends \core\task\scheduled_task {
    public function get_name(): string { return get_string('taskscan', 'local_reviewworkflow'); }
    public function execute(): void { \local_reviewworkflow\workflow::scan(); }
}
