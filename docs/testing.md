# Testing and validation

Use an isolated, non-production Moodle installation. The plugin tests create
synthetic courses, users, submissions and files. Some tests deliberately simulate
persistence failures or exercise plugin upgrades within the test database.

## Running the suite

Install the plugin under `local/reviewworkflow` in the development checkout and
configure that checkout's PHPUnit database prefix and separate PHPUnit dataroot.
Moodle's test initialisation creates/resets its test environment. Verify the
configuration before running it.

For the Moodle 5.1 directory layout used for release validation, run these commands
from the Moodle root:

```sh
php public/admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --test-suffix _test.php public/local/reviewworkflow/tests
```

The test suffix matters: the plugin's tests use Moodle's `_test.php` naming.
In a container environment, run the commands inside the application container.
Do not use the operational site's database as the test environment.

## Recorded release validation

Version 0.4.0 passed 62 PHPUnit tests with 298 assertions on:

- Moodle 5.1.8+;
- PHP 8.3.36;
- PostgreSQL 15.18.

The tests cover submission readiness/versioning, access, reviewer mappings,
notification deduplication/uncertainty, claims, certification, shared completion,
CSV import/export, email templates, filtering, evidence snapshots, package
ownership/expiry, privacy handling and additive upgrade preservation.

The query tests include 300 matching history rows and 50-row page boundaries.
The export tests verify that downloads are not limited to a displayed page and
that an earlier completed review is not returned as the latest review merely
because a completed-status filter was selected.

## Browser and background checks

Synthetic browser checks verified:

1. Latest-only, history and decision filters.
2. Full filtered CSV downloads.
3. Preservation and authorised download of an original reviewed file after its
   live submission changes.
4. Export request, actual Moodle adhoc-task execution and owner download.
5. Offline HTML styling, search, combined status/decision filters, sorting and
   relative document links after archive extraction.
6. Default denial of exports to editing and non-editing teachers.
7. Email preview, validation and default restoration without unintended saves or
   message delivery.

A separate 300-review synthetic history package was built under concurrent
builders. It produced one ready archive, with 300 review PDFs, 300 HTML rows and
301 CSV lines including the header. Local elapsed time was approximately 33
seconds; package size was approximately 40 MB before adding any large originals.
The fixture included unavailable historical evidence and a matching current file.
This is a scale check, not a performance guarantee for another installation.

## Deployment checks

Before operational use, validate the installed Moodle/PHP/database combination,
role overrides, file permissions, mail transport and cron. Test the complete
submission-to-review-to-export sequence with synthetic accounts and captured mail.

Turnitin readiness, authenticated report launches, report export and grade
preservation have not been validated by these tests. No Turnitin integration is
included in the current release.
