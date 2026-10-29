<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_reviewworkflow;
defined('MOODLE_INTERNAL') || die();

/** Plain-text, single-pass templates. No expressions or arbitrary user fields. */
final class email_template {
    public const FIELDS = ['requestsubject', 'requestbody', 'completesubject', 'completebody'];

    public static function tokens(bool $completion = false): array {
        $tokens = ['reviewer_firstname', 'reviewer_name', 'student_name', 'course_name',
            'assignment_name', 'review_link', 'assignment_link'];
        return $completion ? array_merge($tokens, ['completed_by', 'completed_at']) : $tokens;
    }

    public static function defaults(): array {
        $defaults = [];
        foreach (self::FIELDS as $field) { $defaults[$field] = get_string('template_' . $field, 'local_reviewworkflow'); }
        return $defaults;
    }

    /** Return a translated field error, or null for a valid template. */
    public static function validate(string $field, string $value): ?string {
        if (!in_array($field, self::FIELDS, true)) { throw new \coding_exception('Unknown email template field'); }
        $subject = str_ends_with($field, 'subject');
        if (!trim($value) || \core_text::strlen($value) > ($subject ? 255 : 10000)
                || ($subject && preg_match('/[\r\n\x00]/', $value))) {
            return get_string('templateinvalidlength', 'local_reviewworkflow', $subject ? 255 : 10000);
        }
        $allowed = self::tokens(str_starts_with($field, 'complete'));
        // Links are body-only. This also avoids URLs appearing in subject headers.
        if ($subject) { $allowed = array_diff($allowed, ['review_link', 'assignment_link']); }
        preg_match_all('/\{([^{}]*)\}/', $value, $matches);
        foreach ($matches[1] as $token) {
            if (!in_array($token, $allowed, true)) { return get_string('templateinvalidtoken', 'local_reviewworkflow'); }
        }
        if (strpbrk(preg_replace('/\{[^{}]*\}/', '', $value), '{}') !== false) {
            return get_string('templateinvalidtoken', 'local_reviewworkflow');
        }
        foreach (['review_link', 'assignment_link'] as $token) {
            if (substr_count($value, '{' . $token . '}') > 1) {
                return get_string('templateduplicatelink', 'local_reviewworkflow');
            }
        }
        return null;
    }

    public static function example_values(): array {
        return ['reviewer_firstname' => 'Alex', 'reviewer_name' => 'Alex Teacher',
            'student_name' => 'Sam Student', 'course_name' => 'Example course',
            'assignment_name' => 'Example file assignment', 'completed_by' => 'Taylor Teacher',
            'completed_at' => '29 October 2026, 14:00',
            'review_link' => (new \moodle_url('/local/reviewworkflow/review.php', ['id' => 0]))->out(false),
            'assignment_link' => (new \moodle_url('/mod/assign/view.php', ['id' => 0]))->out(false)];
    }

    public static function values(\stdClass $cm, \stdClass $request, \stdClass $recipient): array {
        $course = get_course($cm->course);
        $completed = $request->completedby ? \core_user::get_user($request->completedby) : false;
        return ['reviewer_firstname' => $recipient->firstname, 'reviewer_name' => fullname($recipient),
            'student_name' => fullname(\core_user::get_user($request->studentid)),
            'course_name' => trim(html_to_text(format_string($course->fullname), 0, false)),
            'assignment_name' => trim(html_to_text(format_string($cm->name), 0, false)),
            'completed_by' => $completed ? fullname($completed) : get_string('unknownuser'),
            'completed_at' => $request->timecompleted ? userdate($request->timecompleted, '',
                \core_date::get_user_timezone($recipient)) : '',
            'review_link' => (new \moodle_url('/local/reviewworkflow/review.php', ['id' => $request->id]))->out(false),
            'assignment_link' => (new \moodle_url('/mod/assign/view.php', ['id' => $cm->id]))->out(false)];
    }

    public static function render(\stdClass $cfg, string $kind, array $values): \stdClass {
        if (!in_array($kind, ['initial', 'completion'], true)) { throw new \coding_exception('Unknown notification kind'); }
        $prefix = $kind === 'completion' ? 'complete' : 'request';
        $defaults = self::defaults();
        $subject = trim($cfg->{$prefix . 'subject'} ?? '') ?: $defaults[$prefix . 'subject'];
        $body = trim($cfg->{$prefix . 'body'} ?? '') ?: $defaults[$prefix . 'body'];
        foreach (['subject' => $subject, 'body' => $body] as $part => $value) {
            if ($error = self::validate($prefix . $part, $value)) {
                throw new \moodle_exception('invalidconfig', 'local_reviewworkflow', '', null, $error);
            }
        }
        $links = [
            'review_link' => get_string($kind === 'completion' ? 'emailcompletedlink' : 'emailreviewlink',
                'local_reviewworkflow') . "\n" . $values['review_link'],
            'assignment_link' => '[' . get_string('emailoptionallink', 'local_reviewworkflow') . "\n"
                . $values['assignment_link'] . ']',
        ];
        foreach ($links as $token => $labelled) {
            if (!str_contains($body, '{' . $token . '}')) { $body .= "\n\n{" . $token . '}'; }
        }
        if ($kind === 'completion' && !str_contains($body, '{completed_by}')) {
            $body .= "\n\n" . get_string('emailcompletedby', 'local_reviewworkflow', '{completed_by}');
        }
        $replacements = [];
        foreach (self::tokens($kind === 'completion') as $token) {
            $replacements['{' . $token . '}'] = $links[$token] ?? ($values[$token] ?? '');
        }
        $subject = strtr($subject, $replacements);
        $subject = \core_text::substr(preg_replace('/[\r\n\x00]+/', ' ', $subject), 0, 255);
        return (object)['subject' => $subject, 'body' => strtr($body, $replacements)];
    }
}
