# T32 data files · strategy pillars and Micro logframe

**Status: DRAFT, not approved by MEAL (D-37).** Do not run the imports on a shared or production database until MEAL signs below. Escalated to Dev 1 at CP2.

| File | Source | Content |
|---|---|---|
| `database/seeders/data/strategy-2026-2030.json` | `Stratigy.pptx` (2026–2030 strategic framework), slides 3, 6, 7, 9, 10, 12 | 3 pillars with Arabic name and goal; programs by HR id (D-35a) |
| `database/seeders/data/logframes/micro-urban.json` | `micro logic.xlsx`, sheet `Micro` | urban track: goal, 2 outcomes, 5 outputs, 16 indicators |
| `database/seeders/data/logframes/micro-agri.json` | `micro logic.xlsx`, sheet `زراعي رعوي` | agricultural track: goal, 3 outcomes, 6 outputs, cross-cutting, 21 indicators |

The JSON was extracted once with a throwaway script outside the repo. The app never reads `.xlsx`/`.pptx`.

## How the source maps to the JSON
- **Text** is copied as written. Only runs of spaces are collapsed. Typos in the source are kept (see the review list).
- **`ref`** is the sheet's indicator number (`G1`, `O1.2`, `Op1.1a`, `CC4`). It is for traceability only. Library codes are generated at import (`002.<level_ref>.<serial>`, D-10).
- **`measure`** comes from the indicator wording: `%`/`نسبة` → percentage, `متوسط` → average, otherwise numeric. At import, a ratio or `%` target turns the measure into percentage (D-37 table).
- **`target_raw`** keeps the sheet's symbol. The import maps it as follows:
  - `0.8` → 80 %;
  - `1` → 100 %;
  - `80% (…)` → 80 %, with the remark kept on the entry;
  - `-` → no target, tracking only;
  - `**` → no target, set per proposal;
  - `زيادة 20%` → 20 %, noted as a change from baseline.
- **`frequency`** is the closest `CollectionFrequency`. The sheet's own wording is kept in `frequency_note`. "After 12 months" becomes `follow-up` with `follow_up_months` 12 (the goal: 24).
- **`collected_by`**: «فريق المشروع» → project_team, «MEAL» → meal. The agricultural sheet has no collector column, so it is project_team.
- **`activities`**: the activities column split on `+`. At import they become `activity` results under the indicator's output.
- **Pillar English names** follow D-35. The deck has Arabic only. `goal_en` is empty.

## For MEAL to review before approval
1. **Count indicators with a ratio target.** These are worded as counts, but the sheet gives a ratio, so the import stores them as percentages:
   - urban OP1.2a «عدد المستفيدين الذين أكملوا التدريب…» (`80% ( مشاريع جديدة و زراعية)`);
   - agri OP1.1a (`0.8`), OP2.1b (`1`), OP3.1 (`0.3`).
2. **Frequencies picked from free text:**
   - urban Op1.1a / OP1.1b «شهري / ربع سنوي» → monthly;
   - agri OP1.2b «كل شهرين» → monthly;
   - agri G1/G2 «خط أساس، كل موسم زراعي أو كل 6 أشهر» → per-season.
3. **No identical indicator in both tracks.** The closest pair is urban O1.2 «% المشاريع التي تحقق أرباحاً بعد 12 شهراً» and agri O2.3 «% المشاريع المدعومة التي تحقق أرباحاً تشغيلية بعد 12 شهراً». They stay two entries. Merge them by hand in the library if they are the same indicator.
4. **Typos kept from the sheet:**
   - «دراسة الالستمارات», «لاخذ القرار» (activities, both tracks);
   - «تريبات … خول» (agri OP1.1a activity);
   - «اصخاب عمل» (urban OP1.3b activity);
   - «واستبيانات» (urban Op2.1 activity);
   - «قيس» (urban OP1.3c definition).
5. **Calculation-method mismatch:** urban O1.1 is defined as "after 12 months", but its denominator says «منذ 24–36 شهراً» (copied from G1).
6. **Agri CC1** lists the activity «استبيانات». A cross-cutting result cannot hold activities, so the import skips it and says so.
7. **Programs missing in HR** (D-35a): Wells, Alternative Energy, PSS, Case Management, Parenting. They are listed with `hr_program_id: null` and reported as missing. When HR adds them, put their ids in the file and run the pillar import again.
8. **English names** of the indicators are left empty. MEAL completes them in the library.

## Approval
- [ ] Approved by MEAL: name, date
