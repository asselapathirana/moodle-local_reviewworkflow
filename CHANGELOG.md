# Changelog

The entries below describe the retained source versions. The plugin remains an
alpha release; deployment-specific validation is required before production use.

## 0.4.0 — 2026-10-29

- Added shared dashboard and export filters for assignment, status, multiple
  decisions, student, actual reviewer and inclusive completion dates.
- Made latest detected submission the default, with earlier versions as separate
  history rows. Latest selection precedes status and decision filtering.
- Added complete filtered CSV downloads and background ZIP export through Moodle
  adhoc tasks, with progress, owner-only access and seven-day package expiry.
- Included an offline searchable/sortable HTML index, individual review PDFs and
  original uploaded files in evidence packages.
- Added transactional evidence snapshots when reviews are completed, retaining
  reviewed files after live submissions change.
- Added a separate export capability, granted to Managers by default and
  configurable through standard Moodle role permissions.
- Extended privacy handling to evidence snapshots and export records/packages.
- Preserved existing review data and customised scan schedules on upgrade.

## 0.3.0 — 2026-10-29

- Added per-assignment request and completion email subjects and bodies.
- Added allowlisted placeholders, synthetic preview and restore-default controls.
- Labelled review links and marked the optional assignment link explicitly.
- Added completion notices naming the completing reviewer, with distinct persistent
  notification identities for each recipient.
- Made completion, audit and completion-notice creation transactional.

## 0.2.1 — 2026-10-28

- Added reviewer mapping CSV import using explicit email or username mode.
- Added preview, confirmation, Add/Replace modes and live validation before apply.
- Added mapping export and import history, including claim-release handling.
- Preserved completed reviews and notification history during mapping changes.
- Corrected reversible escaping of identifiers beginning with an apostrophe.

## 0.1.2 — 2026-10-28

- Added the initial standard Moodle file-assignment review workflow.
- Added per-student mappings to multiple eligible teachers and shared review claims.
- Added decisions, comments, draft/completed state and configurable certification.
- Added course dashboard, manual course checks and scheduled monitoring.
- Added persistent notification accounting and submission-version history.
- Kept native grades, submissions and feedback unchanged.
