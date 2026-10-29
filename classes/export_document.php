<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

/** Offline documents contain escaped text and relative, encoded file paths only. */
final class export_document {
    public static function uri(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public static function pdf(array $row, string $path): void {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $pdf = new \pdf(); $pdf->setPrintHeader(false); $pdf->setPrintFooter(true);
        $pdf->SetCreator('Moodle Review Workflow'); $pdf->SetTitle('Review ' . $row['id']);
        $pdf->SetFont('freesans', '', 10); $pdf->AddPage();
        $html = '<h1>Submission review</h1>';
        if ($row['state'] !== 'completed') {
            $html .= '<p><strong>This review is not completed. Recorded decisions and comments are draft assessments.</strong></p>';
        }
        $html .= '<table cellpadding="5">';
        $fields = ['Review ID' => $row['id'], 'Student' => $row['studentname'], 'Course' => $row['coursename'],
            'Assignment' => $row['assignmentname'], 'Moodle attempt' => $row['attempt'] + 1,
            'Submission version' => $row['versionnumber'], 'Status' => get_string('state_' . $row['state'], 'local_reviewworkflow'),
            'Decision' => $row['decision'] ? get_string($row['decision'], 'local_reviewworkflow') : '',
            'Reviewer' => $row['reviewername'], 'Detected' => $row['detecteddate'],
            'Completed' => $row['completeddate'], 'Timezone' => $row['timezone']];
        foreach ($fields as $label => $value) { $html .= '<tr><th width="32%">' . s($label) . '</th><td>' . s((string)$value) . '</td></tr>'; }
        $html .= '</table><h2>Assessment comments</h2><p>' . nl2br(s($row['comments'])) . '</p>'
            . '<h2>Certification</h2><p>' . nl2br(s($row['certification'])) . '</p><h2>Submission files</h2><p>'
            . s(get_string('evidence_' . $row['provenance'], 'local_reviewworkflow')) . '</p><ul>';
        foreach ($row['links'] as $link) {
            $relative = 'files/' . basename($link['path']);
            $html .= '<li><a href="' . s(self::uri($relative)) . '">' . s($link['name']) . '</a></li>';
        }
        $html .= '</ul><p>Open index.html in the extracted export folder to browse the complete package. '
            . 'Historical reviews apply only to their own submission version.</p>';
        $pdf->writeHTML($html); $pdf->Output($path, 'F');
    }

    public static function index(array $rows, \stdClass $job): string {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'self\'; script-src \'self\'; base-uri \'none\'; form-action \'none\'">'
            . '<title>Review evidence export</title><link rel="stylesheet" href="style.css"><script src="table.js" defer></script></head><body><main>'
            . '<header><p class="eyebrow">REVIEW WORKFLOW · EXPORT ' . (int)$job->id . '</p><h1>Review evidence</h1>'
            . '<p><strong>' . s($rows[0]['coursename'] ?? '') . '</strong></p>'
            . '<p>One row per submission version. Extract the ZIP before opening documents. This is a snapshot of the selected results when the export was requested.</p>'
            . '<p>Requested: ' . s(userdate($job->timecreated, '', $rows[0]['timezone'] ?? 'UTC'))
            . ' (' . s($rows[0]['timezone'] ?? 'UTC') . ')</p>'
            . '<p class="criteria">' . s(exporter::describe_filters(json_decode($job->filters, true))) . '</p>'
            . '<p><a href="results.csv" download>Download results as CSV</a></p></header>'
            . '<section class="tools" aria-label="Filter exported results"><label>Search table<input id="search" type="search" placeholder="Student, assignment or reviewer"></label>'
            . '<label>Status<select id="status"><option value="">All statuses</option>';
        foreach (['pending', 'claimed', 'completed', 'superseded'] as $state) {
            $html .= '<option value="' . $state . '">' . s(get_string('state_' . $state, 'local_reviewworkflow')) . '</option>';
        }
        $html .= '</select></label><label>Decision<select id="decision"><option value="">All decisions</option>';
        foreach (report_query::DECISIONS as $decision) {
            $html .= '<option value="' . $decision . '">' . s(get_string($decision, 'local_reviewworkflow')) . '</option>';
        }
        $html .= '</select></label><button id="reset" type="button">Reset filters</button></section>'
            . '<p id="count" role="status">' . count($rows) . ' results</p><div class="table-wrap"><table id="reviews"><caption class="sr-only">Exported review results</caption><thead><tr>';
        $headings = ['Student', 'Assignment', 'Attempt', 'Version', 'Status', 'Decision', 'Reviewer', 'Completed', 'Documents'];
        foreach ($headings as $i => $heading) {
            $html .= '<th scope="col" aria-sort="none"><button type="button" data-col="' . $i . '">' . $heading . ' <span aria-hidden="true">↕</span></button></th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $state = $row['state'];
            $html .= '<tr data-state="' . s($state) . '" data-decision="' . s($row['decision']) . '">'
                . '<td><strong>' . s($row['studentname']) . '</strong><small>Review #' . (int)$row['id'] . '</small></td>'
                . '<td>' . s($row['assignmentname']) . '</td><td>' . ((int)$row['attempt'] + 1) . '</td><td>' . (int)$row['versionnumber'] . '</td>'
                . '<td><span class="badge ' . s($state) . '">' . s(get_string('state_' . $state, 'local_reviewworkflow')) . '</span></td>'
                . '<td>' . ($row['decision'] ? s(get_string($row['decision'], 'local_reviewworkflow')) : '—') . '</td>'
                . '<td>' . s($row['reviewername'] ?: '—') . '</td><td data-sort="' . (int)$row['timecompleted'] . '">'
                . s($row['completeddate'] ?: '—') . '<small>' . s($row['timezone']) . '</small></td><td class="documents">'
                . '<a href="' . s(self::uri($row['pdf'])) . '">Review PDF</a>'
                . '<small class="provenance">' . s(get_string('evidence_' . $row['provenance'], 'local_reviewworkflow')) . '</small>';
            if ($row['links']) {
                $html .= '<details><summary>' . count($row['links']) . ' original file(s)</summary><ul>';
                foreach ($row['links'] as $link) {
                    $html .= '<li><a download href="' . s(self::uri($link['path'])) . '">' . s($link['name']) . '</a></li>';
                }
                $html .= '</ul></details>';
            }
            $html .= '</td></tr>';
        }
        return $html . '</tbody></table></div><p id="empty" hidden>No rows match these table filters.</p>'
            . '<footer>Review Workflow · Files remain in their original formats. A completed review applies only to its own submission version.</footer></main></body></html>';
    }
}
