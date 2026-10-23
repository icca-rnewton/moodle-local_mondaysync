# local_mondaysync

Syncs columns on a Monday.com board into Moodle user profile fields, one-way
(Monday.com -> Moodle), on a scheduled poll. Users are matched by comparing
their Moodle **ID Number** profile field against a column on the Monday
board that holds the same value (not email, not Monday's own user IDs).

## How it works

A scheduled task (default: every 15 minutes) fetches every item on the
configured board, along with all of its column values. For each item:

1. It reads the value of the "matching column" and looks up a Moodle user
   with that value in their ID Number field.
2. If a user is found, it checks each mapped column against the last value
   it saw for that item/column (stored in `local_mondaysync_cache`).
3. If the value has changed, it updates the mapped Moodle profile field and
   writes an entry to the sync log (`local_mondaysync_log`, viewable under
   Site administration > Plugins > Local plugins > Monday.com Sync log).

## Setup

1. Install the plugin as usual (upload the zip via
   Site administration > Plugins > Install plugins, or extract into
   `local/mondaysync`).
2. In Monday.com, generate a personal API token: profile picture (top right)
   > Developers > My access tokens (or Admin > API depending on your
   account type).
3. Find your board ID - it's the numeric ID in the board's URL, e.g.
   `https://yourteam.monday.com/boards/1234567890` -> `1234567890`.
4. In Moodle, go to Site administration > Plugins > Local plugins >
   Monday.com Sync and fill in and save the **API token**, **API version**
   (defaults to 2026-01 - check developer.monday.com if syncing starts
   failing, Monday revs this periodically), and **Board ID**.
5. Now go to Site administration > Plugins > Local plugins >
   **Monday.com column mapping wizard**. It fetches the board's columns
   live and shows a dropdown per column so you can pick, for each one,
   which Moodle field it should sync to (or leave it as "Do not sync") -
   plus a dropdown at the top to choose the matching column. No need to
   know column IDs or hand-write the mapping syntax; this writes the same
   underlying settings for you. Standard Moodle fields and your site's
   actual custom profile fields (including their type) are listed by
   name, and a custom field of type **Date/time** is automatically
   treated as a date - Monday's date text is converted to a Unix
   timestamp before saving, matching what `profile_field_datetime`
   expects. Clearing a date on Monday clears the Moodle field too; if a
   date value can't be parsed, that one update is skipped and logged
   rather than silently applied.
6. Check Site administration > Server > Scheduled tasks to confirm
   "Sync Monday.com board to Moodle profile fields" is enabled, and adjust
   the frequency if 15 minutes is too often/infrequent for your board size
   and Monday API rate limits.

### Advanced: editing the mapping by hand

The wizard just writes two plain settings on the main settings page -
**Matching column ID** and **Field mappings** - so you can still edit
them directly if you prefer (e.g. scripting a bulk change). The field
mappings format is one mapping per line:
```
monday_column_id => standard:fieldname
monday_column_id => custom:shortname
monday_column_id => date:shortname
```
e.g.
```
text_mkr4pz9k => custom:favouritecolour
status_col_id => standard:department
date_mkr8x2 => date:startdate
```
Anything you set this way shows up correctly pre-selected next time you
open the wizard, as long as the value matches an option the wizard
offers (a safe standard-field whitelist plus your actual custom fields).

## Grade sync

A board can also sync grades with one course's gradebook.

- **Link the board to a course** in its board settings (Connected Boards >
  Edit > Course). A course can be linked to only one board. Duplicating a
  course for a new run doesn't copy the board: add a new board for the new
  course.
- **Grade items are linked by their ID number**, not their name, so renaming
  an item doesn't break the link. A manual item's ID number is set in
  Gradebook setup (Edit > ID number); an activity's grade uses the
  activity's own ID number (activity settings > Common module settings >
  ID number); a category total's is set when editing the category. The
  mapping wizard lists the course's items under "Grades", and explains any
  it can't use (no ID number, an ID number shared by two items, one
  containing a colon or longer than 100 characters, or an item with no
  grade, such as a Text or None grade type).
- **Directions.** Monday.com can only write into **manual** grade items with
  no calculation. Activity grades, category and course totals, calculated
  items and outcomes are Moodle -> Monday only - writing into an activity's
  grade would mark it overridden, after which the activity's own grading no
  longer reaches the gradebook. This is enforced on the server, both when a
  mapping is saved and again before every write.
