<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

final class report_filters extends \moodleform {
    public function definition(): void {
        $m = $this->_form;
        $m->addElement('hidden', 'courseid'); $m->setType('courseid', PARAM_INT);
        $m->addElement('select', 'cmid', get_string('assignment', 'local_reviewworkflow'), $this->_customdata['activities']);
        $m->setType('cmid', PARAM_INT);
        $states = ['' => get_string('allstates', 'local_reviewworkflow'),
            'reviewed' => get_string('reviewed', 'local_reviewworkflow'),
            'unreviewed' => get_string('unreviewed', 'local_reviewworkflow')];
        foreach (['pending', 'claimed', 'superseded'] as $state) { $states[$state] = get_string('state_' . $state, 'local_reviewworkflow'); }
        $m->addElement('select', 'state', get_string('status', 'local_reviewworkflow'), $states);
        $options = [];
        foreach (\local_reviewworkflow\report_query::DECISIONS as $decision) { $options[$decision] = get_string($decision, 'local_reviewworkflow'); }
        $m->addElement('select', 'decisions', get_string('filterdecisions', 'local_reviewworkflow'), $options,
            ['multiple' => 'multiple', 'size' => 3]);
        foreach (['student', 'reviewer'] as $field) {
            $m->addElement('text', $field, get_string('filter' . $field, 'local_reviewworkflow'), ['size' => 40, 'maxlength' => 128]);
            $m->setType($field, PARAM_TEXT);
        }
        foreach (['from', 'to'] as $field) {
            $m->addElement('text', $field, get_string('filter' . $field, 'local_reviewworkflow'), ['placeholder' => 'YYYY-MM-DD']);
            $m->setType($field, PARAM_RAW_TRIMMED);
        }
        $m->addElement('advcheckbox', 'history', get_string('includehistory', 'local_reviewworkflow'));
        $m->addElement('static', 'filterhelp', '', get_string('filterhelp', 'local_reviewworkflow'));
        $m->addElement('submit', 'filterbutton', get_string('filter', 'local_reviewworkflow'));
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        try { \local_reviewworkflow\report_query::normalise($data); }
        catch (\moodle_exception $e) { $errors['from'] = get_string('invalidfilters', 'local_reviewworkflow'); }
        return $errors;
    }

    public static function parameters(): array {
        // Moodle's empty multi-select submits this scalar marker instead of an array.
        $raw = $_POST['decisions'] ?? $_GET['decisions'] ?? [];
        if ($raw === '_qf__force_multiselect_submission') { $decisions = []; }
        else {
            if (!is_array($raw)) { throw new \moodle_exception('invalidfilters', 'local_reviewworkflow'); }
            $decisions = optional_param_array('decisions', [], PARAM_ALPHA);
        }
        return ['cmid' => optional_param('cmid', 0, PARAM_INT), 'state' => optional_param('state', '', PARAM_ALPHA),
            'decisions' => $decisions,
            'student' => optional_param('student', '', PARAM_TEXT), 'reviewer' => optional_param('reviewer', '', PARAM_TEXT),
            'from' => optional_param('from', '', PARAM_RAW_TRIMMED), 'to' => optional_param('to', '', PARAM_RAW_TRIMMED),
            'history' => optional_param('history', 0, PARAM_BOOL)];
    }
}
