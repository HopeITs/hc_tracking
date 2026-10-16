<?php

return [
    'future_month' => 'لا يمكن إنشاء تقرير لشهر مستقبلي',
    'outside_period' => 'الشهر خارج فترة المشروع',
    'submit_previous_first' => 'أرسل تقرير :month أولًا',
    'submit_too_early' => 'يمكنك إرسال هذا التقرير ابتداءً من آخر يوم عمل في شهره',
    'value_required' => 'أدخل قيمة لكل مؤشر (صفر مسموح)',
    'zero_denominator' => 'المقام لا يكون صفرًا؛ اترك السطر فارغًا إن لم يُقَس شيء هذا الشهر',
    'above_fixed_denominator' => 'العدد أكبر من عدد مستفيدي المشروع في المقترح (:count)',
    'disabled_above_gender' => 'عدد ذوي الإعاقة أكبر من عدد الذكور/الإناث',
    'not_measured' => 'لم يُقس هذا الشهر',
    'locked' => 'التقرير مُرسَل ومقفل',
    'stale' => 'تغيّرت حالة التقرير للتو، أعد التحميل',
    'console' => [
        'backfill_row' => 'المشروع :project: أُنشئ :created شهرًا، و:skipped موجودة مسبقًا، وبُني :lines سطرًا',
        'prepare_row' => 'المشروع :project: بُني :lines سطرًا لهذا الشهر',
        'dry_run' => 'تشغيل تجريبي: لم يُكتب شيء',
        'invalid_from' => 'استخدم --from=YYYY-MM، مثل --from=2026-10',
    ],
];