- **Column types.** Use a Text column for any grade, a Numbers column for
  points, or a Status column for a scale grade. For a Status column, name
  the status options exactly as the scale's labels (including capitals):
  the plugin writes the status by its label and never creates new options,
  so a label the column doesn't have is rejected by Monday.com and logged.
  Reading back, a status matches a scale label ignoring case.
- **Values.** Points are written to Monday at the grade item's own display
  decimal places ("Grade display decimal points"), exactly as the gradebook
  shows them, with "." as the decimal point. A scale grade is written as its
  label (for example "Merit"). No grade means an empty cell. A Monday
  formula column can turn points into a percentage.
- **Pulling from Monday.com.** The cell must hold a plain number within the
  item's minimum and maximum (Moodle itself would silently clip anything
  outside), or one of the scale's labels (ignoring case). Anything else is
  refused and logged. A value with more decimals than the item displays is
  stored rounded to the item's decimals, so Moodle and Monday show the same
  grade. A blank cell leaves the grade alone unless "Allow clearing" is
  ticked for that column. Locked grades are left unchanged and logged.
  Grades are written through Moodle's grades API, so the gradebook history
  records them and the usual "user graded" event fires.
- **Who entered it.** The gradebook history records pulled grades as the
  account named in the "Grade sync account" setting (for example a
  dedicated "Monday.com Sync" account with the No login authentication
  method and no roles), or the site administrator if that's blank or the
  account can't be found (a warning is logged).
- **Who is synced.** Only learners with an active enrolment in the linked
  course; anyone else on the board still has their profile fields synced,
  and their grade columns are skipped (logged once per row). Hidden grades
  are synced like any other - the board is assumed to be visible to staff
  only.
- **Totals.** If Moodle has flagged the course as needing its grades
  recalculated, that happens at the start of the board's sync, so totals
  aren't stale. If the recalculation reports errors, that board's grade
  columns are skipped for the run and the error is logged. A total that
  depends on a grade just pulled from Monday catches up on the next poll.
- **Privacy.** Grade values appear in the sync log and the sync cache, which
  are exported and erased like the rest. Grades written into the gradebook
  belong to Moodle's own grades subsystem.


- **Orphaned mappings (deleted field or column):** if a mapped Moodle
  custom field or Monday.com column is later deleted, that mapping stops
  applying but isn't silently discarded:
  - In the mapping wizard, a deleted Moodle field shows on its column's
    row as "⚠ Deleted: ..." rather than quietly resetting to "Do not
    sync" - pick a new target or explicitly clear it.
  - A deleted Monday column gets its own "Mappings for columns no longer
    on this board" section, with a "Remove this mapping" checkbox rather
    than just vanishing from view while staying in the saved config.
  - If the **matching column itself** is deleted, syncing for that board
    pauses with one clear error in the log, rather than every row on the
    board logging a confusing "no ID Number found".
  - The sync log gets one summary "warning" entry per run if any
    mappings are currently orphaned (not one per affected row), and the
    Connected Boards list shows a ⚠ next to any board with an issue -
    hover it for details.

- **One-way mappings genuinely enforce one-way.** Monday → Moodle and
  Moodle → Monday compare the live value on both sides every poll and
  correct any mismatch - it doesn't matter whether the "source" side
  actually changed, or the "destination" side drifted on its own (e.g.
  someone edited it by hand). Only "Both ways" tracks history via the
  sync cache, since it genuinely needs to tell a real conflict apart from
  one side simply catching up to the other's last change.
- **Text area custom fields are converted from HTML to plain text** before
  being pushed to Monday.com (a "Text area" field is backed by Moodle's
  rich text editor and stores HTML internally) - otherwise the literal
  `<p>`/`<br>` markup etc. would land in Monday's column.
- **Suspended field labels:** the Advanced "Account suspended" field reads
  several common phrasings automatically from Monday (Yes/No, 1/0,
  True/False, Suspended/Not Suspended) - no configuration needed for
  that direction. Writing *to* Monday needs to know your board's actual
  wording though, so each board has its own "label when suspended" /
  "label when not suspended" text fields (defaulting to "Suspended" /
  "Not Suspended") in the mapping wizard.
