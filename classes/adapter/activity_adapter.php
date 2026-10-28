<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\adapter;
defined('MOODLE_INTERNAL') || die();

/** Contract implemented by built-in activity adapters. */
interface activity_adapter {
    public function supports(\stdClass $cm): bool;
    public function submissions(\stdClass $cm): \Generator;
}
