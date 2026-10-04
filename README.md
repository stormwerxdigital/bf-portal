# Brilliant Futures Dashboard

The parent portal and the tutor back end for bftutoring.com. Built on the
Stormwerx Client Dashboard architecture, adapted to a tutoring practice.

Version 1.134.0 · text domain `bftd` · prefix `BFTD_` · meta prefix `_bftd_`

Source of truth: <https://github.com/stormwerxdigital/bf-portal>, branch `main`.
The plugin header carries `GitHub Plugin URI` and `Primary Branch`, so Git
Updater offers an update on every site from a push to that branch. **Bump the
version in both places**, the `Version:` header and `BFTD_VERSION`, on every
change, or nothing is offered the update.

---

## Installing

1. Zip the `bf-tutoring-dashboard` folder and upload it under **Plugins → Add
   New → Upload Plugin**, then activate.
2. Activation creates the roles, the activity log table, and a page called
   **Portal** holding the `[bf_portal]` shortcode. Move or rename that page
   freely; the plugin remembers its ID.
3. Add family accounts as **Client**, tutors as **Tutor**, under Users. Set
   each client's relationship (parent, teacher, adult learner) on their
   profile.
4. **Brilliant Futures → Emails** to review the wording and the send rules
   before anything can go out. Setting **Pause everything** on while you set up
   is the safe way to see what would have sent, without sending it.
5. Set each tutor's terms under their user profile before the first payroll:
   session rate, diagnostic rate, employee or contractor, start date. Until
   somebody has terms they are staff who are not on the payroll, which is the
   state everybody starts in and not an error.

After the first install, updates come through **Git Updater** from the
repository above rather than by uploading a zip.

## The admin experience

wp-admin is skinned in the Brilliant Futures purple and sage: sidebar,
toolbar, buttons, tabs, panels, list tables and the login screen. It is a
skin rather than a rewrite: every rule is a colour, a typeface or a spacing
value, scoped to a core admin class, so a WordPress update can move things around
without breaking a feature. The core colour-scheme picker is removed for
everyone, because a user switching to "Midnight" would only fight it. There is
a switch under Settings to turn the whole skin off.

**Tutors** get a real wp-admin with the WordPress furniture removed: no Posts,
Comments, Tools, Appearance or Plugins. Media and Users stay, because tutors
upload work samples and set up family accounts. The WordPress dashboard
widgets are replaced with one that says what is waiting on them.

**Families** who somehow reach wp-admin are sent to their portal. Profile,
media upload and AJAX stay reachable, because a parent changing their password
and a file upload in a message both need them.

**Everyone** loses the colour-scheme picker, the "Show Toolbar" row and
application passwords (administrators keep those). Each staff member can hide
the Posts, Pages or Media menus from their own profile, and choose whether new
messages email them every time, once a day, or never.

## Settings

One screen with tabs under Brilliant Futures, rather than nine separate pages
scattered under the WordPress Settings menu. Every change is written to the
activity log with what it was before, because "who turned that off" gets asked
about settings more often than about content.

| Tab | What it holds |
|---|---|
| Look and brand | Primary and accent colour, portal logo, branded-admin switch |
| The portal | Which page holds the shortcode, welcome and contact lines, recording window, which screens families see |
| Login page | Login logo and welcome note |
| Portal notice | A dismissable banner for families, with the revision behaviour below |
| Help bar | The line and button at the foot of every portal screen |
| Where messages go | Shared inbox, named recipient, what happens when nobody is assigned, sound |
| Report wording | The standing text a family reads on each report section |
| Pay rates | The practice's default session and diagnostic rates, and what a tutor's own terms fall back to |
| CRM connection | The GoHighLevel link |
| People and access | Read-only: who can see each student's file, who teaches them, when each family last signed in |
| Roles and permissions | Every role against what WordPress actually has stored, and Check one person |

Email wording, send rules and edit history are their own screen under
Brilliant Futures → Emails rather than a tab here.

The portal notice is dismissable per person, and the dismissal sticks until the
wording actually changes, at which point the stored revision bumps and it
comes back for everyone. Re-saving the same words, or toggling it off and on,
does not.

## The portal, for staff