- **Moodle Workplace multi-tenancy (optional, only shown if that plugin
  is installed):** new accounts can be assigned to a tenant as part of
  creation, before anything else - including the welcome email, so its
  content reflects the correct tenant from the start. A per-row Monday
  column (matched against tenant names) can override a board-level
  default tenant; if neither resolves, Workplace's own default tenant
  behaviour applies unchanged. Implemented using Workplace's own
  documented API (`\tool_tenant\manager::allocate_user()`), not direct
  database access - Workplace's own README explicitly warns against that,
  since the schema isn't a supported external interface, and using the
  real API also ensures the proper `tenant_user_created` event fires
  (which anything Workplace itself hangs off tenant assignment, such as
  tenant-branded behaviour, likely depends on).
- **Manual-auth welcome email (plugin-created accounts only).** An account
  this plugin created gets a new-password welcome email when it is on
  `manual` auth - at creation, or later if its auth is switched to
  `manual` (e.g. created as `nologin` for a staged workflow). Accounts the
  plugin did not create are never touched: a row in
  `local_mondaysync_manual_email` is the marker for "created by this
  plugin", and without one no password is ever reset. Each account is
  emailed only once, tracked permanently (not subject to the log retention
  purge).

  Moodle resets the password *before* it tries to send, so a send that
  fails, or is interrupted part-way, leaves an account whose password
  nobody has been told. The marker therefore records each attempt before
  it starts. On later syncs, an unfinished attempt is retried
  automatically for accounts that are still on `manual` auth, not
  suspended, and have never been used at all (checked across every
  login/access field - anyone who has been in already has a working
  password, which a reset would break) - at most 3 attempts in total, no
  more than once an hour. If those run out, the plugin stops, posts a note
  on the Monday item and logs it, so a site admin can reset the account
  themselves. Upgrading from an earlier version seeds the marker for
  plugin-created accounts that are still unused, identified from the sync
  log (so only accounts created within the log retention period).
- **Deleted-account detection (creation-enabled boards only):** if a row's
  status reads "User created" but no matching Moodle account can be
  found, that's treated as a failure rather than silent inaction - the
  account was almost certainly deleted directly in Moodle (this plugin
  never deletes accounts itself). The status flips to "Error" and an
  update is posted on the item explaining what happened, using the same
  mechanism as an ordinary creation failure - including the same
  anti-repeat property (it won't re-flag on every subsequent poll, only
  once, until someone resets the status).
- **Creation failures post an update on the item.** As well as flipping
  the trigger column to "Error" and logging it in Moodle, a failed
  creation attempt now posts a note in that item's Monday.com
  activity/conversation feed explaining why - visible directly on the
  board without needing Moodle access, and Monday's own notifications
  pick it up automatically for anyone subscribed to that item. This is
  specifically for creation failures (which never repeat until someone
  manually retries) rather than every kind of sync error, to avoid an
  unfixed ongoing field-sync issue posting a duplicate update every poll.
