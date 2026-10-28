<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Confirmation refers only to the server-side preview, never posted mapping IDs. */
final class import_confirm extends \moodleform {
    public function definition(): void {
        $m = $this->_form;
        $m->addElement('hidden', 'cmid'); $m->setType('cmid', PARAM_INT);
        $m->addElement('hidden', 'token'); $m->setType('token', PARAM_ALPHANUM);
        $m->addElement('advcheckbox', 'confirmed', get_string('importconfirm', 'local_reviewworkflow'));
        if (!empty($this->_customdata['claims'])) {
            $m->addElement('advcheckbox', 'releaseclaims', get_string('importreleaseclaims', 'local_reviewworkflow'));
        }
        $this->add_action_buttons(true, get_string('importapply', 'local_reviewworkflow'));
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (empty($data['confirmed'])) { $errors['confirmed'] = get_string('importconfirmrequired', 'local_reviewworkflow'); }
        if (!empty($this->_customdata['claims']) && empty($data['releaseclaims'])) {
            $errors['releaseclaims'] = get_string('importclaims', 'local_reviewworkflow');
        }
        return $errors;
    }
}
