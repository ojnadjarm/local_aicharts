# AI Charts (local_aicharts)

Ask for a chart or a report in plain language; the plugin turns the request into a validated
SQL query and a chart definition, shows a preview, and saves it to a dashboard under
*Site administration → Reports → AI charts*. Saved items run live on every dashboard load or
on a schedule, and scheduled results can be emailed with a CSV attached.

## The wizard

*Add chart*, *Edit* and *Duplicate* open `edit.php`, a page per step with a progress strip,
*Back* / *Next* and *Cancel*. The draft lives in the session under a token in the URL
(`edit.php?w=<token>&step=1..5`), so a reload never resubmits and a completed step can be
reopened from the strip.

1. **Kind** — one-shot or trend (see below), as two cards.
2. **Data** — one or more series, each added or edited in its own modal: what the series should
   return, the list of tables you may query, *Ask the assistant*, the SQL, its parameters and
   *Run*. A series is saved only once a *Run* of exactly those fields succeeded. *Next* runs
   every series again and keeps the result columns for the next step.
3. **Chart** — the type (bar, line, pie, table) and plain selects pointed at the columns the
   Data step produced, with a preview beside them drawn from the rows that run returned.
   *Ask the assistant* fills the controls; *Update preview* redraws them. No JSON is edited.
4. **Schedule** — the row limit, the run mode, the hour and day, the points a trend keeps and
   the recipients.
5. **Review** — a summary, the chart from a fresh run and the chart name. *Save* appears only
   when that run succeeded; saving itself runs no query.

The assistant is optional at every step: a series can be typed by hand with no prompt at all.

### What the provider is asked

The series description, the allowed tables and up to `examplelimit` saved charts as examples
are sent to the configured provider, which answers with a name, one `SELECT`, its named
parameters, a chart definition and optional notes. The SQL is validated before it runs:
`SELECT` only, a deny list of dangerous keywords, `{table}` placeholders that must be in the
allow list, named parameters only. A rejected answer is sent back once with the reason; every
attempt is logged. The query then runs with a row limit and a statement timeout.

Nothing outside the allow list is ever queried, the plugin never writes to site tables, and
the provider receives table names and the request text, never row data.

### Several series

A chart holds one or more series, each with its own description, SQL and parameters. With
several series every query must return two columns: the first is the label the rows are merged
on, the second the value. The label column keeps the first query's column name and each value
column is named after its series label, so the chart definition's `series[].column` names a
series label. One CSV holds all the series.

## Chart kinds

- **One-shot** — every run replaces the data. The chart draws the rows of the newest run.
- **Trend** — every run appends one point per series and the chart grows over runs. A trend
  series returns at most one row and its value is the last column of that row, so both
  `SELECT COUNT(*) AS total` and `SELECT 'Active' AS label, COUNT(*) AS total` work. A trend
  chart must run on a schedule, is drawn along the run time, and keeps the newest
  *Points to keep* run times (its own value, else the `pointsretention` setting, never fewer
  than 30). Each run also stores every point so far as its rows, so the CSV, the email and the
  chart page show the whole trend.

## Requirements

Moodle 5.1 or later. The email chart image uses PHP GD.

## Settings

`Site administration → Plugins → Local plugins → AI charts`

| Section | Setting | Purpose |
|---|---|---|
| Provider | `clienttype` | *Offline sample answers* (no network, canned answers for testing) or *OpenAI-compatible*. |
| Provider | `baseurl`, `apikey`, `model`, `timeout`, `temperature` | Any OpenAI-compatible chat completions endpoint; `/chat/completions` is appended to the base URL. |
| Query safety | `allowedtables` | One unprefixed table name per line. The generated SQL may only read these. |
| Query safety | `maxrowsdefault`, `maxrowsmax` | Row limit prefilled on a new chart and the hard ceiling applied when any chart runs. |
| Query safety | `querytimeout` | Seconds before a query is stopped (PostgreSQL, MySQL and MariaDB session timeouts). |
| Prompting | `examplelimit`, `extrainstructions` | How many saved charts are sent as examples, and site specific guidance appended to the instructions. |
| Logging | `logretentiondays` | Days a generation attempt stays in `local_aicharts_run`. 0 keeps everything. |
| Scheduled runs | `runhour`, `resultretention` | Default run time for scheduled items and how many stored runs are kept per chart. |
| Scheduled runs | `pointsretention` | Run times kept per trend chart when the chart sets none. A chart never keeps fewer than 30. |