A family opening the portal gets their own. A staff member opening it gets a
**searchable family picker** (type a name, username or email, arrow keys to
move, Enter to open) and from then on the page renders exactly what that
family sees: the same sections, the same wording, the same order.

This is the same mechanism the Stormwerx plugin has as `?preview_project=`:
a staff member opening any one client's dashboard. What that plugin removed,
over three attempts, was the read-only *mode*, not the view: a staff member
never gets a degraded version of themselves. So here they keep full rights on
every screen, and a dark bar across the top says whose view it is and links
straight to the student record, the progress report and the diagnostic, which
is also what makes it self-evident that nothing is locked.

**It is not "log in as".** Anything a staff member posts while looking at a
family's portal posts under their own name, from Brilliant Futures, exactly as
it would from the back end. There is no impersonation, and nothing a family
wrote can be answered as though it came from them. The bar says so in plain
words, because a member of staff should never be uncertain about which of the
two they are doing.

Two limits on the search, and the second is the one that matters:

- Staff only.
- A tutor only ever gets back families attached to a student they are already
  assigned to, so the picker cannot be used to enumerate the practice's client
  list from an account that should not see it. Managers and above see
  everyone, which is the difference their tier is for.

Searching matches the student's name as well as the adult's name, username and
email, which is how a tutor actually thinks about a family. Both searches are
restricted to the same allowed set, so matching on a student name can never
surface a family the searcher could not already reach.

A family with more than one student keeps the student switcher. A tutor
opening a family who also has a child with a different tutor sees only their
own, the picker intersects the family's students with the staff member's.

Opening a family's portal is written to the activity log, once per staff
member per family per day rather than on every page turn, so the log stays
readable while still answering "who has been looking at this child's file".

## Where a family's message goes

The assigned tutor is always told. On top of that: a **shared inbox** that gets
a copy of everything, and optionally **one named person** who gets a personal
copy, the arrangement for a practice where one owner reads everything.

The fallback is the part that matters. A student can have a family writing
before a tutor is assigned, and that message must not fall into a gap because
the record was set up but not staffed. You choose whether an unassigned
student's messages email every administrator, go to the shared inbox only, or
email nobody. **Whatever you pick, the message still appears in Conversations
with a count on the menu**, the setting controls email volume, never whether
anyone finds out.

## Conversations, two ways

**Read and reply** is the two-pane inbox: threads on the left, the
conversation on the right, replying posts straight back into the section the
family asked on.

**Every message** is the flat table: who, what, which section and when, with
search across everything. Answering is a conversation; finding what a parent
said in July is a scan, and a thread list hides that one click deep.

## Priority items and For review

Two lists per student, kept deliberately as two classes with two meta keys.
They are nearly the same feature, and writing one parameterised class is the
mistake to avoid: the wording, the colour and the urgency diverge over time,
and sharing code means every change to one has to be checked against the other.

Each item has stable id (so editing the wording never loses a family's checked
state), the ask in one line, a longer explanation behind a disclosure, an
optional link, and an optional pin to a report section, pinned items only
appear while the family is reading that section, unpinned ones follow them
everywhere. Every item carries its own conversation thread.

**Checking and confirming are two separate steps.** A parent saying "done" and
a tutor agreeing are different facts, and collapsing them loses the one that
matters when something was not actually done. Confirming retires the item from
the family's view; reopening brings it back exactly as they left it, with their
checked state untouched either way.

When a family checks off the last outstanding item, the assigned tutor is told
once, on the click that finishes the list, and never again on a later
check or uncheck.

## What is deliberately not here

The Stormwerx "redirect everyone to the dashboard or login" gate is **not
ported**. Every core route (the login form, password reset, the public site) is left
exactly as WordPress ships it. That feature amplified traffic onto the
one endpoint a hosting rate limit was watching, and turned a config problem
into a password reset outage. The portal is a normal page with a shortcode.

There is also no read-only "preview the family's view" mode for staff. A tutor
opening any screen has full staff rights on it, always, however they got there.

## The rules the code is held to

These are not style preferences. Each one is here because something broke
without it.

