# Review Workflow for Moodle

Version **0.3.0**, an alpha release of `local_reviewworkflow`.

Customisable request/completion emails, placeholder validation, preview and completion notices.

The plugin supports staff review of standard individual Moodle file assignments.
Multiple teachers may be assigned to a student; one completed review satisfies
the shared requirement. Native grades, submissions and feedback remain unchanged.

## Requirements and installation

Requires Moodle 5.1 or later. Install the source in `local/reviewworkflow` and
complete Moodle's normal plugin installation/upgrade. Moodle cron and working
site messaging are required. Group/team submissions, online-text evidence and
Turnitin are outside the implemented integration.

## Use

Open Review Workflow in a course, explicitly configure an assignment, map eligible
teachers to students and choose whether to include existing submissions. Teachers
claim a review, examine files, record an assessment and confirm certification.

## Tests

Tests use synthetic Moodle PHPUnit fixtures. Run them only in an isolated
non-production test environment, with a separate PHPUnit database and dataroot.

## Author and licence

Developed by Assela Pathirana. Copyright © 2026 Assela Pathirana.
Licensed under [GNU GPL, version 3 or later](LICENSE).
