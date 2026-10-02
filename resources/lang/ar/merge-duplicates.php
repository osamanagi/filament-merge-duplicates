<?php

/*
|--------------------------------------------------------------------------
| Arabic translation
|--------------------------------------------------------------------------
|
| Community contribution. Treat it as reviewed for wording by a native speaker
| before it is relied upon; the English strings are the reference.
| Keys mirror the English file one for one.
|*/

return [

    'banner' => [
        'never_scanned_title' => 'لم يتم البحث عن السجلات المكررة بعد',
        'never_scanned_description' => 'شغّل البحث للكشف عن السجلات المكررة المحتملة في هذا المورد.',

        'scanning_title' => 'جارٍ البحث عن السجلات المكررة…',
        'scanning_description' => 'النتائج المعروضة من آخر بحث مكتمل، وسيتم استبدالها عند انتهاء هذا البحث.',

        'failed_title' => 'فشل آخر بحث عن السجلات المكررة',
        'failed_description' => 'ما زالت نتائج بحث سابق معروضة. أعد المحاولة عند الاستعداد.',

        'empty_title' => 'لم يتم العثور على سجلات مكررة محتملة',
        'empty_description' => 'اكتمل آخر بحث ولم يجد أي مجموعة قابلة للمراجعة.',

        'results_title' => ':count مجموعة مكررة محتملة|:count مجموعات مكررة محتملة',
        'results_description' => 'المجموعات اقتراحات وليست أحكامًا: قد يظهر السجل في أكثر من مجموعة.',

        'counts_visible_only' => 'يتم احتساب السجلات التي يمكنك عرضها فقط.',
    ],

    'labels' => [
        'last_scan' => 'آخر بحث مكتمل: :time',
        'never_completed' => 'لم يكتمل أي بحث في هذا النطاق',
        'reason_code' => 'رمز السبب: :code',
        'results_may_have_changed' => 'قد تكون النتائج تغيّرت منذ هذا البحث.',
    ],

    'actions' => [
        'review' => 'مراجعة السجلات المكررة',
        'scan' => 'البحث عن السجلات المكررة',
        'retry_scan' => 'إعادة المحاولة',
    ],

    'review' => [
        'title' => 'مراجعة السجلات المكررة',
        'heading' => 'السجلات المكررة: :label',
        'groups_total' => ':count مجموعة محتملة|:count مجموعات محتملة',
        'groups_heading' => 'المجموعات المقترحة',
        'records' => ':count سجل|:count سجلات',
        'stale_badge' => 'تغيّر بعد البحث',
        'not_reviewable_badge' => 'لا يمكن الدمج الآن',
        'member_missing' => 'مفقود',
        'member_retired' => 'تم دمجه',
        'member_changed' => 'لم يعد مطابقًا',
        'showing_of' => 'عرض :shown من :total سجلًا',
        'pagination' => 'صفحات مجموعات السجلات المكررة',
        'previous' => 'السابق',
        'next' => 'التالي',
        'page_of' => 'صفحة :page من :last',
        'empty_body' => 'اكتمل آخر بحث ولم يجد أي مجموعة قابلة للمراجعة.',
        'never_scanned_body' => 'لم يتم البحث في هذا المورد بعد. شغّل البحث للكشف عن السجلات المكررة المحتملة.',
        'scan_already_running' => 'يوجد بحث قيد التشغيل بالفعل في هذا النطاق.',
    ],

];
