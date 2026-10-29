<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\task;
defined('MOODLE_INTERNAL') || die();
final class build_export extends \core\task\adhoc_task {
    public function execute(): void { \local_reviewworkflow\exporter::build((int)$this->get_custom_data()->exportid); }
    public function retry_until_success(): bool { return false; }
}