**Derived, not stored.** Session numbers, activity labels, skills reached,
missed-session counts, attendance, reading level, reading speed and diagnostic
dates are all computed from the records. None of them is written to a second
place that can disagree with the first. A meta key that caches an answer is a
second source of truth, and the bug it produces is a screen that is right and
a screen beside it that is wrong.

**Money is the one exception, and it is deliberate.** A pay entry is a claim
about a debt, and a debt that recalculates itself is not a debt. Rates are
copied onto an entry when it is made and frozen when it is approved. A tutor's
rate rising in March must not repay February.

**One definition, never two.** `used_count()` is `used_counts()` with one id.
`entry_for()` is `entry_by_ref()` with a post reference. `entries_in()` is
`entries_between()` with a period's edges. When two screens ask the same
question they call the same function, because two copies is how a list of
matches comes to disagree with the list underneath it.

**Batched loading.** `BFTD_CPT::sessions_of()` is the single bulk fetch, primed
with `update_meta_cache()` and `cache_users()`. The tests assert that ten rows
and a hundred rows cost the same number of queries.

**The server decides the initial state.** PHP writes `is-on` on a cancellation
block; the script only follows it changing. Chart tabs and folds ship open.
Anything that depends on a script having run is broken for the tutor whose
script did not.

**Money in integer cents, hours in hundredths of an hour.** No floats between a
settings field and a payslip: `75.10 * 100` is `7509.999…` in binary and
`(int)` of that is 7509.

**A session is a "session" everywhere a person reads.** The post type is still
`bftd_session` because it always was, and renaming a meta key migrates data.
`tests/progress-report.php` scans the source for user-facing strings containing
"lesson" and fails on them, with an allow-list for the internal names that keep
the old word on purpose.

## How it fits together

**`BFTD_Schema` is the spine.** Every section of every report is one entry
there: its id (the same id the front end uses as its DOM anchor), which screen
it appears on, what fields it holds, and whether it carries a conversation.
The admin builder, the parent renderer, the thread binding, the review bar and
the activity log all read that one registry. Adding a section to the family's
dashboard is a schema entry and nothing else, there is no second template to
keep in step, which is what makes "the admin maps to the front end" true by
construction rather than by discipline.

**Post types**

| Type | What it is |
|---|---|
| `bftd_student` | The container. One learner. Everything else points back to it. |
| `bftd_assessment` | One Reading Diagnostic event. Several per student over time. |
| `bftd_progress` | The living Progress Report. |
| `bftd_session` | One session record. Builds the session stream, the activity map and the texts read. |
| `bftd_resource` | Shared library: at-home suggestions and setup videos. |

**Roles**, five tiers:

| Role | Rank | What they do |
|---|---|---|
| **Client** | 0 | Parent, guardian, teacher or adult learner. Sees only the students their account is linked to. |
| **Tutor** | 1 | Teaches. Create and edit on their assigned students, no delete. Adds family accounts. Cannot see another tutor's students, change who is assigned, edit settings or email wording, or read the whole activity log. |
| **Tutor Manager** | 2 | Runs the teaching side. Everything a tutor has, on **every** student, plus assigning tutors, deleting records and the oversight report. Manages tutors and families. Not the settings, the email wording, the practice-wide activity log, pay approval or erasure. |
| **Senior Manager** | 3 | Runs the practice. Everything a manager has, plus **adding and removing managers**, the settings, the email wording, the practice-wide activity log, approving pay and erasing records. |
| **Administrator** | 4 | Everything, including WordPress itself. |

Five capabilities carry the difference:

- `bftd_manage_students`: do the work. Tutor and above.
- `bftd_manage_all`: see and change everything. Manager and above.
- `bftd_manage_team`: hire and fire the manager tier. Senior and above.
- `bftd_erase_records`: destroy a record and its history for good. Senior and
  above.
- `bftd_administer`: run the practice's own machinery: the settings screen,
  the email wording, the full audit log, and **approving pay**. Senior and
  above.

The last two are deliberately not `bftd_manage_all`. A tutor manager runs the
teaching side all day without ever needing to decide what the practice owes
somebody or to erase a child's file, and those are the two actions that cannot
be undone by the person who did them.

Without that first split a tutor could rewrite the email templates every family
receives and read another tutor's students' history. That is not what "staff"
should mean once there is more than one tutor.

