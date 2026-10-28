<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later

defined('MOODLE_INTERNAL') || die();
$messageproviders = ['review_request' => [
    'capability' => 'local/reviewworkflow:review',
    'defaults' => ['email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED],
]];
