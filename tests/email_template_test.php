<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

final class email_template_test extends \advanced_testcase {
    public function test_defaults_label_links_and_identify_completion(): void {
        $this->resetAfterTest();
        $values = email_template::example_values();
        foreach (['initial', 'completion'] as $kind) {
            $mail = email_template::render(new \stdClass(), $kind, $values);
            $this->assertSame(1, substr_count($mail->body, $values['review_link']));
            $this->assertSame(1, substr_count($mail->body, $values['assignment_link']));
            $this->assertStringContainsString('[Optional:', $mail->body);
            $this->assertStringContainsString($kind === 'initial' ? 'complete review:' : 'View completed review:', $mail->body);
            if ($kind === 'completion') {
                $this->assertStringContainsString($values['completed_by'], $mail->body);
            }
        }
    }

    public function test_personalisation_is_single_pass_and_missing_links_are_appended(): void {
        $this->resetAfterTest();
        $values = email_template::example_values();
        $values['student_name'] = 'Student {reviewer_name}';
        $cfg = (object)['requestsubject' => 'For {student_name}',
            'requestbody' => 'Dear {reviewer_firstname}, {student_name}, {course_name}, {assignment_name}.'];
        $mail = email_template::render($cfg, 'initial', $values);
        $this->assertSame('For Student {reviewer_name}', $mail->subject);
        $this->assertStringContainsString('Student {reviewer_name}', $mail->body);
        $this->assertStringNotContainsString('{course_name}', $mail->body);
        $this->assertStringContainsString($values['review_link'], $mail->body);
        $this->assertStringContainsString('[Optional:', $mail->body);
        $cfg = (object)['completebody' => 'Finished.'];
        $mail = email_template::render($cfg, 'completion', $values);
        $this->assertStringContainsString($values['completed_by'], $mail->body);
    }

    public function test_invalid_templates_are_rejected(): void {
        $this->resetAfterTest();
        foreach ([['requestsubject', "Hello\nBcc: example"], ['requestsubject', str_repeat('a', 256)],
                ['requestbody', ''], ['requestbody', str_repeat('a', 10001)],
                ['requestbody', '{unknown}'], ['requestbody', '{completed_by}'],
                ['requestbody', '{student_name'], ['requestbody', 'student_name}'],
                ['requestbody', '{{student_name}}'],
                ['requestbody', '{review_link} {review_link}']] as [$field, $value]) {
            $this->assertNotEmpty(email_template::validate($field, $value), $field . ': ' . substr($value, 0, 40));
        }
        $this->assertNull(email_template::validate('completebody', 'By {completed_by} at {completed_at}'));
    }

    public function test_subject_values_cannot_inject_newlines(): void {
        $this->resetAfterTest();
        $values = email_template::example_values();
        $values['student_name'] = "Student\r\nName";
        $mail = email_template::render(new \stdClass(), 'initial', $values);
        $this->assertStringNotContainsString("\n", $mail->subject);
        $this->assertStringNotContainsString("\r", $mail->subject);
        $this->assertLessThanOrEqual(255, \core_text::strlen($mail->subject));
    }

    public function test_configuration_form_exposes_templates_and_validates_fields(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = new \local_reviewworkflow\form\configure();
        $data = email_template::defaults() + ['cmid' => 1, 'enabled' => 1,
            'backlog' => 'include', 'instructions' => '', 'certification' => 'Checked files'];
        $this->assertSame([], $form->validation($data, []));
        $data['completebody'] = '{unknown}';
        $errors = $form->validation($data, []);
        $this->assertArrayHasKey('completebody', $errors);
        $html = $form->render();
        foreach (email_template::FIELDS as $field) { $this->assertStringContainsString('name="' . $field . '"', $html); }
        $this->assertStringContainsString('Preview emails', $html);
        $this->assertStringContainsString('Restore default emails', $html);
    }

    public function test_course_and_assignment_values_are_readable_plain_text(): void {
        $this->resetAfterTest();
        $g = $this->getDataGenerator();
        $course = $g->create_course(['fullname' => 'Water & research']);
        $a = $g->create_module('assign', ['course' => $course->id, 'name' => 'Files & analysis']);
        $cm = get_coursemodule_from_instance('assign', $a->id);
        $student = $g->create_user(); $teacher = $g->create_user();
        $r = (object)['id' => 1, 'studentid' => $student->id, 'completedby' => 0, 'timecompleted' => 0];
        $values = email_template::values($cm, $r, $teacher);
        $this->assertSame('Water & research', $values['course_name']);
        $this->assertSame('Files & analysis', $values['assignment_name']);
    }
}