### One rule governs who may manage whom

**You may edit, delete or hand out a role strictly below your own rank, never
at or above it.** That single comparison produces the whole table below, and it
means a sixth tier, if there ever is one, is one entry in `tiers()` rather
than a new special case.

Editing and handing out are treated as the same risk on purpose. Being able to
edit an account at your own tier means being able to change its email address
and take it over, which is a sideways route into a tier you are not allowed to
hand out, so "cannot promote to Senior Manager" would mean nothing on its own
if you could simply edit an existing one. And a role that can clone its own
tier is a privilege escalation dressed as a convenience: demote such a person
and they re-promote themselves, so the tier stops meaning anything.

Administrators are exempt, so WordPress's own behaviour is untouched.

The tested matrix:

| Can they… | Admin | Senior Mgr | Tutor Mgr | Tutor |
|---|---|---|---|---|
| Edit an Administrator | yes | no | no | no |
| Edit a Senior Manager | yes | no | no | no |
| Edit a Tutor Manager | yes | yes | no | no |
| Edit a Tutor | yes | yes | yes | no |
| Edit a family account | yes | yes | yes | yes |
| Delete a Tutor Manager | yes | yes | no | no |
| Delete a Tutor | yes | yes | yes | no |
| Grant Administrator | yes | no | no | no |
| Grant Senior Manager | yes | no | no | no |
| Grant Tutor Manager | yes | yes | no | no |
| Grant Tutor | yes | yes | yes | no |
| Grant Client | yes | yes | yes | yes |
| See every student | yes | yes | yes | no |
| Change settings and email wording | yes | yes | no | no |
| Approve pay | yes | yes | no | no |
| Erase a record for good | yes | yes | no | no |
| Read the practice-wide activity log | yes | yes | no | no |
| See a record's own History panel | yes | yes | yes | own students only |

The role dropdown on the Add and Edit User screens offers only what that person
may actually hand out, so a tier they cannot grant is never even shown.

**Settings → Roles and permissions** shows every role and every capability it
should hold, checked against what WordPress actually has stored, the two can
drift when another plugin edits a role or an update stops half way, and when
they do the useful thing is to see it rather than reason about what should have
happened. An Administrator can re-apply every grant from that screen with one
button; it is safe to run at any time and changes nothing else.

The same screen has **Check one person**: pick an account and it resolves every
gate for that exact user through `user_can()`, the same call every screen
makes, so what it reports is what WordPress will actually do rather than what
the code believes it should. It also says whether the account is on one of our roles at
all, which is the answer whenever somebody has been left on plain Subscriber.

Three things make a capability failure unlikely in the first place:

- **`create_` is declared, not inherited.** WordPress derives `create_posts`
  from `edit_posts` unless a post type says otherwise. That works until
  something in the chain disagrees, and the only symptom is "Sorry, you are not
  allowed to access this page" on the Add New screen. Both the post type and
  the roles now name the capability explicitly, so nothing is implicit.
- **Roles are updated in place, never removed and re-added.** `remove_role()`
  deletes the role outright and everyone holding it loses everything until
  `add_role()` runs a few lines later; if anything interrupts the request in
  between, the role is simply gone. Syncing in place has no such window.
- **Healing runs on `init` at priority 1**, before the post types register and
  long before any screen checks a capability, as well as on `admin_init`.

**None of the staff tiers are WordPress administrators.** No plugins, no
themes, no core settings; `manage_options` is never granted to any of them.

## Sessions: the schedule and the bank

Two things sound like they mean the same and do not. **A schedule books a
session. Recording one uses it.** Four numbers, not two:

| | |
|---|---|
| **Purchased** | What the family paid for. Typed in. |
| **Used** | Sessions actually taught, counted from the session records. Never typed. |
| **Booked** | Future occurrences reserved but not taught yet. |
| **Remaining** | Purchased minus used. |

Collapsing booked into used would mean a family that cancels on Monday has
paid for a session nobody gave. Collapsing used into booked would mean a session
squeezed in off-schedule never counts. Keeping them apart is what makes a
cancellation cost nothing and an extra session still count.

