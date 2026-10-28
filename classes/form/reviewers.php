<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

final class reviewers extends \moodleform {
    public function definition(): void {
        $m = $this->_form;
        $m->addElement('hidden', 'cmid'); $m->setType('cmid', PARAM_INT);
        foreach (['students', 'teachers'] as $kind) {
            $m->addElement('autocomplete', $kind, get_string($kind, 'local_reviewworkflow'),
                $this->_customdata[$kind], ['multiple' => true]);
            $m->setType($kind, PARAM_INT); $m->addRule($kind, null, 'required');
        }
        $m->addElement('select', 'operation', get_string('operation', 'local_reviewworkflow'), [
            'add' => get_string('addreviewers', 'local_reviewworkflow'),
            'remove' => get_string('removereviewers', 'local_reviewworkflow'),
        ]);
        $this->add_action_buttons(true, get_string('apply', 'local_reviewworkflow'));
    }
}
