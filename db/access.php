<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later

defined('MOODLE_INTERNAL') || die();
$capabilities = [
    'local/reviewworkflow:export' => [
        'riskbitmask' => RISK_PERSONAL, 'captype' => 'read', 'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
    'local/reviewworkflow:manage' => [
        'riskbitmask' => RISK_PERSONAL, 'captype' => 'write', 'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    'local/reviewworkflow:review' => [
        'riskbitmask' => RISK_PERSONAL, 'captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'teacher' => CAP_ALLOW],
    ],
];
