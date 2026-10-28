<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
function xmldb_local_reviewworkflow_upgrade($oldversion): bool {
    if ($oldversion < 2026102802) {
        upgrade_plugin_savepoint(true, 2026102802, 'local', 'reviewworkflow');
    }
    return true;
}
