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
| Permissions (all mapped to `university_owner` only) | `owner_portal.access`, `owner_portal.home.view`, `owner_payroll.view`, `owner_payroll.employees.manage`, `owner_payroll.bodies.manage`, `owner_payroll.amounts.edit`, `owner_payroll.export`, **`owner_payroll.config.manage`** (add/edit/delete columns and formulas — including the final net-payable formula and its restore — and change the global settings) |

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
- Template columns keep their meaning: only label, order and visibility can change, and **their formulas cannot be edited — with one deliberate exception, the final «إجمالي الصافي المستحق» formula (below)**.

### The authoritative net payable is editable

`total_net_payable` is the figure Home, the totals, the by-body/by-workplace summaries, the completeness status, sorting and both exports call *net payable*. A user with `owner_payroll.config.manage` can change **only its formula** (manager → الأعمدة → تعديل «إجمالي الصافي المستحق»), so a custom column takes part exactly when the formula names it:

`[صافي الراتب] + [صافي التعويض] - [حسميات أخرى] + [مكافأة إضافية] - [حسم إضافي]`

With the reference employee and custom amounts 1,000 and 250 the net payable becomes **125,916.30 ل.س** (and stays 125,166.30 as long as the formula does not mention them — adding a column alone changes nothing). A custom amount is never silently treated as taxable compensation or as a deduction: the other figures (gross entitlement, insurance, taxes, total deductions, net salary/compensation) keep their own definitions, so total deductions stay 26,633.70 in that example.