Because **used** is counted from the session records rather than typed, it can
never drift from what actually happened. There is one typed number beside it,
an offset for a student who was already partway through when this was
installed.

**Occurrences are generated, not stored.** "Mondays and Wednesdays at four"
plus a start date produces every date on demand. Writing a year of them into
the database would be 150 rows that go stale the moment someone moves the
regular slot. What *is* stored is the short list of deliberate departures:
this one cancelled, that one moved to Thursday.

**Next session is derived**, always the first booked date in the schedule. It
was a text field a tutor typed; it is now impossible for it to be stale after
a reschedule, because nobody maintains it.

The schedule editor is one row per regular slot (a student who comes three
times a week has three rows) with the generated dates underneath refreshing
as you type, so you see what you are booking before you save. Each date can be cancelled
or moved individually, with a reason, and both are logged.

Moving one opens a **month grid**, not a date box. The question a tutor is
actually answering is "when is this child free", and that is a shape on a
calendar rather than a string, so days already carrying a session are marked,
taught and booked are told apart, and a clash raises a note before it is made
rather than after. Weeks start on Monday, which puts a Monday-and-Wednesday
pattern in the same two columns on every row. Typing a date directly still
works and the grid follows it.

`bftd_schedule_occurrences` is the filter GoHighLevel plugs into: it can add
bookings or replace the set entirely without anything else changing shape.

## The sessions and students screens

Both were rebuilt for volume rather than for a demo practice. Each is grouped
into a section per tutor, with search and filtering by student, client, tutor
and caregiver, so the question "what has this tutor got outstanding" is one
screen rather than a sort.

Session titles are generated (`Kaine M · 16 Sep 2026, 9:00 am`) and they are
identifiers rather than titles. A generated title never appears on a family's
report; it exists so a list of three hundred records can be read by a person.

Attendance sits at the top of the session editor, not the bottom, because it is
the first thing a tutor knows and the thing every other number depends on. A
cancellation or a reschedule cannot be published without who and why.

## The oversight report

Managers and above, and a tutor cannot reach it, the capability enforces that
rather than the menu hiding the row, because a hidden row is still a URL.

It measures the paperwork against the schedule rather than against itself. The
schedule says a session was due; the session records say what was written; the
report is the difference. A session written up a fortnight late was written
from memory, and a scheduled hour with nothing against it is an hour nobody can
say happened, which is the one a family will ring about.

Nothing on it is a judgement. It is a list of hours, each with a date and a
name, that somebody can go and look at.

## The two libraries

The numbered activity programme and the skills those activities teach. Tutor
managers and above maintain them; only senior managers and administrators can
erase from them. Tutors read them: the lists open for them with each name
linked to a read-only page (`BFTD_Library_View`), no checkboxes and no bulk
actions, and they point at them from a session. Nothing in either is ever
typed out by hand, because the same thing typed twice is two things on a
family's report.

`BFTD_Library` holds only the picker, and only because the picker is the part
with behaviour: the search, the list of matches, and the difference between
looking at something and taking it. Searching matches numbers as numbers and
words in any order, and ranks the results before the list is cut to eight, so
the best match is on screen rather than alphabetically absent.

The libraries themselves stay separate classes. An activity has a number and a
place in a sequence; a skill does not. Folding them into one parameterised type
would mean every reader having to ask which kind it is.

## Time cards

**Brilliant Futures → Time Cards**, one period and one tutor at a time. For an
approver: what has this tutor done this fortnight, and what am I saying yes to.
For a tutor: what have I got coming, and has anybody looked at it yet.

A tutor sees their own card and nothing else. Not a filter set to them: a
different screen with a different question behind it, the same rows minus every
other person's and minus every control that decides money.

| | |
|---|---|
| **Pay period** | Semi-monthly. The 1st to the 15th, the 16th to the end of the month. Twenty-four a year, which is also the figure the CRA formula asks for. |
| **Key** | `2026-09-A` is the first half of September, and the key is the whole identity: sortable as a string, readable by a person, and unable to drift the way a stored start and end date pair can. |
| **One session, one entry** | The entry is keyed to the session record, so rescheduling a session four times pays a tutor once. What changes is what the entry is *for*: taught, cancelled, moved. |
| **Drafts pay nothing** | An entry is made when a record is published, never when it is saved. A draft is a tutor thinking. |
| **A tutor's terms** | Session rate, diagnostic rate, employee or contractor and start date, kept on their own user record and editable only by somebody who can approve pay. A tutor able to edit their own rate is not a permission model. |

