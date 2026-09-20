# Manual testing — local_aicharts

Every step names the expected result. Steps marked **(browser)** need JavaScript; the rest can
also be checked from the command line.

## 0. Environment

| | |
|---|---|
| Site | `http://moodle52.localhost` (also reachable on `http://localhost:8052`) |
| Admin | `admin` / `Admin1234!` |
| Container | `moodle52-app-1`, Moodle root `/var/www/html`, code under `public/` |
| Mail catcher | `http://localhost:8025` |

All CLI commands below run from the Moodle root inside the container, i.e. prefixed with
`docker exec -w /var/www/html moodle52-app-1`.

### 0.1 Build the fixture

```
php public/local/aicharts/.research/testdata/setup.php
```

Expected: the script prints a category, 3 courses, 6 users, `Role aicrecipient (N) held by
aic_reports`, `10 fixture charts, 16 charts on the site`, `5 stored runs added` and
`11 points behind the trend chart`, then a list of URLs. It is idempotent — running it again
prints `0 enrolments added`, `0 stored runs added` and the same totals.

What it creates: category `AI Charts fixture`; courses `aic_fx_c1..c3` with enrolments and three
course completions; users `aic_teacher`, `aic_reports`, `aic_student1..3`, `aic_newcomer`
(password `Fixture1234!`, `aic_newcomer` has never logged in); a system role `aicrecipient` giving
`aic_reports` the `local/aicharts:receiveresults` capability; and ten charts whose idnumbers
start with `aic_fx_` — five live (one of them a query-only table, one with two series), one
daily with three stored runs, one weekly with one stored run, one daily with `aic_reports` as
recipient and no run yet, one paused monthly item that runs on the last day of the month, and
one daily trend chart with ten hand-made points plus one real run.

### 0.2 Remove it again

```
php public/local/aicharts/.research/testdata/teardown.php
```

Expected: the fixture charts, their stored runs and files, any queued run of them, the courses,
the category, the users and the role are gone, and the last line reads `6 charts left (the
shipped defaults)`. Course deletion prints backup-subsystem noise on the way; that is core.

---

## 1. Settings

1. Open `/admin/settings.php?section=local_aicharts`.
   Expected: the page shows five sections — Provider, Query safety, Prompting, Logging,
   Scheduled runs.
2. Check **Provider**.
   Expected: it reads *Offline sample answers*, set by the fixture. Every step below relies on
   this; with an OpenAI-compatible provider the answers differ.
3. Note the values of **Allowed tables**, **Maximum row limit** (5000) and **Query timeout**.
   Expected: the allow list holds one unprefixed table name per line, `user_enrolments` among them.

## 2. Dashboard — grid, list and filter

4. Open `/local/aicharts/index.php`.
   Expected: 15 cards. Each card header carries a chart-type icon and the chart name; scheduled
   cards also carry a clock icon, the emailed one an envelope. A note above the grid explains that
   live items query the site database on load. *Fixture: enrolments this year vs last year* shows
   two bars per course, one per query (its `This year` and `Last year` series).
5. **(browser)** Type `fixture` into **Filter charts**.
   Expected: after a short pause only the 9 fixture cards remain and the line under the field
   reads *Showing 9 of 15 items.*
6. **(browser)** Replace it with `zzzz`.
   Expected: no cards, and the message *No items match.*
7. Clear the field and switch to **List view**.
   Expected: one row per chart with Name, Mode, Rows and State columns; the paused item shows
   *Paused* in State, and every name links to its chart page. Switch back to **Grid view**;
   the choice is remembered for your account.

## 3. Deferred loading

8. Stay in grid view and look at the cards near the end of the grid.
   Expected: exactly 4 cards are not run on load. One that has never stored a run shows
   *Not run yet — this live query runs when you ask.*, a footer note `limit 500 · not run` and,
   for a manager, a **Run now** button — nothing else to press. Only the first six live charts
   run on page load.
9. **(browser)** Press **Run now** on one of them, then reload the deferred view.
   Expected: the card now draws that stored run with a **Run again** button, the *not run* footer
   note is gone and the footer names the last stored run instead of *ran just now*.
9a. Look at any drawn chart card, then at the *Fixture: users who never logged in* table card.
   Expected: the chart card shows the chart alone — no *Show chart data* link (the link is on the
   chart page, step 27). The table card shows the first 5 rows in a box of fixed height that
   fades at the bottom, followed by an **Open — N rows** link to the chart page; there is no
   *Show all N rows* toggle on the card.