- Protected: the stable key, amount type, calculated/read-only nature, aggregation role, group; it cannot be deleted, turned into an input, or receive values (the server answers 422/409). Every other template column's formula stays locked (a formula sent for one is a 422).
- The same restricted parser, static type checks, cycle detection (a formula naming the net payable itself, or a custom column that uses it, is reported as a cycle), complexity limits, **impact preview before saving** (the reference employee's before/after and the effect on the total), configuration revision, atomic transaction, editor attribution (`updated_by_user_id`) and conflict handling (409 `payroll_config_conflict`).
- **«استعادة معادلة القالب»** (`POST /payroll/config/columns/total_net_payable/restore-formula`) restores the default canonical formula; settings, employee values and custom columns are not touched. The default is defined once in code (`App\Services\Payroll\PayrollTemplate::NET_PAYABLE_FORMULA`); the API sends its readable form (`template_formula_display`, plus `is_template_default`/`formula_editable`) and the UI shows exactly that — no duplicated string. The migration's seed is a historical snapshot and a test asserts it equals the constant.
- A custom column that the net formula uses cannot be deleted (the manager lists the dependent and offers no confirm button) until the formula is changed or restored.
- The edited formula is what the server calculates, what the browser uses for pending edits and totals, what the impact preview compares, and what Excel/PDF export (the Excel formula is a real formula; custom columns it needs but that are not exported travel as hidden helper columns). Tested end to end, including a LibreOffice recalculation of the exported workbook against the server.

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
- **Rounding and Excel's binary doubles.** The application rounds an amount column **once**, half away from zero, directly from the exact value (2 decimals for amounts, 6 for numbers/percentages; division keeps 10 decimals; an explicit `ROUND` is honoured). Excel computes in binary floating point, so `0.07` or `4539.5*0.15` are only approximate and an exact tie can land on either side of the boundary. An earlier translation snapped every result to 6 decimals before the column rounding; that double rounding moved a non-tie across the half-cent boundary (`0.499999 * 1%` = 0.00499999 → application 0.00, Excel 0.01), also for small values. The translation now uses the **exact decimal scale every sub-expression has, known statically** (literal = its decimals; `a+b` = max; `a*b` = sum; `a%` = +2; `a/b` = 10; `ROUND(x,d)` = d; a reference = the declared scale of its column or setting) and snaps a value to *its own* scale with `ROUND(x, scale)` only where binary noise could matter (operands of `*`, `/`, comparisons, `ROUND`, `MAX`/`MIN`, and the final value). A value with at most that many decimals is changed only by its noise, never moved across a boundary; the column's own rounding then runs once on the clean value. Examples: `0.499999*1%` → `=ROUND(ROUND((0.499999*(1/100)),8),2)`; `4539.5*0.15-680.93` → `-0.005` → `-0.01`; division → `ROUND(a/b,10)` then the column rounding. Totals are `=ROUND(SUMIF(...),scale)`.
- **Verified boundary (honest limit).** A double carries about 15 significant digits. Across about 11,400 randomly generated cells recalculated in LibreOffice (products of 2–3 factors, quotients, nested expressions, explicit ROUND, amount and 6-decimal number outputs, magnitudes from 0.01 to 999,999,999.99, results up to 10¹⁵) 18 cells differed from the application, and every one fell into one of two cases; the export **flags exactly those cells** instead of claiming equality: (a) the rounded result needs more than 15 significant digits (for an amount: 10¹³ or more), or (b) the exact pre-rounding value lies closer to a rounding boundary than the formula's accumulated floating-point error (conservatively 3·10⁻¹⁴ of the largest intermediate magnitude). Flagged cells (and the total of their column) are filled orange and a note under the totals lists them («تنبيه دقة Excel … المرجع هو التطبيق»); no unflagged cell differed in any run (the random sweep itself is a throw-away script, not a committed test; the committed tests are the structured, tie and near-tie cases). Comparisons (`IF`/`MAX`/`MIN`) are exact within 15 digits as well. Below the boundary the recalculated workbook equals the application cell for cell and total for total. The cached results written by PhpSpreadsheet are not what is compared: tests strip them and recalculate the real formulas in LibreOffice.
- **PDF:** TCPDF, embedded Cairo, Arabic shaping/RTL, A3 landscape, the selected export columns, a repeated legend, **dark group row and light heading row on every page and every part** (section 13.1), «صفحة X من Y», totals per table and the exclusion note. A wide report is split into **column groups** (each repeats the employee number and name, labelled «الجزء n من m») instead of shrinking the type; unavailable calculated cells print «ناقص», errors «خطأ».
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

`frontend/AGENTS.md` requires named, current reference pages and their real rendered components. What was inspected, how, and when:

**Newly verified for PR #158 (source read and rendered on desktop 1440 px and phone 390 px; measurements are computed styles from the rendered pages).** The reference page is the HR employee list, route `/hr/employees` (`frontend/src/app/App.jsx:328`, page `frontend/src/features/hr-dashboard/pages/EmployeesPage.jsx`), because it is a current page that renders the shared table and filter components together inside the shared shell. Its data call goes to a hard-coded production host, so the run **intercepted that host and served 8 synthetic employees** (production was never contacted; the local fixture database served the owner pages). Screenshots: `docs/images/owner-payroll/ref-hr-employees-desktop.png`, `ref-hr-employees-mobile.png` (reference, **synthetic data**) and `ref-owner-payroll-desktop.png`, `ref-owner-payroll-mobile.png` (this feature, **live local backend, synthetic data**).

| Reference (repository path) | Rendered on | Comparison with the owner payroll page |
|---|---|---|
| `frontend/src/components/table/DataTable.jsx` | `/hr/employees` | Header band `bg-text-dark` = `rgb(26,46,16)`, white 90 % text, 12 px / 700, cell padding 16 × 14 px; card radius 16 px, shadow `0 2px 16px rgba(26,46,16,.06)`. The grid card has the same shadow and radius (16 px; 12 px below 820 px) and the group row keeps the same idiom (dark band, white bold labels) but is **not identical**: `rgb(31,61,18)`, 13 px / 800. `DataTable` has a single header row; the light column row under the group row is feature-specific (two header levels were requested) and has no shared counterpart. |
| `frontend/src/components/table/FilterBar.jsx` | `/hr/employees` | Search field 14 px, radius 13 px, green border (1.5 px in the source, computed as 1 px). The owner toolbar's search/select/buttons are deliberately more compact (12.5 px, radius 10 px) so the grid keeps its height; they are inline controls, not `FilterBar`. |
| `frontend/src/components/layout/DashboardLayout.jsx` (with `DashboardHeader.jsx`, `DashboardFooter.jsx`) | both pages | Same sidebar, top bar and footer on desktop; same collapsed menu button and stacked layout on the phone. The owner page adds nothing to the shell. |
| `frontend/src/features/ministry-portal/components/MinistryUi.jsx` — `PageHeader` | read as source | The owner page header is an **inline reproduction** of the `PageHeader` look (gradient bar, white card) with tighter spacing (`px-4 py-2.5`, radius 16 px, title 18 px, versus `px-7 py-[20px]`, radius 18 px, 20 px) so the grid keeps the height. It does not import the component. This is a deliberate deviation inherited from the first owner-payroll PR, not new in this PR; see the remaining findings. |
| `MinistryUi.jsx` — `Notice`, `StatePanel` | imported by `OwnerPayroll.jsx` | Used directly (no copy). Rendered in the owner page states (`docs/images/owner-payroll/04`–`06b`); not re-rendered on a ministry page this round. |
| `frontend/src/features/exam-board/components/ManualGradeDialog.jsx` | imported by `EmployeeDialog.jsx`, `BodiesDialog.jsx`, `OwnerPayroll.jsx` | Used directly. Rendered in the owner dialogs (`docs/images/owner-payroll/11`, `12`); not re-rendered on its original page this round. |

The measurement found one real defect that source inspection missed: the grid (header and cells) was rendering in RevoGrid's own `Nunito` fallback stack instead of Cairo, because `--revo-grid-font-family: inherit` resolves to nothing. `payrollGrid.css` now sets it to the application font token (`--font-sans`, Cairo); the rendered grid, header, cells and legend now all report `Cairo, "Segoe UI", sans-serif`.

**Previously inspected (earlier rounds of this feature; not re-opened for PR #158).** `docs/images/ministry-portal/desktop-01-home.png` and the other `docs/images/ministry-portal/*` pages (the earlier look comparison for Home and the toolbar), and `frontend/src/features/hr-dashboard/pages/AddEmployeePage.jsx` (field styling used for `EmployeeDialog`). These statements come from the earlier review, not from a fresh rendering.

Synthetic-data screenshots (live local backend, real Chromium) of this feature are in `docs/images/owner-payroll/`: Home, wide and compact payroll, invalid paste, failed save, row conflict, configuration conflict, column manager, formula editor with preview, the editable net-payable formula, delete protection, settings impact, bodies and employee dialogs, phone layouts, the visual-hierarchy set `18`–`26`, the legend states `27`–`29` (all kinds, hidden calculated columns, hidden final net) and the rendered PDF pages `visual-pdf-*.png` (one-part report, a three-part wide report with a continuation page, and a grayscale rendering).

## 13.1 Visual hierarchy (grid and PDF)

Presentation only: no calculation, permission, data, sorting, clipboard, save/conflict, column-configuration or export-scope behaviour changed.

**Grid.**
- Two header rows: the **group row** (بيانات الموظف، الراتب، التعويض، الاقتطاعات، الصافي) is dark green with white bold text, a little taller, groups separated by white rules; the **column row** is light green with compact dark text and subtle separators. Sort arrows, spreadsheet focus, pinned/scrolling alignment and the sticky header are unchanged.
- **Input / calculated / read-only** follows the real column configuration *and* the current user's `owner_payroll.amounts.edit`: for an editor, input columns have a pencil (✎) in the header, a very light warm fill, an underline cue and a dashed rule when blank (no placeholder text); calculated columns have ƒ in the header and a light neutral fill and stay selectable/copyable; identity columns look normal (the ✎ edit-details button is unchanged). A view-only user sees inputs as plain white cells without pencil, and the legend says «قيم مدخلة (للعرض فقط)». A compact legend above the grid names «قابل للتعديل / محسوب تلقائيًا / الصافي النهائي»; markers carry the meaning, not colour alone. **The legend lists only the styles of the columns actually displayed**: it is derived by `legendEntries(columns, canEdit)` (`lib/payrollView.js`) from the very list `buildColumns` hands to the grid (after `visible_grid` and the compact view), so hiding inputs, ordinary formulas or the final net, leaving them out of the compact view, or switching view, updates it at once. The final-net entry is keyed on `total_net_payable`, and the net column alone never switches the ordinary «محسوب تلقائيًا» entry on.
- **Final net payable** is recognised by the stable key `total_net_payable` (never the label, so a renamed or moved column keeps it): dark-green header with white text, soft-green bold cells with stronger side rules, and a larger bold total in the pinned footer. A missing/error result stays grey or red and never wears the payable green; negatives stay red.
- Pending save (blue, italic), failed (red ring), conflict (amber ring), invalid and calculation warnings come later in the stylesheet than the ordinary fills and win over them. Keyboard focus is a 2 px dark-green ring on every fill; a selected range is a stronger green tint.

**PDF.** Three rows repeat on every page and every horizontal part: a one-line key («بيانات مدخلة» / «قيم محسوبة (ƒ)» / «الصافي النهائي», only the kinds present in that part), the dark **group row** (merged cells, spans computed per part) and the light **heading row**. Input columns get a warm heading with a firm rule under it, calculated columns a neutral heading marked ƒ, the net payable a dark-green heading, bold green cells and a larger bold total with a heavy top rule. Unavailable results print «ناقص»/«خطأ» on grey. The distinction survives a black-and-white print (rule thickness, ƒ, dark vs light headings, weight). Type size, Arabic shaping, RTL, SYP formatting and the repeated employee number/name per part are unchanged.

## 14. Verification

| Check | Result |
|---|---|
| `OwnerPayroll*Test` (9 classes: access/employees/bodies/sheet/Home, calculation, configuration, values, exports, migration, shared formula vectors, **net-payable formula**, **Excel precision**; 63 tests; the export class gained a test that renders the PDF and checks the colours of every page) | pass on in-memory SQLite and on MariaDB 10.11 (isolated instance); one SQLite-trigger test is skipped on MariaDB. The two LibreOffice-based classes recalculate the real exported workbooks (they skip, visibly, where `soffice` is missing) |
| `tests/Support/owner_payroll_concurrency.php` on MariaDB | 17/17 (adds: 8 writers of the net-payable formula → one winner, one complete formula, restore): one winner per revision, one row per employee number across 8 processes, opposite-order batches never deadlock, one winner per configuration revision, values-vs-configuration, body deactivation in flight; the body checks fail when the lock is removed |
| Frontend `node --test tests/*.test.mjs` | 401/401 (the owner logic test covers decimals, parsing, SYP formatting, the 38 shared vectors, the template fixture, the edited net-payable formula incl. pending-edit previews, clipboard, controller incl. review finding 1) |
| `tests/browser/owner-payroll.mjs` (real Chromium, real API, desktop 1440/1900 and 390 px) | 242/242 (adds a legend section — every kind, hidden inputs/formulas/net, compact exclusions, view-mode switch and column-manager changes without reload, renamed net, view-only wording — and the visual-hierarchy section — computed styles of the real rendering: dark group row vs light column row, net header found by its stable key after a rename, pencil/ƒ markers, three distinct fills, bold final payable, blank-input identification, legend, and a view-only user — plus editing the final formula through the manager with preview, rejection of cyclic/unknown formulas, locked type/group/aggregation, pending custom-column edit moving the net payable, Home, Excel recalculation and the restore action; Home, grouping, compact/detailed, editing, keyboard, clipboard, undo/redo, sort/filter, failure/conflict/config-conflict, review findings 1, 2 and 4, column manager and formula flows, exports recalculated in LibreOffice, PDF, dialogs, access, phone) |
| Excel (real formulas recalculated in LibreOffice) vs server | every calculated cell and total of the structured cases (0.00499999 → 0.00, −0.00499999 → 0.00, ±0.005 → ±0.01, ±0.00500001, the compensation-tax tie −0.01, six-decimal number × percentage products, division, nested, explicit ROUND, 6-decimal number/percent outputs, large values, the 125,166.30 reference, 24 tie/near-tie amounts) equal; ~11,400 random cells: 18 differences, all inside the flagged precision boundary (section 10) |
| PDF rendered with `pdftoppm` | inspected at 90 dpi, in colour and grayscale: a one-part report, a 6-page wide report in 3 parts (both header rows and the key repeat on every page and part, group spans correct per part, input/calculated fills, net-payable emphasis incl. the totals cell, unavailable results grey), Arabic shaped, RTL, SYP figures. `OwnerPayrollExportTest` samples the rendered pixels of every page (dark band over light heading row; net heading only in the part that has it) and fails on the previous renderer |
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

- Excel holds about 15 significant digits: results of 10¹³ or more (amounts) or values extremely close to a rounding boundary can differ from the application in the last digit. Such cells are flagged in the export (section 10); the application (grid, Home, PDF) is authoritative.
- Only the final net-payable formula of the template can be edited (and restored); every other template formula is protected — add custom columns and reference them from the final formula instead. Custom formulas cannot reference another employee.
- A column that already holds values can only switch between number and amount.
- The owner page header and toolbar are compact inline reproductions of the shared `PageHeader`/`FilterBar` looks, not the shared components (section 13); switching them would give up grid height and was left to a separate decision.
- The group label of a very wide group scrolls out of view with its columns (RevoGrid headers are not sticky horizontally); the column row below always stays labelled.
- The view-only look is verified with a user profile whose edit permission is removed (the server also refuses writes); only the legend and styling depend on it.
- PDF input columns are marked by a firm rule under the heading and the legend, calculated ones by «ƒ»; Cairo has no pencil glyph.
- A range selection cannot cross the frozen/scrolling boundary or include the totals row.
- The sheet loads all matching rows in one request and calculates them in PHP (fine for hundreds to low thousands of employees); sorting by a calculated column is done in PHP after calculation.
- Only the Cairo regular and bold weights are embedded in the PDF; very long custom text wraps rather than shrinks.
- RevoGrid mounts the cell editor a tick after an edit starts: typing and an immediate Enter/Tab are handled, but F2 followed by Escape within the same instant can leave the editor open until Escape is pressed again.
- The percentage and exemption values come from the reference workbook; the application does not assert they match any current regulation.
- Development in this environment used PHP 8.3 (production runs 8.4): a real HTTP server needed a local `request_parse_body()` shim that is **not** part of the repository.