## Capabilities

| Capability | Default | Grants |
|---|---|---|
| `local/aicharts:view` | manager | The dashboard, chart pages, chart data tables and CSV downloads. |
| `local/aicharts:manage` | manager | Add, edit, duplicate, pause, run now and delete charts; the only capability that reaches the provider. |
| `local/aicharts:receiveresults` | manager | Can be chosen as an email recipient; opens the chart page, stored runs and CSV of the items received. |

All three carry `RISK_PERSONAL`: results may contain personal data.

## Run modes

- **Live** — the query runs when the dashboard loads. The first six live items run on load;
  the rest show their last stored run instead, or *Run now* when they have none. Every live card
  says so in its footer.
- **Daily / Weekly / Monthly** — the item runs at its own hour (site timezone); a weekly item
  on the weekday you pick, a monthly one on the day of the month you pick (1–28, or the last
  day of the month). A scheduled task (`queue_due_charts`, every
  minute) queues one ad hoc `run_chart` task per due item; results are stored as CSV and the
  dashboard shows the newest successful run.

*Run now* appears on a card that has never stored a run; once the card holds one it shows that
result and a *Run again* button instead. Either runs the queries at once, stores the result before
the page comes back and never emails; the stored run is listed on the chart page. Cron is not
involved. A paused chart is not run. Saving a chart never runs it. A live card renders live on
every load and carries *Run again* under that render: only a run you ask for is stored, never a page
load, and the card footer names the last stored run. A live card beyond the load limit is not run on
load: it draws its last stored run with *Run again*, or shows *Run now* alone when nothing is stored.
The chart page carries the same button under its header, named by the same rule, whichever run it is
showing.
*Pause* stops a scheduled item's schedule, or stops a live item from querying, until *Resume*.

## Cards

A card shows the chart and nothing else: no data table, and the card footer carries the rows
and the run time. A query-only item (chart type *table*) shows a short fixed-height preview of
the first rows with an *Open — N rows* link to its page. A trend card carries a trend marker
and how many run times it has stored. The list view has one row per chart with the same marker.

## Chart page

Every chart name on the dashboard opens its page (`view.php?id=<chartid>`). A live item runs
there and shows the current result with a *Download CSV* button that streams a fresh run
(nothing is stored); a scheduled item, or a live item with manual runs, shows the newest stored
run, a *Runs* table (date, trigger, status, rows, duration, CSV) and lets you view or download
any kept run. `history.php` redirects there.

`cleanup_runs` (daily) prunes the attempt log; stored results are pruned per chart on each run.

## Email

A scheduled item can list recipients holding `local/aicharts:receiveresults`; the capability is
checked again at send time. After each scheduled run every recipient gets a message through
the `scheduledresult` provider (email and web notification): the chart as an inline PNG or the
first rows as a table, an *Open run history* button that opens the chart page and the CSV attached. *Only when rows are
returned* skips empty runs. Web-based mail clients show the inline image; clients that block
remote or inline images still get the table and the attachment.

## Testing without a provider

The default provider, *Offline sample answers*, is a stub that answers from
`tests/fixtures/stub_responses.json` without any network call: prompts containing `list`,
`slow`, `wrongtable` or `last year` get the matching fixture, a prompt with `joke`, `poem`,
`weather` or no Moodle vocabulary is refused, and anything else gets the default bar chart. PHPUnit and the
manual walk-through both rely on it.

`testing-instructions.md` walks the whole feature by hand. The fixture scripts it uses live in
the untracked `.research/testdata/` folder: `setup.php` builds courses, users, a recipient role
and the charts covering every card state, one with two series and one trend with stored points
(idempotent), `teardown.php` removes them again.

```
docker exec -w /var/www/html <container> php public/local/aicharts/.research/testdata/setup.php
docker exec -w /var/www/html <container> php public/local/aicharts/.research/testdata/teardown.php
```

## Tests

```
vendor/bin/phpunit --testsuite local_aicharts_testsuite
vendor/bin/behat --tags=@local_aicharts
```

## Licence

GNU GPL v3 or later.