## 4. Chart wizard — Kind, Data, Chart and Schedule **(browser)**

**Add chart** on the dashboard and **Edit** or **Duplicate** in a card menu open the wizard. Only
the **Save chart** button of step 5 writes to the database; until then the draft lives in the
session under the `w` token.

1. Press **Add chart** (or open `/local/aicharts/edit.php`). Expected: redirect to `edit.php?w=<token>&step=1`, the step
   strip *Kind · Data · Chart · Schedule · Review*, *Step 1 of 5* and two cards, *One-shot* and
   *Trend*, none selected. **Next** without a choice shows *Choose a kind.*
2. Pick *One-shot*, **Next**. Expected: *Step 2 of 5*, the line *Kind: One-shot — Every run
   replaces the data. Change*, an empty *Series* list with **Add series**. **Next** shows *Add at
   least one series.*
3. **Add series** opens the modal: *Series name*, *What should this query return?*, the table
   catalogue, *SQL*, *Parameters*, **Run**, **Save series**. **Save series** before **Run** shows
   *Run the query first…* under SQL. Type `SELECT c.shortname AS label, COUNT(*) AS total FROM
   {course} c WHERE c.id > :siteid GROUP BY c.shortname` and `{"siteid": 1}`, **Run**. Expected:
   a *Preview* table of at most 5 rows and *N rows returned · M ms*. Editing the SQL after Run
   and saving shows *Run the query first…* again.
3a. **Add series** again, type `users per course` under *What should this query return?* and press
   **Ask the assistant** (the row shows *Provider: Offline sample answers*). Expected: the status
   strip *Asking the model…* while it runs, then *Series name* reads *Users per course*, *SQL* and
   *Parameters* hold the sample answer and *Assistant notes: Counts enrolled users, whatever the
   enrolment status.* shows above SQL. A name typed before asking is kept. **Run** previews it as
   in step 3.
3b. In a fresh modal type `tell me a joke` and press **Ask the assistant**. Expected: the box *This
   request is not a chart or report from Moodle data.* with the two working prompts, the editors
   untouched. An empty hint shows *Describe what the query should return first.*
4. **Save series**. Expected: the modal closes, the page reloads and the list shows the series
   with *N rows returned · M ms*, the first SQL line, **Edit**, ↑ ↓ and **Remove**. Add a second
   series; ↑ ↓ swap them, **Remove** drops one. **Edit** reopens the modal prefilled.
5. **Next** with every series run. Expected: *Step 3 of 5 — how the result is drawn.* with the
   *Preview* card on the left (the chart drawn from a fresh run, *N rows returned · M ms*, **Update
   preview**) and the controls on the right: *Columns from the Data step: …*, *Chart type* (Bar,
   Line, Pie, Table), *Title*, *Label column*, *Label format*, one checkbox and label box per
   remaining column under *Series*, the axis labels, *Bar options* and *How should it look?
   (optional)* with **Ask the assistant**. A new one-shot chart starts as a bar of every numeric
   column over the first column; a trend starts as a line over *Run time* with one series per query,
   listed as text. Below 992px the preview sits above the controls.
5a. Pick *Pie*. Expected: *Bar options* and the axis labels disappear, *Pie options* (Doughnut)
   appears. *Table* leaves only the type and the title. Tick *Doughnut*, type a title and press
   **Update preview**. Expected: the page reloads on step 3 with the same values and the preview is
   a doughnut with that title. Untick every series and press **Next**. Expected: *Invalid chart
   definition: the chart has no series* under the chart type.
5b. Type `one bar per course` under *How should it look?* and press **Ask the assistant**.
   Expected: the strip *Asking the model…* above the form, then the controls read the sample
   answer (Bar, *Users per course*, series *Users*, axis labels *Course* / *Users*) and the preview
   is redrawn. `tell me a joke` shows the refusal box and leaves the controls as they were; an empty
   hint shows *Describe how the chart should look first.* If the answer names a column the result
   does not have, the box *The assistant named a column the result does not have…* shows instead.
