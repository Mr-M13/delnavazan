# Academy operational cashbook

## Purpose and source model

The uploaded `Delnavazan.xlsx` separates a summary from an Australia account and an Iran account. The Australia sheet records AUD income and expenses by date, description and category. The Iran sheet records toman receipts and payments. A transfer appears as an Australian expense and an Iranian receipt, but the workbook does not retain a linked transfer, its exchange rate or its evidence reference.

The platform cashbook keeps that familiar two-account workflow while recording one auditable fact set. It does not import the sample rows or assume a starting balance: the workbook's summary starts at zero, while the account pages contain activity without a verified cutover balance. Each account may receive one explicit, evidence-backed opening-balance entry when the operator knows the cutover amount.

## Finance authority boundary

This is an operational cashbook inside the existing Finance application and reuses its command idempotency and transaction boundary. It has its own `finance_cash_*` tables and `academy_cashbook_v1` command domain; it does not expand or alter the existing teacher payability, lesson snapshot, statement or correction tables. It does not create a second payment processor and never reads or writes Stripe payment-execution records.

Only administrators with the dedicated cashbook view/manage capabilities can open or change the ledger. Teacher and Student roles are explicitly kept without those grants.

## Accounts and exact amounts

| Account | Stored unit | Entry precision |
| --- | --- | --- |
| Australia | AUD | Integer cents; shown as dollars and cents |
| Iran | TOM | Integer tomans; no fractional tomans |

`TOM` is the cashbook's internal label for the toman amount the operator enters. It is not converted to rial and is never combined with AUD in a single total. Persian and Arabic digits are accepted in amounts. All posted amounts are positive integer units with a separate direction; balances are calculated as the sum of signed account entries.

Income, expenses and one opening balance are recorded directly against one account. Categories remain editable text so the academy can use its existing labels, such as Delnavazan, books, advertising, website, lessons, instruments, teacher share or postage.

## Transfers and historical exchange rates

A conversion from Australia to Iran is one command that appends:

1. An AUD outflow to the Australia account.
2. A TOM inflow to the Iran account.
3. A transfer record containing both exact amounts, the transfer date, a `TOM per AUD` rate, a description and an evidence reference.

The two ledger entries and their command record commit together. No live FX service participates. The submitted rate is retained as entered to eight decimal places and is never recalculated from today's rate. Transfer legs change account balances but are excluded from earned/spent category reports. Fees are entered as separate expenses in the account where they were charged.

## Audit, corrections and reconciliation

Posted transactions, conversions, command facts and reconciliation snapshots are append-only. Each command records a keyed idempotency digest, a payload digest, operator and UTC record time. Evidence references are retained as operator-readable labels and one-way digests; supporting documents remain in the academy's document storage.

A mistaken income or expense is corrected by appending an equal and opposite reversal, then recording the correct fact if needed. Since each account has one opening entry, correct an opening balance by reversing it and appending a reasoned, evidence-backed balance adjustment for the corrected difference. Balance adjustments change only the balance and are excluded from earned/spent reporting. A conversion is reversed as a single paired operation, which restores the AUD side and removes the TOM side in one transaction. Original entries and their historic rates remain unchanged. Monthly income/expense totals attribute a single-entry reversal back to the original category, while paired transfers and their reversals remain outside those reports.

Statement reconciliation records the ledger balance as of a date, the externally observed balance, their signed difference and an evidence reference. It does not adjust account balances. A correction to a reconciliation is a new comparison, preserving the earlier result.

## Reporting and scope

The admin page shows both currency balances, one selected account's activity, monthly income and spending by category, recent paginated ledger entries and recent statement comparisons. Currency totals remain separate throughout.

This is operational cash tracking. It does not determine tax treatment, prepare formal financial statements, establish a legal accounting policy, or replace Stripe and commercial payment authorities. It only records the income or spending the operator enters and does not claim those entries are complete.
