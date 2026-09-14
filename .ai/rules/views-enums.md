---
paths:
  - '{lang/**,resources/views/**,app/Enums/**}'
---

# Views Enums

## Every visible string goes through grouped lang files in en and ar
No literal UI text. Use __('group.key') / trans_choice('group.key') with keys in lang/en/<group>.php AND lang/ar/<group>.php (shared words in common.php, page titles in titles.php as #[Title('titles.x')], date patterns in formats.php via ->translatedFormat(__('formats.date'))). Enum display uses $case->label() (HasTranslatedLabel, lang/*/enums.php); stored values stay English. Arabic plurals use explicit ranges {0}|{1}|{2}|[3,10]|[11,*]. Never name a group file after a word Flux translates bare (results, of, to, files, showing): __('results') would return the whole array. lang/ar.json only holds vendor (Flux/Laravel) sentence keys. tests/Feature/LocalizationTest.php fails on missing keys, lost placeholders or missing enum labels.