February is why `BFTD_Pay_Period` is a class and not an expression written out
in six places. A period that ends on "the 30th" is wrong twice a year, and
wrong by a day every leap year.

## Statutory holiday pay

Employees only. It is an entitlement under the Employment Standards Act and the
Act is about employees, which is why the classification is recorded on a tutor
in the first place.

`BFTD_Stat_Holidays` writes the eleven BC holidays as **rules, not dates**,
because a list has to be maintained and an unmaintained list is a payroll that
quietly stops paying stat holidays in January of whatever year nobody topped it
up. Eight are arithmetic; Good Friday needs the Easter algorithm. Easter Sunday
and Boxing Day are **not** statutory holidays in British Columbia, which is
exactly the sort of thing that gets copied in from a national list.

Eligibility is thirty days employed and fifteen of the thirty days before the
holiday worked or earning. That second half is a higher bar than it looks for
work paid by the session: a tutor teaching Tuesdays and Thursdays reaches eight
or nine days and does not qualify. The screen shows the working rather than a
bare no, because the first question anybody asks about a no is how it was
arrived at.

What is owed is an average day's pay, wages in the thirty calendar days before
the holiday divided by days worked in them. If they worked the holiday, time and
a half on top, double time past the twelfth hour in the day.

Nothing here pays anybody. It makes pending entries on the time card for an
approver, like everything else.

**This is not tax or payroll advice, and the Act is the authority rather than
this file.** What the code does is apply one reading of it consistently and show
the numbers it used. Deductions are not calculated anywhere yet; see *Still to
build*.

## Accessibility mode

