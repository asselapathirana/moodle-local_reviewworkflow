<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later

defined('MOODLE_INTERNAL') || die();
$tasks = [[
    'classname' => '\local_reviewworkflow\task\scan', 'blocking' => 0,
    'minute' => '17', 'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*',
], [
    'classname' => '\\local_reviewworkflow\\task\\cleanup_exports', 'blocking' => 0,
    'minute' => '43', 'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*',
]];
