<?php
// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
$string['pluginname'] = 'Review Workflow';
$string['reviewworkflow:manage'] = 'Configure review workflows and oversee reviews';
$string['reviewworkflow:review'] = 'Review assigned submissions';
$string['messageprovider:review_request'] = 'Submission review requests';
$string['messageprovider:review_completed'] = 'Submission review completion notices';
$string['taskscan'] = 'Detect submissions and send review requests';
$string['emailsubject'] = 'Review requested: {$a->assignment} ({$a->student})';
$string['emailbody'] = 'Submitted files from {$a->student} are ready for review in {$a->course}: {$a->assignment}. Please open the review page, examine the files and record your assessment. Any one eligible reviewer can complete the shared review.';
$string['template_requestsubject'] = 'Review requested: {assignment_name} ({student_name})';
$string['template_requestbody'] = 'Dear {reviewer_firstname},

Submitted files from {student_name} are ready for review in {course_name}: {assignment_name}.

Please examine the files and record your assessment and certification. Any one assigned teacher can complete the shared review.

{review_link}

{assignment_link}';
$string['template_completesubject'] = 'Review completed: {assignment_name} ({student_name})';
$string['template_completebody'] = 'Dear {reviewer_firstname},

{completed_by} has completed the review of {student_name}\'s submission for {assignment_name} in {course_name}.

Completed: {completed_at}. No further review is required for this submission.

{review_link}

