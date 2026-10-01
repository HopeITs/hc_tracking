---
paths:
  - '{app/Support/AgreementMoney.php,app/Support/Tranches.php,app/Models/TransfersTracking.php}'
---

# Support Models

## Funding money has one definition
Scheduled / received / outstanding / not scheduled are defined only in AgreementMoney and TransfersTracking::stateSql(). Never recompute a money figure inline on a page or component; call these. Currencies are never added together — totals are always one line per currency.
