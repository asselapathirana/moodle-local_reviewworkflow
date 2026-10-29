<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

final class configure extends \moodleform {
    public function definition(): void {
        $m = $this->_form;
        $m->addElement('hidden', 'cmid'); $m->setType('cmid', PARAM_INT);
        $m->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_reviewworkflow'));
        $m->setDefault('enabled', 1);
        $m->addElement('select', 'backlog', get_string('backlog', 'local_reviewworkflow'), [
            '' => get_string('choose'), 'include' => get_string('includeexisting', 'local_reviewworkflow'),
            'exclude' => get_string('excludeexisting', 'local_reviewworkflow'),
        ]);
        $m->addRule('backlog', null, 'required');
        if (!empty($this->_customdata['configured'])) { $m->hardFreeze('backlog'); }
        $m->addElement('textarea', 'instructions', get_string('instructions', 'local_reviewworkflow'), ['rows' => 5, 'cols' => 65]);
        $m->setType('instructions', PARAM_TEXT);
        $m->addElement('textarea', 'certification', get_string('certification', 'local_reviewworkflow'), ['rows' => 3, 'cols' => 65]);
        $m->setType('certification', PARAM_TEXT); $m->addRule('certification', null, 'required');
        $m->setDefault('certification', get_string('defaultcertification', 'local_reviewworkflow'));
        $m->addElement('header', 'emailsettings', get_string('emailsettings', 'local_reviewworkflow'));
        $m->addElement('static', 'templatehelp', '', get_string('templatehelp', 'local_reviewworkflow'));
        foreach (\local_reviewworkflow\email_template::defaults() as $field => $default) {
            $subject = str_ends_with($field, 'subject');
            $m->addElement($subject ? 'text' : 'textarea', $field,
                get_string('templatefield_' . $field, 'local_reviewworkflow'),
                $subject ? ['size' => 65] : ['rows' => 8, 'cols' => 65]);
            $m->setType($field, PARAM_RAW);
            $m->setDefault($field, $default);
        }
        $m->addElement('submit', 'previewemail', get_string('previewemails', 'local_reviewworkflow'));
        $m->addElement('submit', 'restoreemail', get_string('restoreemails', 'local_reviewworkflow'));
        $m->registerNoSubmitButton('restoreemail');
        $this->add_action_buttons();
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        foreach (\local_reviewworkflow\email_template::FIELDS as $field) {
            if ($error = \local_reviewworkflow\email_template::validate($field, (string)($data[$field] ?? ''))) {
                $errors[$field] = $error;
            }
        }
        return $errors;
    }

    /** Override only the editable email fields; other submitted settings remain. */
    public function restore_email_defaults(): void {
        $this->_form->setConstants(\local_reviewworkflow\email_template::defaults());
    }
}
