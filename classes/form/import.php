<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** CSV upload and explicit identifier/mapping modes. */
final class import extends \moodleform {
    public function definition(): void {
        $m = $this->_form;
        $m->addElement('hidden', 'cmid'); $m->setType('cmid', PARAM_INT);
        $m->addElement('filepicker', 'csvfile', get_string('importfile', 'local_reviewworkflow'), null,
            ['maxbytes' => 1048576, 'accepted_types' => ['.csv']]);
        $m->addRule('csvfile', null, 'required');
        $m->addElement('select', 'idtype', get_string('importidentifier', 'local_reviewworkflow'), [
            'email' => get_string('email'), 'username' => get_string('username'),
        ]);
        $m->addElement('select', 'mode', get_string('importmode', 'local_reviewworkflow'), [
            'add' => get_string('importaddmode', 'local_reviewworkflow'),
            'replace' => get_string('importreplacemode', 'local_reviewworkflow'),
        ]);
        $m->setDefault('mode', 'add');
        $this->add_action_buttons(true, get_string('importpreview', 'local_reviewworkflow'));
    }
}
