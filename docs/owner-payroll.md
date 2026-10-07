# University owner portal — Home and Payroll

Arabic RTL portal for the university owner: **الرئيسية** (Home) and **الرواتب** (Payroll). The payroll sheet is **one current working sheet**; there are no months, periods, approvals, closing, payments or history (see "Deferred").

All amounts are **Syrian pounds (SYP, «ل.س»)**. There is no other currency and no exchange rate anywhere in the feature.

Base: `origin/develop` `ffcda5d` (PR #154), built in the dedicated worktree `/home/user/wt-owner-payroll` on branch `codex/university-owner-payroll` (PR #156). No other working directory, branch or database was touched.

Contents: 1 Access · 2 Data and migrations · 3 Data-preservation decisions · 4 Deployment · 5 The workbook calculation · 6 Columns and formulas · 7 Workspace · 8 Home · 9 Saving, conflicts, undo · 10 Exports · 11 Grid dependency · 12 Review findings · 13 Visual references · 14 Verification · 15 Deferred · 16 Limitations

## 1. Access and permissions

| Item | Code |
|---|---|
| Module | `owner_portal` |
| Role | `university_owner` — «مالك الجامعة» (system role, **assigned to nobody by default**) |
| Permissions (all mapped to `university_owner` only) | `owner_portal.access`, `owner_portal.home.view`, `owner_payroll.view`, `owner_payroll.employees.manage`, `owner_payroll.bodies.manage`, `owner_payroll.amounts.edit`, `owner_payroll.export`, **`owner_payroll.config.manage`** (new: add/edit/delete columns and formulas, change the global settings) |

Server rule (`App\Support\OwnerPortal`, middleware `RequireOwnerPortal`, every `/api/v1/owner/*` route):

- allowed if the account is the **central administrator** (active `super_admin`, existing behaviour unchanged), **or**
- the account is active, holds an **active `university_owner` assignment**, and **that role itself** grants `owner_portal.access` plus the route's permission.
- Anything else is 403. An owner permission mapped to another role does **not** open the portal. The president, both vice presidents, HR, technical team, deans and the ministry observer have no owner permission and no access; tests assert it for each, for every endpoint (including the new configuration endpoints and both exports).
- Reading the configuration (`GET /payroll/config`) needs only `owner_payroll.view` (the grid needs it); every change needs `owner_payroll.config.manage`.

React mirrors this (`ACCESS.ownerPortal/ownerHome/ownerPayroll`, `PERMISSIONS.ownerPayrollConfigManage` in `auth.js`); the server is authoritative. The sidebar has **exactly two items** (الرئيسية، الرواتب). The owner role lands on `/owner`.

### Granting access (authorized administrator)

1. Deploy (section 4) and run `php artisan owner-portal:provision-access`. It is idempotent, **never creates users or assigns the role**, and `--check` reports the state without writing. **Re-run it after this update** so the new `owner_payroll.config.manage` permission is created and mapped to the owner role (an existing owner role keeps working for everything else; without the re-run only the central administrator can change columns and formulas).
2. Sign in as `super_admin` → المكتب التقني → الحسابات والصلاحيات → assign the role «مالك الجامعة» to the owner's account (only `super_admin` can).
3. The owner signs in again and lands on `/owner`.

No production account, credential or assignment is created by this change.

## 2. Data and migrations

Both migrations are **additive**: nothing existing is dropped, rewritten or deleted.

| Migration | Purpose |
|---|---|
| `2026_10_08_000000_create_owner_payroll_tables.php` (first part of the PR) | `payroll_bodies`, `payroll_employees`, `payroll_entries` (legacy integer-cents columns, kept untouched) |
| `2026_10_09_000000_add_payroll_columns_settings_and_syp_template.php` (this update) | `payroll_config`, `payroll_settings`, `payroll_columns`, `payroll_entry_values`; seeds the workbook template and settings; copies existing values (section 3) |

| Table | Purpose |
|---|---|
| `payroll_bodies`, `payroll_employees` | Unchanged: bodies; identity/classification with the **manual string employee number (unique, leading zeros preserved)** |
| `payroll_entries` | One row per employee; now only the **row revision** + audit (`revision`, `updated_by_user_id`). The legacy `*_cents` columns stay for traceability and rollback |
| `payroll_config` | One row: `revision` (protects columns/formulas/settings), `updated_by_user_id` |
| `payroll_settings` | Global settings stored once: key, Arabic label, type, value |
| `payroll_columns` | Every column: immutable stable `key` (e.g. `fixed_salary`, `c_1a2b3c4d5e`), Arabic label, group, `kind` (`input`/`formula`), `value_type` (`text`/`number`/`amount`/`percent`), canonical formula (stable keys), `blank_as_zero`, `allow_negative`, `warn_negative`, `aggregation`, visibility flags (grid / export / compact), `is_system`, `sort_order` |
| `payroll_entry_values` | The saved input values: `(employee, column)` primary key, `value_scaled` (integer) or `value_text`. **A blank cell has no row.** An entered zero is a row with `0` |

- Payroll employees are independent records. No foreign key leaves the `payroll_*` tables (a test inspects the schema). Creating a payroll employee writes only these tables (a test compares row counts of the existing tables); a source-contract test proves no existing application file references the payroll tables, so reports and workforce statistics cannot include payroll people.
- `payroll_entry_values.payroll_column_id` cascades only when a **custom** column is deleted (after an explicit confirmation); `payroll_employee_id` is `RESTRICT`.
- `down()` refuses to run when any value or custom column exists.

## 3. Data-preservation decisions

- **Scale.** Phase 1 stored `value × 10²` (integer "cents") and labelled the numbers USD. They are plain numbers; the unit was only a label. The new table stores `value × 10⁶` (so signed adjustments, percentages and 6-decimal numbers are exact). The migration multiplies by exactly `10⁴` — a change of scale, **not** of value: `96600.00` → `96600000000`; the numeric value and its two decimals are identical, and no exchange rate is applied. The migration test enters legacy cents (`9660000`, `12345`, `5520050`, `0`, `1`, NULL), runs the migration and proves each value reads back as the same number (`96600.00`, `123.45`, `55200.50`, `0.00`, `0.01`) and that NULL stays blank (no row), never zero.
- **Mapping.** `fixed_salary_cents → الأجر المقطوع`, `compensation_cents → التعويض`, `deduction_cents → حسميات أخرى`. The legacy single deduction is an **aggregate**; it is deliberately **not** split or redistributed into insurance / income tax (those are computed by formula from the settings). The insurance and tax columns therefore hold no stored data (a test asserts it). The new net-payable formula differs from phase 1 (`salary − deduction + compensation`), so the same legacy numbers produce different totals — that is the requested calculation change, not data loss.
- **Not touched.** Employee rows, employee numbers (leading zeros), revisions (`payroll_entries.revision` is preserved) and the legacy `*_cents` columns. No table is dropped or reseeded; no real record is created.
- **Rollback.** Revert the code and leave the tables (the legacy columns still hold the original values). `down()` of the new migration refuses to erase saved values.

## 4. Deployment (not executed against production)

1. Back up the database.
2. Backend: `composer install` (TCPDF 6.11.4 LGPL and PhpSpreadsheet 5.9.0 were already requirements; **`brick/math` 0.14.8 is now a direct requirement** — it was already installed transitively and provides the exact decimals; only the lock file's content hash changed). Production PHP is 8.4.
3. `php artisan migrate --force` (or the two `--path=` forms in order: `2026_10_08_000000_create_owner_payroll_tables.php`, then `2026_10_09_000000_add_payroll_columns_settings_and_syp_template.php`).
4. `php artisan owner-portal:provision-access` (creates/maps `owner_payroll.config.manage`).
5. Build/deploy the frontend (`npm ci && npm run build`).
6. `storage/app` must be writable (the first PDF converts the shipped Cairo fonts under `storage/app/pdf-fonts`).
7. Assign the role (section 1).

The reference-workbook values (insurance 7 %, income tax 15 %, exemption 12,560 ل.س) are seeded as **editable settings supplied by the reference file**, not as a statement about current law.

## 5. The workbook calculation

Reference: «شرح حساب الرواتب والأجور.xlsx». One employee per row; the same rules apply to **every** employee (there are no per-employee exemptions, profiles or switches).

**Editable inputs** (one value per employee): الأجر المقطوع (fixed salary, ≥ 0, required), فروقات الراتب (salary differences, signed), التعويض (≥ 0), فروقات التعويض (signed), حسميات أخرى (≥ 0).
**Global settings** (stored once): نسبة التأمينات 7 %, نسبة ضريبة الدخل 15 %, الحد الأدنى المعفى 12,560 ل.س.

| Column | Formula |
|---|---|
| الراتب المستحق | `[الأجر المقطوع] + [فروقات الراتب]` |
| التأمينات الاجتماعية | `[الأجر المقطوع] * [نسبة التأمينات]` — on the **fixed salary only**: never the adjusted salary, never compensation |
| الوعاء الضريبي للراتب | `[الراتب المستحق] − [التأمينات] − [الحد المعفى]` — the exemption is applied once |
| ضريبة دخل الراتب | `[الوعاء الضريبي للراتب] * [نسبة ضريبة الدخل]` |
| التعويض المستحق | `[التعويض] + [فروقات التعويض]` |
| الوعاء الضريبي المجمّع | `[الراتب المستحق] + [التعويض المستحق] − [التأمينات] − [الحد المعفى]` |
| ضريبة دخل التعويض | `[الوعاء الضريبي المجمّع] * [نسبة ضريبة الدخل] − [ضريبة دخل الراتب]` |
| إجمالي الاقتطاعات | `[التأمينات] + [ضريبة الراتب] + [ضريبة التعويض] + [حسميات أخرى]` |
| إجمالي المستحقات (قبل الاقتطاع) | `[الراتب المستحق] + [التعويض المستحق]` |
| صافي الراتب | `[الراتب المستحق] − [التأمينات] − [ضريبة دخل الراتب]` |
| صافي التعويض | `[التعويض المستحق] − [ضريبة دخل التعويض]` |
| إجمالي الصافي المستحق | `[صافي الراتب] + [صافي التعويض] − [حسميات أخرى]` — other deductions are subtracted **once**; nothing is double-counted |

The Arabic labels separate **gross entitlement** (المستحق / المستحقات قبل الاقتطاع) from **net payable** (الصافي المستحق). Reference case (asserted on the server, in the browser engine, in the real UI, in Excel and in the PDF): fixed 96,600 and compensation 55,200, no adjustments or other deductions → salary entitlement 96,600.00, insurance 6,762.00, salary taxable base 77,278.00, salary tax 11,591.70, compensation entitlement 55,200.00, combined base 132,478.00, compensation tax 8,280.00, total deductions 26,633.70, net salary 78,246.30, net compensation 46,920.00, **total net payable 125,166.30**.

Rules:

- **Exact decimals.** Server: `brick/math` `BigDecimal`. Browser: a `BigInt` decimal. No binary floating point for any stored or calculated value.
- **One rounding rule:** each calculated column is rounded **half away from zero at its own column boundary** — 2 decimals for amounts, 6 for numbers and percentages — and the rounded figure feeds the dependent columns (this is what the workbook shows). Division keeps 10 decimals first. Identical on the server, in the browser, in Excel (section 10) and in the PDF.
- **No zero floor.** There is no `MAX(0, …)`. A negative taxable base or tax stays negative and is flagged as a calculation warning (⚠ amber, counted on the page and on Home), never hidden and never turned into 0.
- **Blank vs zero.** A blank cell has no stored value and is displayed blank. A blank *required* input (the fixed salary) makes every dependent result **unavailable** («مدخل ناقص: الأجر المقطوع»). A blank *optional* input counts as zero in calculations but is never overwritten with 0. Unavailable results show «—» (never 0) and are excluded from totals; the number excluded is stated.
- A formula error (division by zero; overflow ≥ 10¹⁵) shows «خطأ» at the affected cell with the reason in the tooltip and formula bar; it never silently becomes zero and never touches unrelated columns.
- Template columns keep their meaning: only label, order, visibility and group-level presentation can change; their formulas cannot be edited.

## 6. Columns and formulas

Button **إدارة الأعمدة والمعادلات** opens the manager (tabs: الأعمدة · عمود جديد/تعديل · الإعدادات العامة). Everything below runs through the same infrastructure as the workbook template: the template is simply seeded columns.

- **Column types:** نص، رقم، مبلغ (ل.س)، نسبة مئوية. **Fill:** manual input or a formula (a formula column is number/amount/percent). Per column: Arabic name (unique among columns and settings), group, display order, visibility in the grid / in exports / in the compact view, aggregation (`مجموع` or none — **percentages are never auto-summed**), blank-as-zero, allow-negative, warn-on-negative.
- **Stable internal ids.** Every column has an immutable key; formulas are stored with keys (`{fixed_salary} * {insurance_rate}`) and shown with readable names (`[الأجر المقطوع] * [نسبة التأمينات]`). Renaming, hiding, reordering or moving never breaks a formula or moves a value (tested).
- **Language.** `+ − * /`, parentheses, percentage literals (`10%`), comparisons (`= <> < <= > >=`), `SUM`, `IF`, `MAX`, `MIN`, `ROUND` (digits 0–6), numbers, quoted text, same-row column references and global settings. Arguments are separated by `,`, `;` or `؛`; Arabic digits and `٫ ٪ × ÷ −` are normalised. Nothing else: no other functions, no cross-employee references, no macros, no external links, no `eval` (a hand-written tokenizer and recursive-descent parser build an AST that the server validates and evaluates).
- **Typing and limits.** Static type checks (text vs numeric, `IF` branch types, `ROUND` digits must be a constant). Rejected with a reason: unknown references, circular dependencies (reported as a cycle), unsupported functions or characters, incompatible types, formulas over 600 characters, 120 nodes or depth 24, dependency chains over 30, more than 60 custom columns.
- **Evaluation semantics (identical on the server, in the browser and in Excel):** a reference to a blank required input or to an unavailable result makes the formula unavailable — decided over **every** reference of the formula, whichever `IF` branch would run; a referenced error propagates; `IF` evaluates only the chosen branch (so `IF([ب]=0, 0, [أ]/[ب])` is safe).
- **Shared vectors.** `backend/tests/Fixtures/payroll_formula_vectors.json` (38 formula cases including the AST) is asserted by PHPUnit against the server engine and by `node --test` against the browser engine; `payroll_template_reference.json` (8 employees) does the same for the whole workbook template. They cannot drift without a test failing.
- **Autocomplete and preview.** Typing `[` suggests columns and settings by name; letters suggest functions. A live preview against a selected employee shows that employee's value (or the error/missing reason) and the effect on the total net payable, the number of employees whose result changes and any new unavailable results — computed on the server over the saved values, **before anything is saved**.
- **Calculated cells are read-only:** typing, paste, clear and F2 on a calculated column are refused (a paste refuses the whole block), and the server rejects any submitted value for a calculated column.
- **Deletion.** Template columns cannot be deleted (hide them). A custom column that formulas use cannot be deleted: the manager lists the formulas and offers no confirm button. A column that holds values needs a second explicit confirmation stating how many values will be deleted.
- **Adding a custom column alone never changes net payable** (tested).
- **Atomic and attributable.** Every configuration change (column, layout, settings) is one transaction that locks `payroll_config`, checks the revision, writes, bumps the revision and records the authenticated editor (`updated_by_user_id`). A stale revision is a 409 `payroll_config_conflict` that writes nothing.
- **Settings.** Edited as plain numbers (percentages as 0–100), previewed on all employees, saved atomically; the whole sheet recalculates.

## 7. Workspace

- **Layout.** A slim header (title, actions), one toolbar row (search and filters; on a phone the filter selects sit behind one button), a second row (view switch, save state, undo/redo and the formula bar), then the grid, which takes the remaining screen height, and one summary line. No nested cards, banners or permanent notices.
- **Groups.** A header band groups the columns: بيانات الموظف · الراتب · التعويض · الاقتطاعات · الصافي.
- **عرض مختصر / عرض تفصيلي.** Two views of the same data. Compact: number, name, body, workplace, the major inputs (fixed salary, compensation, other deductions), total deductions and total net payable. The choice is remembered in the browser. A column's membership in the compact view is part of the configuration.
- **Formula bar:** for the selected cell shows the column's formula in readable names and the value, or the reason a result is unavailable / an error / a warning.
- **Totals footer:** pinned inside the grid, aligned with the columns, always visible while scrolling; a `*` marks totals that exclude unavailable records, with the count in the tooltip and in the summary line.
- **Editable vs computed** is distinguished sparingly: inputs have a faint underline, computed cells a faint green tint and `ƒ` in the header; unavailable `—`; errors red; warnings amber.
- **Identifiers stay visible** while scrolling (employee number and name are frozen; on a phone only the number).
- Spreadsheet behaviour (cell editors, Excel clipboard, range operations, undo/redo, filtering, sorting by any column including calculated ones, conflicts) works for custom columns exactly as for the template. Percent columns accept `7` or `7%`; pasted `ل.س`, thousands separators and Arabic digits are normalised; a pasted `$` is rejected. Column widths the user dragged survive re-renders.

## 8. Home

Heading «نظرة عامة»; primary button «فتح كشف الرواتب». The principal figure is the **total net payable**, followed by the employee count, the complete and needing-attention counts (links to the filtered payroll), a compact breakdown (gross entitlements, insurance, income taxes, other deductions, net), summaries **by body** and **by workplace** (count and net payable; each row opens Payroll with that filter) and a list of records with missing inputs or calculation errors. A total that leaves out incomplete records says so and how many; incomplete records are never shown as healthy zeros. No trends, month selector, charts or unrelated metrics, and no new sidebar item. Home and the grid use the same calculator and totals (a test compares them).

## 9. Saving, conflicts, undo

Each operation (typed edit, paste, clear, undo, redo) is **one atomic request** to `PATCH /api/v1/owner/payroll/values`, keyed by employee id and the row's `expected_revision`, carrying the `config_revision` the figures were calculated under. Invalid → 422 and nothing written; a stale row → 409 `payroll_conflict`, nothing written; a stale configuration → 409 `payroll_config_conflict`, nothing written. Failed or conflicting operations halt the queue and keep the entered values visible (marked, never shown as saved):

- **Row conflict:** keep mine and re-save, or use the server's values.
- **Configuration conflict:** load the current configuration and re-apply my values (values for a column that no longer accepts input are dropped **and reported by name**), or discard.
- **Failure:** retry (a retry whose first attempt actually succeeded is recognised, not treated as a conflict) or discard.
- Undo/redo (Ctrl+Z / Ctrl+Y and buttons) use the same endpoint and rules. Leaving with unsaved edits is guarded.

## 10. Exports

`GET /api/v1/owner/payroll/export/xlsx|pdf` take the same filters and sort as the grid and export all matching rows in the displayed order from **one transaction** (values, settings, formula definitions and totals). The UI exports the dataset **on screen** (disabled while the controls are ahead of the grid), waits for pending saves and refuses while an edit is failed or conflicting. Scope, order, timestamp, «كل المبالغ بالليرة السورية» and «ورقة عمل حالية» are printed in the files.

- **Excel:** real `.xlsx`, right-to-left, Arabic headings with a group band, employee numbers as **text** (leading zeros kept), inputs as numbers formatted `#,##0.00 "ل.س"` (percentages `0.##%`), blank = empty cell; columns follow each column's **export** flag and the saved order. A labelled **settings block** (rows 4–5: label above value) holds the three settings and the formulas reference those absolute cells. Calculated columns are **real Excel formulas** equivalent to the application formulas, e.g. `=IF(OR(I8=""),"",ROUND(ROUND((I8*$A$5),6),2))`, so changing an input or a setting recalculates the sheet. A column that an exported formula needs but that is not exported is included as a **hidden helper column**. Totals are `=SUMIF(range,">-1E+16")`, which skips blank/unavailable/error cells like the application's totals, followed by a note stating how many records are excluded. User text is always a literal string (quote-prefixed when it starts with `= + - @`).
- **Float noise.** Excel computes in binary floating point. Each formula is rounded to 6 decimals first and then to the column's decimals, which removes representation noise so exact ties round like the application (`4539.5*0.15−680.93` is `−0.005`, not `−0.00499999999999545`; found by the LibreOffice comparison and fixed).
- **PDF:** TCPDF, embedded Cairo, Arabic shaping/RTL, A3 landscape, the selected export columns, a repeated group band and headings on every page, «صفحة X من Y», totals per table and the exclusion note. A wide report is split into **column groups** (each repeats the employee number and name, labelled «الجزء n من m») instead of shrinking the type; unavailable calculated cells print «ناقص», errors «خطأ».
- Verified by recalculating the real Excel formulas in **LibreOffice** (cached results stripped first) against the server for every calculated cell and the totals, and by rendering the PDF pages (`docs/images/owner-payroll/export-pdf-page-*.png`).

## 11. Grid dependency and license

`@revolist/revogrid` + `@revolist/react-datagrid` **4.28.0 (MIT)** (RTL, range selection, frozen columns, resizing, keyboard navigation, in-cell editor, column groups, pinned totals row), selected after `react-datasheet-grid` (no resizing/RTL) and `react-data-grid` (no range selection) were rejected. Verified in Chromium, not assumed. Handled in the application layer:

- Tab/Enter order is not RTL-aware → Tab goes to the cell on the **left**, Shift+Tab right, Enter down, Shift+Enter up, also while editing.
- Its clipboard plugin is off (`useClipboard={false}`); autofill and range-edit events are cancelled: copy/paste/clear are implemented in the application so every multi-cell write is validated first.
- Cancelling its `beforeedit` leaves its keyboard state stuck, so inputs use a **custom cell editor** (`valueEditor.js`) that refuses an invalid value where it is typed.
- Its sorting/filtering are unused (the server sorts and filters so grid, totals and exports share one order).
- **Limitation:** a range cannot cross the boundary between the frozen and the scrolling columns, nor include the totals footer.

Licenses: RevoGrid MIT, TCPDF LGPL-3.0-or-later, PhpSpreadsheet MIT, brick/math MIT, Cairo font SIL OFL 1.1 (`backend/resources/pdf-fonts/OFL.txt`; the shipped TTFs have only their cmap extended for isolated Arabic presentation forms).

## 12. Review findings fixed (each has a regression test)

1. **Uncertain save → "discard" showed stale values.** `useServer()` now **reloads the authoritative rows first**; pending values are dropped only after that succeeded, the authoritative revision is adopted, and if the reload fails nothing is discarded and the error stays visible. Tests: controller (a save that succeeded but lost its answer is shown after discard, and the next edit does not conflict; a failing reload keeps everything) and the real browser run with a dropped response.
2. **Filter/sort changed while a save fails.** The controls hold the *desired* query; the grid, totals, scope label and exports describe the *shown* dataset. The desired query is applied automatically after retry/resolution while the page says it is waiting, exports are disabled and the filter controls are locked during an unresolved failure. Tests: source contracts and a browser run (save held, then failed, filter chosen meanwhile, retry → filter applied).
3. **Body assignment race.** The selected body is read `FOR UPDATE` inside the same transaction as the employee create/update. Tests: statement-order test, and `tests/Support/owner_payroll_concurrency.php` on MariaDB holds a deactivation in flight while three writers arrive — all are refused; **with the lock removed the same script fails** (checked).
4. **Academic-level blank filter sentinel.** The API takes a boolean `academic_level_blank`; the page's option values are prefixed (`level:…` / `none`), so a level literally named `__blank__` is an ordinary value (this also removed a duplicate-key React warning that the browser run exposed). Tests: API test, node test, browser run with both kinds of employees.

## 13. Visual references (frontend/AGENTS.md)

Reused rendered components: the `PageHeader` look (gradient bar), `MinistryUi` (`Notice`, `StatePanel`), the `DataTable` header look (dark band, white bold labels, in `payrollGrid.css`), `ManualGradeDialog`, `hr-dashboard/pages/AddEmployeePage.jsx` field styling and `DashboardLayout`. The toolbar is intentionally more compact than `FilterBar` so the grid keeps the height. Compared against `docs/images/ministry-portal/desktop-01-home.png` and the previous screenshots of this portal. Synthetic-data screenshots (live backend, real Chromium) are in `docs/images/owner-payroll/`: Home, wide and compact payroll, invalid paste, failed save, row conflict, configuration conflict, column manager, formula editor with preview, delete protection, settings impact, bodies and employee dialogs, phone layouts, PDF pages.

## 14. Verification

| Check | Result |
|---|---|
| `OwnerPayroll*Test` (7 classes: access/employees/bodies/sheet/Home, calculation, configuration, values, exports, migration, shared formula vectors; 47 tests) | pass on in-memory SQLite and on MariaDB 10.11 (isolated instance); one SQLite-trigger test is skipped on MariaDB |
| `tests/Support/owner_payroll_concurrency.php` on MariaDB | 14/14: one winner per revision, one row per employee number across 8 processes, opposite-order batches never deadlock, one winner per configuration revision, values-vs-configuration, body deactivation in flight; the body checks fail when the lock is removed |
| Frontend `node --test tests/*.test.mjs` | 386/386 (the owner logic test covers decimals, parsing, SYP formatting, the 38 shared vectors, the template fixture, clipboard, controller incl. review finding 1) |
| `tests/browser/owner-payroll.mjs` (real Chromium, real API, desktop 1440/1900 and 390 px) | 189/189 (Home, grouping, compact/detailed, editing, keyboard, clipboard, undo/redo, sort/filter, failure/conflict/config-conflict, review findings 1, 2 and 4, column manager and formula flows, exports recalculated in LibreOffice, PDF, dialogs, access, phone) |
| Excel (real formulas recalculated in LibreOffice) vs server | 55/55 calculated cells and all totals equal (an exact-tie rounding difference found here was fixed, section 10) |
| PDF rendered with `pdftoppm` | inspected: Arabic shaped, RTL, groups and headings repeated, SYP figures, exclusion note, column-group pages |
| `npx eslint` on every changed/new frontend file; `vendor/bin/pint` on changed PHP files | clean (`routes/api.php` and the compact browser-fixture script were not pint-formatted before this work either and are untouched in style) |
| `vite build` | passes (existing large-chunk advisory) |
| Full backend suite | The suite cannot run as one process: `AcademicCalendarPhase5OccurrenceResponseTest` fatals on PHPUnit 12 (`final` method override). Each file was run separately and compared with a clean `origin/develop` worktree: **94 pass; the same 44 files fail or crash as on clean `origin/develop`; no new failure.** One of them, `SupplementaryExamEndToEndHardeningContractTest`, is a historical branch-wide guard that forbids any `database/migrations` change in a diff and cannot coexist with the migrations this task requires (documented earlier; not weakened). |

Commands:

- Backend: `cd backend && php artisan test tests/Feature/OwnerPayrollTest.php` (and the other `OwnerPayroll*Test.php` classes); on MariaDB add `DB_CONNECTION=mysql DB_HOST=… DB_DATABASE=<scratch>`.
- Concurrency: `OWNER_PAYROLL_FIXTURE_CONFIRM=disposable … php tests/Support/owner_payroll_concurrency.php` (after the fixture script on a disposable database).
- Regenerate the shared vectors after an intentional engine change: `UPDATE_PAYROLL_VECTORS=1 php artisan test tests/Feature/OwnerPayrollFormulaVectorsTest.php tests/Feature/OwnerPayrollCalculationTest.php`.
- Frontend: `cd frontend && node --test tests/ownerPayrollLogic.test.mjs tests/ownerPortal.test.mjs`.
- Live browser run: `tests/browser/owner-payroll.mjs` (the header comment lists the steps; the fixture `backend/tests/Support/owner_payroll_browser_fixture.php` refuses to run unless the database name says scratch/test/dev and `OWNER_PAYROLL_FIXTURE_CONFIRM=disposable`; the run also uses LibreOffice and poppler to check the exports).

## 15. Deferred (not implemented)

Monthly cycles, period selection, approval, closing, payment execution, history and carry-forward. The sheet is labelled everywhere as the current working sheet.

## 16. Concrete limitations

- Excel computes in binary floating point; the 6-decimal guard removes ordinary noise, but for results above about 10⁹ an exact-tie case can still differ from the application's exact decimals by one unit of the last place. The application (grid, Home, PDF) is authoritative.
- Template formulas cannot be edited (only label, visibility, order); to change the calculation add custom columns and hide template ones. Custom formulas cannot reference another employee.
- A column that already holds values can only switch between number and amount.
- A range selection cannot cross the frozen/scrolling boundary or include the totals row.
- The sheet loads all matching rows in one request and calculates them in PHP (fine for hundreds to low thousands of employees); sorting by a calculated column is done in PHP after calculation.
- Only the Cairo regular and bold weights are embedded in the PDF; very long custom text wraps rather than shrinks.
- RevoGrid mounts the cell editor a tick after an edit starts: typing and an immediate Enter/Tab are handled, but F2 followed by Escape within the same instant can leave the editor open until Escape is pressed again.
- The percentage and exemption values come from the reference workbook; the application does not assert they match any current regulation.
- Development in this environment used PHP 8.3 (production runs 8.4): a real HTTP server needed a local `request_parse_body()` shim that is **not** part of the repository.
