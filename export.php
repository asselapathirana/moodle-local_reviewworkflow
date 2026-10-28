<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT); $idtype = required_param('idtype', PARAM_ALPHA);
$cm = \local_reviewworkflow\workflow::cm($cmid); require_login(get_course($cm->course), false, $cm);
$csv = \local_reviewworkflow\importer::export($cm, $idtype);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reviewers-' . $cmid . '-' . $idtype . '.csv"');
header('Cache-Control: private, no-store, no-cache');
header('X-Content-Type-Options: nosniff');
echo $csv;
exit;
