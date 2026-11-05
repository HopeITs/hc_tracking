<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The starting risk library and the one idempotent rule that seeds it,
 * shared by the data migrations and the seeder (W-09, D-61).
 *
 * The list is the risk column of MEAL's «مصفوفة سياق المخاطر والافتراضات»
 * (Hope Centre Theory of Change 2026–2030), supplied by the developer on
 * 4 Nov 2026: the six context-level risks first, then the three pathways in
 * the order the matrix gives them. The matrix's assumptions and mitigation
 * measures have no column here — a library entry is a risk title in both
 * languages plus a grouping category, and the mitigation is written per
 * proposal on the attached risk (`risks.solution`).
 *
 * `category` is a single free-text column (no per-language pair), so these
 * are English and MEAL may rename them on /admin/risk-library.
 *
 * An entry counts as present when any row carries its English **or** its
 * Arabic title, so a renamed or deactivated default never comes back as a
 * duplicate. Query builder only: no model events.
 */
final class DefaultRiskLibrary
{
    /**
     * @return list<array{title_en: string, title_ar: string, category?: ?string}>
     */
    public static function all(): array
    {
        return [
            // The general and contextual level.
            ['title_en' => 'Sharp exchange-rate swings and price inflation', 'title_ar' => 'تقلبات حادة في سعر الصرف وتضخم الأسعار', 'category' => 'Context'],
            ['title_en' => 'Sudden logistical or security restrictions on movement', 'title_ar' => 'قيود لوجستية أو أمنية طارئة على الحركة', 'category' => 'Context'],
            ['title_en' => 'Severe decline of the public power network and fuel scarcity', 'title_ar' => 'تدهور حاد في شبكات الطاقة العامة وشح المحروقات', 'category' => 'Context'],
            ['title_en' => 'Renewed security tensions or local conflict in the areas of intervention', 'title_ar' => 'تجدد التوترات الأمنية أو النزاعات المحلية في مناطق التدخل', 'category' => 'Context'],
            ['title_en' => 'Sudden change of policy, regulatory frameworks or controlling authorities', 'title_ar' => 'تغيّرات مفاجئة في السياسات أو الأطر التنظيمية أو الجهات المسيطرة', 'category' => 'Context'],
            ['title_en' => 'Shifting donor priorities or tighter sanctions and banking restrictions', 'title_ar' => 'تبدّل الأولويات السياسية للجهات المانحة أو تشديد العقوبات والقيود المصرفية', 'category' => 'Context'],

            // Pathway 1: economic empowerment, livelihoods and infrastructure.
            ['title_en' => 'Weak purchasing power or failure of the funded businesses', 'title_ar' => 'ضعف القوة الشرائية العامة أو تعثر المشاريع', 'category' => 'Economic empowerment'],
            ['title_en' => 'Grant capital diverted to emergency household spending', 'title_ar' => 'تسرب رأس المال للنفقات الاستهلاكية الطارئة', 'category' => 'Economic empowerment'],
            ['title_en' => 'Falling water table, contaminated water sources or system breakdowns', 'title_ar' => 'انخفاض منسوب المياه أو تلوث المصادر المائية أو أعطال المنظومات', 'category' => 'Economic empowerment'],

            // Pathway 2: youth and students.
            ['title_en' => 'Demand beyond the capacity of the study centres', 'title_ar' => 'زيادة الطلب بما يتجاوز الطاقة الاستيعابية لمراكز الدراسة', 'category' => 'Youth and students'],
            ['title_en' => 'Stagnant hiring and low wages in the local market', 'title_ar' => 'ركود حركة التوظيف وتدني الأجور في السوق المحلي', 'category' => 'Youth and students'],
            ['title_en' => 'Trained young talent emigrating after qualifying', 'title_ar' => 'هجرة الكفاءات الشابة بعد تلقي التدريب والتأهيل', 'category' => 'Youth and students'],
            ['title_en' => 'Automation of the trades and skills the training targets', 'title_ar' => 'أتمتة بعض المهن والمهارات المستهدفة بالتدريب المهني والتقني', 'category' => 'Youth and students'],
            ['title_en' => 'A widening digital divide in access to AI tools', 'title_ar' => 'اتساع الفجوة الرقمية في الوصول إلى أدوات الذكاء الاصطناعي واستخدامها', 'category' => 'Youth and students'],
            ['title_en' => 'Training content ageing faster than it can be updated', 'title_ar' => 'تقادم محتوى التدريب بسرعة تفوق قدرة المركز والسوق على التكيّف', 'category' => 'Youth and students'],
            ['title_en' => 'Official or social limits on who may attend and on mixed delivery', 'title_ar' => 'فرض فصل ديمغرافي أو تغيّر الضوابط الاجتماعية بما يحدّ من وصول بعض الشباب والطلاب', 'category' => 'Youth and students'],

            // Pathway 3: childhood and positive parenting.
            ['title_en' => 'Cases needing long-term specialist mental health care beyond the project', 'title_ar' => 'حاجة بعض الحالات لرعاية نفسية تخصصية طويلة الأمد تفوق نطاق المشروع', 'category' => 'Children and parenting'],
            ['title_en' => 'Low parent participation because of livelihood pressures', 'title_ar' => 'ضعف مشاركة أولياء الأمور نتيجة الانشغال بتأمين المعيشة', 'category' => 'Children and parenting'],
        ];
    }

    /**
     * Insert the entries that are missing, after the existing ones.
     *
     * @param  list<array{title_en: string, title_ar: string, category?: ?string}>|null  $list  defaults to all()
     * @return int how many were inserted
     */
    public static function ensure(?array $list = null): int
    {
        $list ??= self::all();

        if ($list === []) {
            return 0;
        }

        return DB::transaction(function () use ($list): int {
            $existing = DB::table('risk_library')->lockForUpdate()->get(['title_en', 'title_ar']);
            $titles = $existing->pluck('title_en')->merge($existing->pluck('title_ar'))->filter()->all();
            $position = (int) DB::table('risk_library')->max('sort_order');
            $now = now();
            $inserted = 0;

            foreach ($list as $default) {
                if (in_array($default['title_en'], $titles, true) || in_array($default['title_ar'], $titles, true)) {
                    continue;
                }

                DB::table('risk_library')->insert([
                    'title_en' => $default['title_en'],
                    'title_ar' => $default['title_ar'],
                    'category' => $default['category'] ?? null,
                    'is_active' => true,
                    'sort_order' => ++$position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted++;
            }

            return $inserted;
        });
    }
}
