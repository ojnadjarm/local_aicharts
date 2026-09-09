# AI Charts (local_aicharts)

Ask for a chart or a report in plain language; the plugin turns the request into a validated
SQL query and a chart definition, shows a preview, and saves it to a dashboard under
*Site administration → Reports → AI charts*. Saved items run live on every dashboard load or
on a schedule, and scheduled results can be emailed with a CSV attached.

## How it works

1. A manager describes the chart in the *Add chart* modal (optional hints for the schema, the
   chart configuration and the SQL).
2. The prompt, the allowed tables and up to `examplelimit` saved charts as examples are sent
   to the configured provider, which answers with a chart name, one `SELECT`, its named
   parameters, a chart definition (bar, line, pie or table) and optional notes.
3. The SQL is validated before it runs: `SELECT` only, a deny list of dangerous keywords,
   `{table}` placeholders that must be in the allow list, named parameters only. A rejected
   answer is sent back to the provider once with the reason; every attempt is logged.
4. The query runs with a row limit and a statement timeout, the preview renders through the
   core chart API, and the manager saves, refines or regenerates.

Nothing outside the allow list is ever queried, the plugin never writes to site tables, and
the provider receives table names and the request text, never row data.

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

## Capabilities

| Capability | Default | Grants |
|---|---|---|
| `local/aicharts:view` | manager | The dashboard, chart data tables, run history and CSV downloads. |
| `local/aicharts:manage` | manager | Add, edit, duplicate, pause, run now and delete charts; the only capability that reaches the provider. |
| `local/aicharts:receiveresults` | manager | Can be chosen as an email recipient; opens the run history and CSV of the items received. |

All three carry `RISK_PERSONAL`: results may contain personal data.

## Run modes

- **Live** — the query runs when the dashboard loads. The first six live items run on load;
  the rest wait behind a *Load chart* button. Every live card says so in its footer.
- **Daily / Weekly / Monthly** — the item runs at its own hour (site timezone; weekly on the
  first day of the site week, monthly on the 1st). A scheduled task (`queue_due_charts`, every
  minute) queues one ad hoc `run_chart` task per due item; results are stored as CSV and the
  dashboard shows the newest successful run. A scheduled item is run once when it is saved.
  *Run now* queues a run for the next cron pass without emailing. *Pause* stops the schedule.

`cleanup_runs` (daily) prunes the attempt log; stored results are pruned per chart on each run.

## Email

A scheduled item can list recipients holding `local/aicharts:receiveresults`; the capability is
checked again at send time. After each scheduled run every recipient gets a message through
the `scheduledresult` provider (email and web notification): the chart as an inline PNG or the
first rows as a table, an *Open run history* button and the CSV attached. *Only when rows are
returned* skips empty runs. Web-based mail clients show the inline image; clients that block
remote or inline images still get the table and the attachment.

## Testing without a provider

The default provider, *Offline sample answers*, is a stub that answers from
`tests/fixtures/stub_responses.json` without any network call: prompts containing `list`,
`slow` or `wrongtable` get the matching fixture, a prompt with `joke`, `poem`, `weather` or no
Moodle vocabulary is refused, and anything else gets the default bar chart. PHPUnit and the
manual walk-through both rely on it.

`testing-instructions.md` walks the whole feature by hand. The fixture scripts it uses live in
the untracked `.research/testdata/` folder: `setup.php` builds courses, users, a recipient role
and eight charts covering every card state (idempotent), `teardown.php` removes them again.

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
