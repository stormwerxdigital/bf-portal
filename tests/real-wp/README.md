# Real WordPress checks

The stub tests in `tests/` cannot see WordPress itself: its access checks, its
edit screen, TinyMCE, or what a browser really posts. These scripts drive a
real WordPress in a real browser. They are not run by `tests/run.sh`.

## A throwaway WordPress (about two minutes)

1. WordPress 7.1.x (`git clone --depth 1 --branch 7.1.3 https://github.com/WordPress/WordPress.git wp`).
2. SQLite instead of MySQL: copy `packages/plugin-sqlite-database-integration`
   from `github.com/WordPress/sqlite-database-integration` into
   `wp-content/plugins/sqlite-database-integration` (follow its symlinks), and
   its `db.copy` to `wp-content/db.php`, filling in the two placeholders.
3. A `wp-config.php` with `WP_HOME`/`WP_SITEURL` `http://127.0.0.1:8099`, then
   `php -S 127.0.0.1:8099` from the WordPress folder and install by posting to
   `wp-admin/install.php?step=2`. The PHP server dies now and then; restart it.
4. Copy this plugin in without `tests/`, activate it, and wait about three
   seconds after each copy (PHP caches files briefly).
5. Users: `admin`, and one each of `bftd_senior_manager`, `bftd_tutor_manager`,
   `bftd_tutor`, `bftd_client`, all with the password in `BFTD_PASS`. A student
   with the tutor in `_bftd_assigned_staff` and the client in
   `_bftd_client_user_id`; for sessions and activities, one published skill
   (track `t23`) and one published activity.

## save-everything.mjs

    BFTD_STUDENT=<id> BFTD_ACTIVITY=<id> BFTD_SKILL=<id> \
      node tests/real-wp/save-everything.mjs bftd_assessment tutor

Run it for `bftd_assessment`, `bftd_progress`, `bftd_student`, `bftd_session`,
`bftd_activity` and `bftd_skill`, as admin and as a tutor. At 1.148 every
field survives every step, except these, which are rules rather than losses:

- "Also shared with" gains whoever created the record (a new record always does).
- A session subheading left blank is filled with the tutor's name.
- Picking the student on screen fills the title with the student's name when the title is still as it loaded (the test picks the student after typing the title).
- A schedule slot that overlaps another student's is refused, with a warning.
- A second draft session for the same student and date is held as a draft.
- An activity refuses a skill from the other track.

## view-as.mjs

    BFTD_PORTAL='/?page_id=5' node tests/real-wp/view-as.mjs

"View as client" from the Users screen (1.150): the client row has the link
and staff rows do not; the view matches the client's own login exactly; links
keep the view; the page cannot post as the client and WordPress refuses a
request sent around the page; made-up links and staff accounts do nothing;
senior managers have it, tutor managers and tutors do not.