{assignment_link}';
$string['emailreviewlink'] = 'Open submission and complete review:';
$string['emailcompletedlink'] = 'View completed review:';
$string['emailoptionallink'] = 'Optional: open the Moodle assignment page:';
$string['emailcompletedby'] = 'Review completed by {$a}.';
$string['templateinvalidlength'] = 'Enter nonempty text up to {$a} characters. Subjects must be a single line.';
$string['templateinvalidtoken'] = 'Use only the documented placeholders, enclosed in single braces. Link placeholders are allowed only in the message body.';
$string['templateduplicatelink'] = 'Use each link placeholder at most once.';
$string['emailsettings'] = 'Email templates';
$string['templatefield_requestsubject'] = 'Review request subject';
$string['templatefield_requestbody'] = 'Review request message';
$string['templatefield_completesubject'] = 'Review completion subject';
$string['templatefield_completebody'] = 'Review completion message';
$string['templatehelp'] = 'Plain text only. Available placeholders: {reviewer_firstname}, {reviewer_name}, {student_name}, {course_name}, {assignment_name}. Messages also support {review_link} and {assignment_link}. Completion templates additionally support {completed_by} and {completed_at}. Links are automatically labelled; missing links are appended. The assignment link is marked optional. Completion messages always identify the completing teacher. Settings apply to this assignment; changes affect messages not yet sent.';
$string['previewemails'] = 'Preview emails';
$string['restoreemails'] = 'Restore default emails';
$string['emailpreviewheading'] = 'Email preview';
$string['emailpreviewhelp'] = 'Synthetic example values only; links are examples. Nothing has been saved and no email has been sent. Save changes to apply these templates.';
$string['requestemail'] = 'Review request email';
$string['completionemail'] = 'Review completion email';
$string['review'] = 'Review';
$string['privacy:metadata'] = 'Review configuration, reviewer mappings, assessments, notification history and audit records.';
$string['privacy:field'] = 'Information associated with a student or reviewer and their review activity.';
$string['privacy:messages'] = 'Review notifications are passed to Moodle messaging.';
$string['busy'] = 'Another workflow operation is running. Please try again shortly.';
$string['forbidden'] = 'You are not authorised to access this review.';
$string['invalidconfig'] = 'Choose a backlog policy and provide a certification statement.';
$string['unsupported'] = 'Only individual file assignments without group mode are supported in this version.';
$string['invalidreviewer'] = 'Choose active students and eligible teachers from this assignment.';
$string['claimed'] = 'This review is no longer available for this operation. Reload to see its current state.';
$string['stale'] = 'The submitted evidence has changed or is no longer submitted. Wait for the next scan and review the current version.';
$string['decisionrequired'] = 'Choose a valid decision before completing the review.';
$string['certifyrequired'] = 'Confirm the certification statement before completing the review.';
$string['configure'] = 'Configure review workflow';
$string['enabled'] = 'Enabled';
$string['disabled'] = 'Disabled';
$string['backlog'] = 'Existing submissions';
$string['includeexisting'] = 'Include existing submissions in review requests';
$string['excludeexisting'] = 'Exclude existing submissions; monitor new evidence only';
$string['instructions'] = 'Reviewer instructions';
$string['certification'] = 'Certification statement';
$string['defaultcertification'] = 'I certify that I have examined the submitted files and recorded my assessment.';
$string['saved'] = 'Changes saved.';
$string['backlogcount'] = 'Currently submitted file attempts: {$a}. Choose explicitly whether to include them.';
$string['reviewers'] = 'Reviewers';
$string['students'] = 'Students';
$string['teachers'] = 'Eligible teachers';
$string['operation'] = 'Operation';
$string['addreviewers'] = 'Add selected teachers to selected students';
$string['removereviewers'] = 'Remove selected teachers from selected students';
$string['apply'] = 'Apply reviewer mapping';
$string['student'] = 'Student';
$string['reviewer'] = 'Reviewer';
$string['mappinghelp'] = 'Select one or more students and teachers. Any one mapped teacher can complete the shared review. Use add or remove to change mappings in bulk.';
$string['ineligiblereviewer'] = 'Reviewer no longer eligible';
$string['reviewerneeded'] = 'Reviewer needed';
$string['decision'] = 'Decision';
$string['acceptable'] = 'Acceptable';
$string['concerns'] = 'Concerns';
$string['investigate'] = 'Further investigation';
$string['comments'] = 'Comments';
$string['certify'] = 'Certification';
$string['savedraft'] = 'Save draft';
$string['complete'] = 'Complete review';
$string['certificationchanged'] = 'The certification wording has changed. Reload the page, read the current wording and confirm it again.';
$string['deleteduser'] = 'Deleted or unavailable user';
$string['status'] = 'Status';
$string['attempt'] = 'Attempt {$a}';
$string['files'] = 'Submitted files';
$string['historicalevidence'] = 'This evidence version is no longer current. Its original files are not archived by this plugin; current replacement files are not shown as historical evidence.';
$string['completedby'] = 'Completed by {$a}';
$string['claimedby'] = 'Review in progress by {$a}';
$string['startreview'] = 'Start review';
$string['release'] = 'Release review claim';
$string['dashboard'] = 'Review dashboard';
$string['allassignments'] = 'All assignments';
$string['allstates'] = 'All statuses';
$string['assignment'] = 'Assignment';
$string['monitoring'] = 'Monitoring';
$string['schedulehelp'] = 'Configured assignments are checked hourly by Moodle scheduled tasks. Reviewers must be mapped before requests can be emailed.';
$string['filter'] = 'Filter';
$string['notifications'] = 'Notification history';
$string['noreviews'] = 'No review requests match these filters.';
$string['state_pending'] = 'Pending';
$string['state_claimed'] = 'In progress';
$string['state_completed'] = 'Completed';
$string['state_superseded'] = 'Superseded or excluded';
$string['notice_queued'] = 'Queued';
$string['notice_processing'] = 'Processing';
$string['notice_accepted'] = 'Accepted by Moodle messaging';
$string['notice_failed'] = 'Failed';
$string['notice_uncertain'] = 'Uncertain; investigate before any resend';
$string['notice_skipped'] = 'Skipped';
$string['scannow'] = 'Check this course now';
$string['scancomplete'] = 'Course check completed. See the notification history for results.';
$string['importoptions'] = 'Choose Email or Username and Add or Replace.';
$string['importformat'] = 'Use a valid UTF-8 comma-separated CSV file no larger than 1 MiB. Check that quoted fields are closed.';
$string['importheaders'] = 'Expected student and numbered teacher columns, optionally suffixed with _{$a}, matching the selected identifier type.';
$string['importtoomany'] = 'The file exceeds 10,000 student rows.';
$string['importcolumns'] = 'Provide a student identifier and no more cells than the header defines.';
$string['importunknown'] = 'No existing account matches: {$a}';
$string['importambiguous'] = 'More than one account matches: {$a}. Correct the accounts or use usernames.';
$string['importrowerror'] = 'Row {$a->line}: {$a->reason}';
$string['importduplicatestudent'] = 'Student appears in more than one row: {$a}';
$string['importstudent'] = 'Not an active enrolled student eligible to submit: {$a}';
$string['importteacher'] = 'Not an active enrolled teacher eligible to review this student: {$a}';
$string['importduplicate'] = 'Repeated teacher ignored: {$a}';
$string['importempty'] = 'Include at least one student row. Header-only files cannot clear mappings.';
$string['importinvalid'] = 'This import contains errors or no longer has eligible accounts. Create a new preview.';
$string['importstale'] = 'Mappings, account resolution or affected claims changed since preview. Create a new preview before applying.';
$string['importexpired'] = 'This preview has expired. Upload the file again to create a new preview.';
$string['importclaims'] = 'This import removes a teacher holding an active claim. Confirm release of the listed claims before applying.';
$string['importmanualclaim'] = 'This teacher holds an active review claim. Release it from the review page before removing the mapping.';
$string['importreviewers'] = 'Import reviewers from CSV';
$string['importfile'] = 'CSV file';
$string['importidentifier'] = 'Identify accounts by';
$string['importmode'] = 'Import mode';
$string['importaddmode'] = 'Add mappings; keep existing mappings';
$string['importreplacemode'] = 'Replace all mappings for this assignment';
$string['importpreview'] = 'Preview import';
$string['importconfirm'] = 'I confirm the additions and removals shown in this preview.';
$string['importreleaseclaims'] = 'I also confirm release of all active claims listed above.';
$string['importconfirmrequired'] = 'Confirm the preview before applying changes.';
$string['importapply'] = 'Apply import';
$string['importapplied'] = 'Import applied. Mappings and history have been saved.';
$string['importhelp'] = 'Use one student per row and any number of numbered teacher columns (up to 100). For example: student_email,teacher1_email,teacher2_email. Alternatively use student,teacher1,teacher2 and explicitly select Email or Username. Blank teacher cells are ignored. Accounts and course enrolments must already exist. UTF-8 comma-separated CSV only; maximum 1 MiB and 10,000 student rows. Previews expire after 30 minutes. Nothing is applied until you confirm.';
$string['importreplacewarning'] = 'Replace mode covers the entire assignment. All existing mappings absent from this file will be removed, including students omitted from the file. Review every removal below.';
$string['importrow'] = 'CSV row';
$string['import_additions'] = 'Mappings to add';
$string['import_removals'] = 'Mappings to remove';
$string['import_claims'] = 'Claims to release';
$string['importhistory'] = 'Import history';
$string['importfilehash'] = 'Source file fingerprint';
$string['importactor'] = 'Applied by';
$string['importaction_added'] = 'Added mapping';
$string['importaction_removed'] = 'Removed mapping';
$string['importaction_claimreleased'] = 'Released claim';
$string['importexportemail'] = 'Export CSV (emails)';
$string['importexportusername'] = 'Export CSV (usernames)';