For a staff member with poor eyesight, double vision, or both. It belongs to
one account: switched with **Accessibility mode** on the top bar (on the report
preview it is on the preview's own bar) or the checkbox under Accessibility on
the profile, and both write the same user meta (`_bftd_a11y`). An administrator
can set it for somebody else from their profile. Clients never get it, whatever
is stored. `BFTD_Accessibility` holds all of it.

When it is on, every wp-admin screen, the portal as that person sees it, and
every report they open (real, preview or sample) get the `bftd-a11y` body class
and `assets/css/bftd-a11y.css`:

- Text at least 16.5px. The plugin's own sizes are not written out a second
  time: `BFTD_Accessibility::sizes_css()` reads every px font size out of
  `bftd-admin.css`, `bftd-portal.css` and `bftd-report.css` and repeats it,
  enlarged, behind the body class, so a label added next year is covered
  without anyone remembering this exists. WordPress's own screens are sized in
  the stylesheet.
- Text contrast of 7:1 or better (WCAG AAA): the brand palette, darkened.
- One typeface, Atkinson Hyperlegible, made for low vision readers. The
  alphabet face a child is taught with is kept.
- Nothing faint, nothing half transparent, no shadows, no italics, no motion,
  no letter-spaced capitals. A shadow or a soft edge is a second blurred copy
  of an edge, which is what double vision already produces.
- Links underlined; buttons and fields 44px with a solid 2px edge; a black and
  yellow ring on whatever has focus; row actions that normally appear on hover
  always shown; a taller top bar.
- The visual editors' text is enlarged too, including the ones the script
  builds when a row's notes are opened.

Everything is inside `@media screen`, so printing a report prints the report
the family gets. Nobody else's screens change: for everyone else the only
difference is the switch on the top bar. `tests/accessibility.php` and
`tests/browser/a11y.mjs` check the rules; the second measures contrast, size,
typeface and underlines on the real students list and progress report.

## Mobile

Every screen is expected to work at 390×844 and 360×740, and
`tests/browser/mobile.mjs` is what keeps that true rather than goodwill. Put a
section's mobile rules in that section's own media block, at the end of it:
overrides appended to the end of the stylesheet beat a media query higher up,
and that is the bug this project keeps rediscovering.

## Who can open a report

Everything on a student follows the student. A tutor assigned to a child
(or who created the child's record) opens all of that child's sessions,
progress reports and diagnostics.

A record also carries a tutor list of its own, shown as **Also shared with**.
It only adds people: whoever wrote the record and whoever is listed can open
it even without being on the student. It never takes the student's tutors
away.

- Whoever creates a record is added to its list on the first save.
- Managers, Senior Managers and Administrators open every record regardless.
- The list is a chip list over a hidden multi-select and a search box. The
  select is still what posts, so the form works with JavaScript off.
- Adding someone emails them and puts it in their notifications; both
  directions are written to the activity log.
- Changing the list is for managers, the author, and people already on it.

Clients never reach wp-admin. They see the portal, and only the students
their account is linked to.

## Tests

```
./tests/run.sh      # the whole suite, 77 checks, non-zero exit on failure
```

Three layers, all run by that one script.

| Layer | What it is |
|---|---|
| `tests/*.php` | Plain PHP against `wp-stubs.php`. No WordPress, no dependencies. About sixty files. |
| `tests/*.py` | Static checks. `methods-resolve.py`: every `BFTD_*::` call resolves, every hook callback exists, every class is required. `css-clash.py`: no class means two different things in one stylesheet. |
| `tests/browser/*.mjs` | Playwright against Chromium. Each spec has a `build-*.php` that renders the real code into a fixture. Playwright is resolved through `npm root -g`; do not run `playwright install`. |

A few worth knowing by name:

- `capability-matrix.php`: the full who-can-do-what table, run through the real
  `map_meta_cap` filters rather than asserted.
- `add-new-reachable.php`: every post type still resolves a parent for
  `post-new.php`. This exists because a version shipped where it did not, and
  the test meant to catch it had a hardcoded pass in it.
- `report-access.php`: who can open a report, including the case that matters:
  a tutor assigned to the student but not to that report.
- `pay-ledger.php`, `pay-period.php`, `stat-holidays.php`, `stat-pay.php`: the
  money. Frozen rates, one entry per session, period edges across February and
  leap years, eligibility arithmetic.
- `mobile.mjs`: every screen at two phone widths: sideways scroll, elements
  wider than the viewport, tap targets under 40px, text under 12px, anything
  behind a hover, SVG label sizes and collisions, wrapped tab strips. It opens
  every collapsed panel first, because a closed panel measures as nothing.
  **Add every new screen to its `SCREENS` list.**

### The discipline, which is the part that matters

**Every new rule is mutation-verified.** Write the test, watch it pass, then
deliberately break the code it is about and watch it fail. If it still passes,
the test is decorative. This has caught real problems repeatedly:

- A test for "the skill's description crosses into the activity" passed because
  the fixture handed the script HTML already wrapped in `<p>` tags, which real
  WordPress content never is.
- A test for "a tutor sees only their own time card" passed with the isolation
  removed entirely, because the fixture's second tutor was being filtered out
  for an unrelated reason.
- A test for "the best matches are shown first" passed with the sort removed,
  because the fixture happened to have the best match first in list order.

In each case the fix was to the fixture, not the assertion. **A fixture that
cannot express the failure cannot test for it.**

**Render the page.** Features have been written, tested and shipped doing
nothing here, and only driving a real page found it: chart cards raising a
notice on every render, a tab strip breaking into two rows, row actions
invisible behind a hover. If a change affects something a person looks at,
screenshot it and look at it.

Known failure modes, so they are not rediscovered: stubs that model a different
system than WordPress (a `get_posts` stub ignoring `meta_query` reports every
entry as being in every period); audits that cannot see the failure they exist
for (SVG text has no `offsetParent`, a wrapped tab strip does not overflow,
`getBBox()` ignores transforms, a hidden element is skipped rather than
flagged).

## The empty-field rule

A field a tutor has not filled in is never rendered to a family, and a table
row whose meaningful cells are empty is dropped. That rule lives in exactly one
place, `BFTD_Fields::has_value()`, and applies to every field type, so a
report in progress shows the parts that are written rather than a grid of
blanks. It is about *unfilled data*, not about design: badges, meters and
summary cards all still render, with the values that exist.

## Logging

One table, `{prefix}bftd_activity_log`, holds everything: content edits with a
field-level before and after, conversations, every email sent and every one
withheld by a rule, sign ins and failed sign ins, and access changes. Rows are
never edited and survive the deletion of the record they refer to.

Three views onto the same data:

- **Brilliant Futures → Activity Log**: everything, filterable by category,
  event, student, person or free text.
- **History** panel on every edit screen: that one record.
- **Student hub**: everything across one student's whole file.

## Sign-in timestamps

`BFTD_Access_Log` records every successful login as a numeric timestamp, keeps
a rolling 25-entry history with IP and device per account, and adds a sortable
**Last Login** column to Users showing the date, how long ago, and the lifetime
sign-in count, plus a **Students** column linking each family member to the
children they are attached to.

The sort uses an OR'd `meta_query` rather than a bare `meta_key`, because a
plain `meta_key` sort does an INNER JOIN and silently drops every account that
has never signed in.

## Email

**Wording**: subject, preview text and message per email, with merge tags.
Every edit is kept: who changed it, when, and the before and after, under
Emails → Edit history and in the activity log.

**Send rules**: each email is set to *send automatically*, *hold for a tutor
to review and send*, or *never*. On top of that, per-email conditions:

- only if they have not already read it in the portal
- only the first time, never on a later edit
- only when the session has a recording
- hold and send once a day
- never inside quiet hours

Plus global quiet hours, a per-address daily cap, and a pause switch. An email
held by any rule is logged as withheld with the reason, so "why didn't they get
it" always has an answer.

**The review bar**: when a report changes, the tutor sees a bar on the edit
screen: what changed, the email that would go out, editable inline, send or
dismiss. Nothing on that path ever mails a family by itself.

## Conversations

Threads are WordPress comments with `comment_type = bftd_thread`, one per
section, excluded from every public comment query and from the wp-admin
comments list. The section id lives in comment meta and is validated against
the schema, so a thread can never point at a section that does not exist.

**Brilliant Futures → Conversations** is the staff inbox: every thread from
every section of every student, filtered to what is waiting on a reply.
Replying there posts back into the section it came from, so the family reads
the answer where they asked the question.

Attachments (up to five per message, pasted, dragged or picked) are stored
with their comment and served through a signed, short-lived URL that re-checks
access at download time. These are children's work samples; they are not
sitting on a guessable uploads path.

## Extending

Two filters, both used the way the Stormwerx plugin pair does it: add the hook
when something actually needs it, not before.

- `bftd_sections`: register a new section into the schema; the admin builder
  and the parent renderer both pick it up with no further changes.
- `bftd_email_templates` and `bftd_email_template_defaults`: register a new
  customisable email into the same settings screen as the built-in ones.

## Still to build

In roughly the order the practice will want it.

1. **The pay run.** CRA deduction tables by year (CPP, CPP2, EI, federal and
   BC tax) maintained in Settings, gross to net per tutor per period, 4%
   vacation pay paid out each period for employees, locking a period, pay
   statements, and a register for the accountant. In-plugin deductions were
   chosen deliberately; the standing caution is that nothing in here is payroll
   advice and the first real run should be checked against the CRA calculator
   line by line before anybody is paid from it.
2. **Per-tutor performance statistics.** Sessions delivered, cancellation and
   reschedule rates, write-up timeliness. The other half of what was meant by
   "stats" when statutory holiday pay was asked for first.
3. **Numbering skills.** Skills carry no number anywhere in the data model, so
   they cannot be searched by one. It needs a field and a column on the library
   list; the search already handles numbers once there is one to match.
4. **SMS**, as its own module over GoHighLevel, with CASL consent recorded per
   contact. The send rules already have the shape for it.
5. **The daily digest runner.** The rule and the template exist; the cron that
   gathers and sends it does not.
6. **Sound alerts** on new messages, and the browser-notification permission
   flow.
7. **A support-request type.** Families can ask on any section today, which
   covers most of it, but there is no ticket with a status of its own.

Open questions, which are decisions rather than work:

- **Pay dates.** A period ending the 15th is currently paid *on* the 15th. If
  payroll should run a period behind, that is one line in
  `BFTD_Pay_Period::pay_date`.
- Whether skills get numbers, as above.