- **User creation (optional, per board):** a Status column on the board
  can trigger creating a new Moodle account for a row with no existing
  match - set it to "Create user", the account's created on the next
  sync, and the column flips to "User created" (or "Error", with the
  reason in the sync log, if something's wrong - missing data, a
  duplicate username/email, etc). Requires dedicated column mappings for
  firstname, lastname, email, and username (configured in the mapping
  wizard's "User creation" section), plus a default authentication
  method for brand-new accounts. There's deliberately no "delete" option
  in this mechanism - only creation - so an irreversible action always
  has a human as the final decision-maker, made elsewhere (e.g. suspending
  via the Advanced field first, then manually removing the account when
  someone's certain). If the trigger column is later found reading
  anything other than "User created" for a row that already has a
  matched account, it's automatically corrected back - resetting it
  never triggers a duplicate account, since matching is always checked
  first.
- **Advanced fields:** a third mapping category, alongside Standard and
  Custom, for core account properties rather than ordinary profile data.
  `auth` (authentication method) and `suspended` (blocks login) can be
  synced in any direction; they genuinely control login access, so they
  are clearly labelled with a warning in the wizard. `auth` needs the
  Monday column's value to exactly match an enabled auth plugin's
  shortname (e.g. "manual", "nologin") - anything else effectively breaks
  that account's login. `username`, `confirmed`, `policyagreed`,
  `currentlogin` (shown as **Last login**) and `timecreated` are
  **Moodle → Monday only**, as are the
  Standard fields **email** and **ID number**: they show on the board but
  nothing on Monday can change them in Moodle. (Email decides where
  password resets go, ID number is the key used to match rows to accounts,
  username can change which external login resolves to an account, and
  writing `confirmed` or `policyagreed` would bypass Moodle's own
  confirmation and policy-acceptance records.) This is enforced on the
  server, not just by the wizard. A mapping saved before a field was
  restricted is ignored and reported in the sync log until it is re-saved.
  **Last login** is Moodle's `currentlogin` - the time of the account's
  most recent login, whichever sign-in method was used. Moodle's own
  `lastlogin` field is deliberately not offered: it holds the *previous*
  login, so it stays 0 after an account's first login and otherwise lags
  one login behind. It changes when someone logs in, not when they merely
  stay active in a session they already have open.
- **Fixed-choice fields are validated before writing.** Country, language
  and calendar type only accept Moodle's own codes (`GB`, `en`). Moodle
  itself silently replaces anything else with the site default, so a bad
  value from Monday is refused and logged instead.
- **Two-way sync:** each column mapping has a direction, chosen per column
  in the mapping wizard - Monday → Moodle (default), Moodle → Monday, or
  Both ways.
- **Conflict handling for "Both ways" mappings:** if a field genuinely
  changes on *both* sides between one sync and the next, Monday.com's
  value is kept. This is a fixed rule, not a "most recent edit wins"
  comparison - Moodle doesn't record a per-field edit timestamp for
  custom profile fields at all, so there's no reliable way to know which
  side was actually edited more recently. These show up in the sync log
  with a distinct "conflict" status, separate from ordinary updates.
- **Writing to Monday.com:** text and status columns are written using
  their plain display text (e.g. a status label like "Done"), which is
  Monday's own documented approach for simple column writes. Date columns
  are written using their structured JSON format (UTC date and time), and
  cleared with an empty string. Last login and Account created can write
  to a genuine Monday Date column, so Monday's own date filters and
  automations work on them; whether the cell needs rewriting is decided by
  comparing Monday's stored UTC value, not its display text, so the
  timezone of the Monday user makes no difference. No other field can
  target a Date column. Less common column
  types (formula, mirror, connect boards, etc.) aren't guaranteed to
  accept either format - a failed write shows up as an "error" in the
  sync log with Monday's own error message, rather than failing silently.
- Text representation of each column value is used for comparison and
  writing on the Monday → Moodle side (the API's `text` field for that
  column), not the raw JSON `value`. This works well for text, status, and
  dropdown columns; more complex column types (e.g. formula, mirror) may
  need extra handling if you map them later.
- Rows with no value in the matching column, or no matching Moodle user by
  ID Number, are skipped and logged - they don't error out the run.
- The item's **Name** column can be used for matching or mapping like any
  other column (its Monday-internal ID is always `name`) - but note that
  if you rename its display header in Monday's UI, the API's reported
  `title` for it doesn't update to match (a known Monday API quirk, not
  a bug here) - only the `id`/`type` (`name`) stay reliable, so identify
  it by that rather than its title in the mapping wizard. It can only be
  used as a matching column or a Monday → Moodle mapping target - pushing
  a Moodle value to rename the item isn't supported (renaming an item
  isn't part of the same write API as column values).
- The plugin fetches every item on the board on every run (up to 500 per
  page, cursor-paginated for larger boards) rather than using Monday's
  webhook/subscription model. Fine for boards of a few hundred to low
  thousands of rows on a 15-minute poll; if the board grows large or you
  want near-instant updates later, a webhook-based push model would be a
  better fit for a v2.
- **Outages and rate limits.** If Monday.com is unavailable for one board,
  the other boards still sync and retention cleanup still runs; the task is
  then marked failed so Moodle's own retry backoff applies. Monday's
  throttling (HTTP 429) is shared by every board on the token, so on a 429
  the remaining boards are skipped for that run rather than adding to it.
  The failed and skipped boards are named in the task's failure message.
- **Privacy.** Personal data held in the sync log, the welcome-email
  records and the sync cache is exported and erased through Moodle's
  Privacy API. Erasure deletes the cached values themselves, not just
  their link to the user.