5c. **Next**. Expected: *Step 4 of 5 — when it runs and who receives it.* with *Row limit* (site
   default, *Site maximum: N. Rows beyond the limit are cut…*), *Run mode* (Live, Daily, Weekly,
   Monthly), *Run time*, the site time line and *Next run: …* for a scheduled mode, then *Email the
   result* with *Recipients*, *Send* and the hint — all hidden while *Live* is selected. **Back** to
   step 3 shows the same controls. Editing a shipped 1.1 chart (**Edit** on its card, run its series
   on the Data step, **Next**) shows its saved definition as controls, e.g. *Courses per category*
   with *Horizontal* ticked; **Next** keeps the stored JSON identical.
5d. Pick *Weekly*. Expected: *Day of the week* appears (site week start first) and the *Next run*
   line names that day. *Monthly* swaps it for *Day of the month* (1–28, *Last day of the month*).
   Type `9999` as the row limit and press **Next**: *Enter a row limit between 1 and N.* Add a
   recipient (only users who may receive results and the admins are offered), **Next**. Expected:
   *Step 5 of 5* (section 5) and no **Next** button; **Back** shows the schedule as saved.
   Editing a scheduled 1.1 chart opens step 4 on its mode, day, hour and recipients.
5e. Repeat from step 1 with *Trend* and a one-row series (`SELECT COUNT(c.id) AS total FROM
   {course} c WHERE c.id > :siteid`). On step 4 expected: no *Live* radio, *Daily* preselected,
   the line *A trend chart runs on a schedule; every run adds one point per series. Run now adds a
   point too.*, and *Points to keep* prefilled with the site setting (365) above the email header.
   Type `10`, **Next**, **Back**: it reads `30` (the floor).
6. **Change** on the kind line, pick *Trend*, **Next**. Expected: the Data step shows *The kind
   changed: run every series again.* and every series reads *not run*; **Next** names the first
   series that has not been run. A trend series that returns more than one row is refused with
   *Series 'X' returned N rows; a trend series returns one.*
7. **Cancel**. Expected: back on the dashboard; opening the old `edit.php?w=<token>&step=2` URL
   shows *The chart wizard has expired. Start again.* on the dashboard.

## 5. Chart wizard — Review and Save **(browser)**

