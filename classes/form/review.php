<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

final class review extends \moodleform {
    public function definition(): void {
        $m = $this->_form;
        $m->addElement('hidden', 'id'); $m->setType('id', PARAM_INT);
        $m->addElement('hidden', 'certificationhash', hash('sha256', $this->_customdata['certification']));
        $m->setType('certificationhash', PARAM_ALPHANUM);
        $m->addElement('select', 'decision', get_string('decision', 'local_reviewworkflow'), [
            '' => get_string('choose'), 'acceptable' => get_string('acceptable', 'local_reviewworkflow'),
            'concerns' => get_string('concerns', 'local_reviewworkflow'),
            'investigate' => get_string('investigate', 'local_reviewworkflow'),
        ]);
        $m->addElement('textarea', 'comments', get_string('comments', 'local_reviewworkflow'), ['rows' => 8, 'cols' => 65]);
        $m->setType('comments', PARAM_TEXT);
        $m->addElement('advcheckbox', 'certified', get_string('certify', 'local_reviewworkflow'),
            s($this->_customdata['certification']));
        $buttons = [
            $m->createElement('submit', 'savedraft', get_string('savedraft', 'local_reviewworkflow')),
            $m->createElement('submit', 'complete', get_string('complete', 'local_reviewworkflow')),
            $m->createElement('cancel'),
        ];
        $m->addGroup($buttons, 'buttons', '', ' ', false);
    }
}
