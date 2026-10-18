# Reportium – Interactive Reports for Moodle (local_reportium)

Interactive SQL reports for Moodle, running on your own site: tables, charts and KPI cards that drill down from level to
level, scheduled emails and alerts, and ready-made reports installed with the plugin.

## Features

- **Drill-down levels**: click a bar, slice or row to open the next level (category › course › learner › activity…).
  Each level is an SQL query; `%%PREVIOUSVALUE%%` and `%%LEVELVALUE:n%%` receive the row clicked above.
- **Charts and tables**: bar, line, pie and table views of every level, with settings per level and per chart type.
- **KPI cards and totals row**, also at the end of the downloads.
- **Calculated columns** without SQL (a formula over the columns: `pct([completed], [enrolled])`), column filters,
  search, sortable headers, pagination, and the column order of each user (drag and drop).
- **Periods**: every report can filter by date (`%%STARTTIME%%` / `%%ENDTIME%%`) and compare with the previous period.
- **Downloads** of what is on screen in every Moodle data format (CSV, Excel, ODS, JSON…), a PDF like the print (KPI
  cards, chart and table) and the chart as a PNG image.
- **Scheduled emails** (daily, weekly, monthly) with the file attached, a message template with placeholders, and
  **alerts** that are sent only when a condition is met (e.g. "Average of Completion < 50").
- **Ready-made reports**: learner progress, course completion, grades, activity and logins, forums, quizzes, overdue
  assignments, inactive learners, file storage and more.
- **Access per report** by role; `%%MYCOURSES%%` lets one report show each user only their own courses.
- **SQL Inspector** with history of every change, slow-query tips and an instructions file (AGENTS.md) to write the
  SQL with any AI chat.
- **First setup** assistant for administrators, and report files to move reports between sites.

The placeholders, column keywords and settings are described in `instructions.json`.

## Requirements

Moodle 4.5 (PHP 8.1 or later).

## Installation

1. Copy the folder to `local/reportium` (or install the ZIP from *Site administration › Plugins › Install plugins*).
2. Visit *Site administration › Notifications* to finish the installation.
3. Open *Site administration › Reports › Reportium*, or follow the *First setup* assistant.

## Capabilities

| Capability | Default | What it allows |
|---|---|---|
| `local/reportium:view` | Everyone | See the reports shared with one of their roles |
| `local/reportium:download` | Manager, Teacher | Download reports |
| `local/reportium:create` | Manager, Teacher | Create reports |
| `local/reportium:manage` | Manager | Manage every report |
| `local/reportium:share` | Manager, Teacher | Choose the roles that see a report |
| `local/reportium:writesql` | Nobody | Write the SQL of reports (it can read any table: give it only to trusted people) |
| `local/reportium:transfer` | Manager | Import and export report files |
| `local/reportium:schedule` | Manager | Schedule emails of reports |
| `local/reportium:useai` | Manager | Use the instructions file for AI chats |

## Security

- The SQL of a report can only be written by users with `local/reportium:writesql`, which no role has by default.
- Only `SELECT` queries are run: statements that change data, several statements and explicit table prefixes are
  refused, and every query has a row limit.
- HTML returned by the SQL is cleaned before it is shown.
- The instructions file for AI chats holds no data of the users: only the SQL of the report, with values that look
  personal or secret replaced by examples.

## Privacy

Reportium stores the reports, their levels and versions, the roles they are shared with, the scheduled emails and the
column order of each user. All of it is described and exported through the Privacy API of Moodle.

## Support

- Bug tracker: https://github.com/pedrolois/moodle-local_reportium/issues
- Source code: https://github.com/pedrolois/moodle-local_reportium

## Licence

GNU GPL v3 or later (see LICENSE). Copyright 2026 Pedro Luis Garcia Leyva.

Includes Apache ECharts (Apache 2.0), see `thirdpartylibs.xml`. To update it, replace `vendor/echarts/echarts.min.js`
with `dist/echarts.min.js` of the wanted release and update the version in `thirdpartylibs.xml`.
