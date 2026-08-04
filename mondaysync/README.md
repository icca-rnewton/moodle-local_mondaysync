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

## Notes / limitations

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
- **Manual-auth welcome email now fires on any transition into "manual",
  not just at creation.** If an account's created with a different
  default (e.g. `nologin`, for a staged "waiting state" workflow) and its
  auth is later switched to `manual` via the Advanced `auth` mapping,
  that now triggers the same welcome email as a creation-time `manual`
  default would - but only ever once per account, tracked permanently
  (not subject to the log retention purge), no matter how many times auth
  flips away from and back to `manual` afterward.
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
  Custom, for core account properties rather than ordinary profile data -
  currently `auth` (authentication method), `suspended` (blocks login),
  and `lastlogin` (read-only for Monday - Moodle overwrites it itself on
  every real login, so it's restricted to a Moodle → Monday direction
  only, enforced by the wizard). These genuinely control login access, so
  they're clearly labelled with a warning in the wizard rather than mixed
  in unlabelled with ordinary fields. `auth` in particular needs the
  Monday column's value to exactly match an enabled auth plugin's
  shortname (e.g. "manual", "nologin") - anything else effectively breaks
  that account's login.
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
  are written using their structured JSON format. Less common column
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