8. Walk a one-shot chart to *Step 5 of 5 — check it, name it, save it.* Expected: *Chart name*
   (prefilled with the assistant's name, if any), then a summary of *Kind*, *Series*, *Chart*,
   *Runs*, *Row limit* and, when a recipient is set, *Emailed*; the chart of a fresh run in a card
   headed by the name with *N rows returned · M ms*; and a **Save chart** button beside **Back**.
   The run happens on this page only — pressing **Save chart** runs nothing.
9. Empty *Chart name* and press **Save chart**. Expected: the step stays with *You must supply a
   value here.* and the summary still listed. Fill it in and press **Save chart**. Expected: the
   dashboard with *Chart saved.* and that card first, *Not run yet.* for a scheduled chart.
10. **Edit** that card. Expected: *Edit chart*, *Step 1 of 5* with its kind selected; step 2 lists
    the saved series as *not run*, so **Edit** it, **Run**, **Save series**, then walk on — step 3
    shows its saved definition and step 5 its name. Change the name and **Save chart**. Expected:
    the same card renamed, no second card.
11. **Duplicate** in the card menu. Expected: the wizard on step 1 with the name suffixed
    *(copy)*; saving adds a second card and leaves the first alone.
12. On step 2 of a saved chart, **Remove** a series, then walk to step 5 and save. Expected: the
    summary lists only the series left and the chart page of a trend chart drops that line.
13. Break the data behind a run series: with the wizard on step 5, remove a table the series reads
    from **Allowed tables** in the settings and reload the step. Expected: the error box naming
    that table with the link *Fix it on the Data step* to step 2, no chart card and **no Save
    chart** button. Put the setting back and reload: the chart and the button return.
14. Repeat for a *Trend* chart. Expected: the summary *Runs* line reads *Scheduled daily at HH:00 ·
    Next run: … · keeps the last 365*, the card draws the stored points plus the point of this run
    and the note *First point; the chart grows with each run.*
    After saving, the dashboard card and the chart page show the trend marker *Trend — one point per
    run* and *N points* (the chart page adds *keeps the last 365*). A chart saved this way stores
    no point until its first run.
15. At 390px wide: the step strip wraps, the summary terms sit above their values, the chart card
    fills the width and **Back** / **Save chart** stay reachable without a sideways scroll.

## 6. Scheduled card states

26. Look at the four scheduled fixture cards on the dashboard.
    Expected:
    - *Fixture: daily completions per course* — the stored chart, footer *last run: … · scheduled
      daily · 03:00*; the name links to its chart page and the menu offers **View**.
    - *Fixture: emailed enrolments per course* — envelope icon, *Not run yet.* and *Next run: …*
      (until step 32 of section 8 runs it).
    - *Fixture: weekly courses per category* — the stored chart, *scheduled weekly on Wednesday ·
      05:00*.
    - *Fixture: paused accounts per authentication* — *Paused — not run until resumed.*, no chart,
      no next run, footer *scheduled monthly on the last day · 07:00 · Paused*; its ⋮ menu offers
      **Resume**. The list view Mode column reads *Paused*.

## 7. Chart page and CSV

26a. Follow the name of *Fixture: enrolments per course* (a live chart,
    `/local/aicharts/view.php?id=<chartid>`).
    Expected: the breadcrumb *AI charts / Fixture: enrolments per course*, the line *Live — runs
    when the dashboard or this page loads*, the chart drawn full width with the footer *3 rows ·
    limit 500 · … ms · ran just now* and a **Download CSV** button; no *Runs* table. Press
    **Download CSV**: a `chart-<id>-<date>.csv` file with `coursename,total` as its first line is
    streamed from a fresh run; nothing is stored. `/local/aicharts/history.php?id=<chartid>`
    redirects to the same page.
27. Follow the name of *Fixture: daily completions per course*
    (`/local/aicharts/view.php?id=<chartid>`).
    Expected: the newest stored result drawn at the top with its **Show chart data** link (the
    page keeps the data table the cards drop) and a **Download CSV** button under it, a header
    line *Scheduled daily at 03:00*, *30 runs kept*, a **Back to charts** link, a **Run again**
    button under that line and a table of three runs, newest first. Press it: the page comes back
    with *Run stored — N rows*, a fourth run at the top of the table and that run drawn in the card.
28. Read the run table.
    Expected: one row per run with its date, the trigger — *Scheduled*, *Manual (Admin User)* and
    *First run after save* — the status *OK*, the row count, the duration, a **Download CSV** link
    and a **View** link.
29. Press **View** on an older run.
    Expected: the page redraws that run and the header reads *Run of <date> · viewing an older run
    — latest is <date>*. Appending an unrelated `resultid` to the URL is refused with *This run
    does not belong to this item.*
30. Press **Download CSV**.
    Expected: a `chart-<id>-<date>.csv` file whose first line is the column header
    (`coursename,total`) and whose body matches the row count of that run.
31. Log out and log in as `aic_reports` / `Fixture1234!`, then open the same chart page URL.
    Expected: the page opens and the CSV can be downloaded — the receive-results capability is
    enough. Opening `/local/aicharts/index.php` as that user is refused (*access denied*); the
    dashboard needs `local/aicharts:view`. Log back in as admin.

## 8. Run now and the scheduled pipeline

32. **(browser)** On the *Fixture: emailed enrolments per course* card (not run yet), press the
    **Run now** button in its body.
    Expected: the run happens while the request is open — the page reloads with a notification
    *Run stored — N rows. It is now the latest run of this chart.* and the card already shows the
    result with a **Run again** button. No cron pass is needed and nothing is added to the ad hoc
    queue (`SELECT count(*) FROM {task_adhoc}` for `\local_aicharts\task\run_chart` stays as it
    was). **Run now** appears only where nothing is stored yet; elsewhere it reads **Run again**.
32a. **(browser)** Open `/local/aicharts/index.php?skiplive=1`: every live card is deferred and a
    card with nothing stored shows **Run now** alone; press **Run now** on *Enrolments per course*.
    Expected: the page reloads, the notification names the stored run and the card footer gains
    *last run: <date>* — a deferred card stores a run like any other.
32c. **(browser)** On a live chart that has never run (*Courses per category*) press **Run now**,
    then **Run again**, without running cron in between.
    Expected: two rows on `/local/aicharts/view.php?id=<id>`, newest first, both *Manual*, and the
    **View** link of the older one renders it with *viewing an older run*. Reloading the dashboard
    many times adds no further runs — only a run you ask for is stored.
32b. **(browser)** On the same live card choose **Pause**.
    Expected: *Chart paused.*, the card body reads *Paused — not run until resumed.* and its page
    no longer runs the query (the stored manual run is shown instead). Pressing a run button on a
    paused chart answers *This chart is paused, so it was not run.* and stores nothing.
    **Resume** restores it.
33. Confirm the queue from the CLI:
    ```
    php admin/cli/adhoc_task.php --showsql=0 --execute --ignorelimits
    ```
    Expected: `Chart <id> ran with status ok in N ms.` and `Ran 1 adhoc tasks`. Reload the
    dashboard: the `· queued` marker is gone and *last run* has moved to now.
34. Exercise the scheduler itself:
    ```
    php admin/cli/scheduled_task.php --execute='\local_aicharts\task\queue_due_charts'
    php admin/cli/adhoc_task.php --execute --ignorelimits
    ```
    Expected: the first prints `Queued N chart runs.` for the due items, the second runs them and
    prints one `ran with status ok` line per chart. `/admin/tool/task/adhoctasks.php` stays empty
    of failures afterwards.

## 9. Email delivery

35. Empty the mail catcher (`http://localhost:8025`, *Delete all messages*), then run:
    ```
    php admin/cli/scheduled_task.php --execute='\local_aicharts\task\queue_due_charts'
    php admin/cli/adhoc_task.php --execute --ignorelimits
    ```
    Expected: *Fixture: emailed enrolments per course* is queued and run.
36. Open `http://localhost:8025`.
    Expected: one message to `"Rita Reports" <aic_reports@example.com>`, subject *Fixture: emailed
    enrolments per course: 3 rows*. Its body is a card at most 600 px wide (narrower on a phone)
    with the chart as a PNG, the run date and row count, an *Open run history* button that opens
    the chart page, the line about the attached CSV and the personal-data footer. The CSV is
    attached.
37. Log in as `aic_reports` and open the notification bell.
    Expected: the same result as an in-site notification (the message provider allows both email
    and popup).
38. Reload the chart page of that chart as admin.
    Expected: the run table has an **Emailed** column and the run just sent is marked *Emailed*.

## 10. Trend chart

38a. Open the dashboard and find *Fixture: active users, trend*.
    Expected: a line chart with 11 points; the labels are run times such as `14/09/26, 06:00`.
38b. Run it from the card's **Run again** button, or from the CLI:
    ```
    php admin/cli/scheduled_task.php --execute='\local_aicharts\task\queue_due_charts'
    php admin/cli/adhoc_task.php --execute --ignorelimits
    ```
    Then reload.
    Expected: 12 points; on its chart page the newest run reads *12 rows* and its **Download CSV**
    file starts with `runtime,"Fixture: active users, trend"` followed by one line per run, the
    run time formatted, not an epoch. `SELECT COUNT(*) FROM mdl_local_aicharts_point WHERE
    chartid = <id>` also reads 12; every point of the newest run carries the id of that result.
38c. A run whose query returns more than one row (edit the SQL to `SELECT id FROM {user}` in
    the database) stores a failed run reading *Series '…' returned N rows; a trend series
    returns one.* and adds no point.

## 11. Privacy

39. Open `/admin/tool/dataprivacy/pluginregistry.php` and expand **Local plugins → AI Charts**.
    Expected: the four tables (`local_aicharts_chart`, `local_aicharts_query`,
    `local_aicharts_run`, `local_aicharts_result`), the `result` file area, the `core_message`
    subsystem and the dashboard view preference are all listed with a plain-language purpose. Nothing says the
    result rows belong to one user.

## 12. Clean up

40. Run the teardown from section 0.2 and delete by hand anything you created during the walk
    (anything saved in sections 4 and 5).
    Expected: `6 charts left (the shipped defaults)` and the dashboard shows only those six.

## Not covered yet

- The OpenAI-compatible provider: every step above uses the offline sample answers. With
  *OpenAI-compatible* selected and no key, **Ask the assistant** shows *No API key is set for the
  provider.* with an **Open settings** link; an unreachable endpoint shows a box with the
  sanitised error under *Show details*.
- The ⋮ menu item *Delete*: it asks a core confirmation first and removes the chart with its
  stored runs.
- The empty state of a query with no rows: the card shows *No rows returned.* with an info
  icon and `0 rows · limit N` in the footer. No fixture chart returns zero rows by default.
