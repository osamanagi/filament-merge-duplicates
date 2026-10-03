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
        'audit_reference' => 'مرجع التدقيق',
        'view_audit' => 'عرض سجل التدقيق',
        'back_to_review' => 'العودة إلى المراجعة',
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
        'scan_confirm_heading' => 'بدء البحث عن السجلات المكررة؟',
        'scan_confirm_description' => 'يعمل البحث في الخلفية ويستبدل النتائج الحالية عند انتهائه.',
        'compare' => 'مراجعة سجلين',
    ],

    'merge' => [
        'title' => 'المقارنة والدمج',
        'heading' => 'دمج سجلات :label',
        'survivor_reason' => 'الموصى به: :reason',
        'survivor_heading' => 'السجل المُراد الإبقاء عليه',
        'survivor_hint' => 'سيتم إعادة السجل الآخر وسيتم نقل سجلاته التابعة إلى السجل الذي تُبقيه.',
        'keep' => 'الإبقاء على :title',
        'survivor_value' => 'القيمة الحالية في :title',
        'source_value' => 'القيمة في :title',
        'fields_heading' => 'مقارنة الحقول',
        'choice_required' => 'اختر قيمة',
        'proposed_from_source' => 'سيأخذ قيمة السجل الآخر',
        'choose_for' => 'اختر قيمة للحقل :label',
        'keep_survivor_value' => 'الإبقاء على قيمة السجل المُبقى',
        'take_source_value' => 'أخذ قيمة السجل الآخر',
        'value_on' => 'القيمة في :title',
        'status_identical' => 'متطابق',
        'status_different' => 'مختلف',
        'status_source_only' => 'يُملأ من السجل المُلغى',
        'status_survivor_only' => 'موجود في السجل المُبقى فقط',
        'status_empty' => 'فارغ في السجلين',
        'side_kept' => 'مُبقى',
        'side_dropped' => 'غير مُبقى',
        'side_same' => 'متماثل',
        'side_added' => 'منقول',
        'side_empty' => 'فارغ',
        'will_transfer' => 'ستُكتب هذه القيمة في :title.',
        'audited_marker' => 'مُدرج في التدقيق',
        'match_reasons_heading' => 'سبب تطابق هذه السجلات',
        'summary_compared' => 'تمت مقارنة :count حقل',
        'summary_identical' => ':count متطابق',
        'summary_different' => ':count مختلف',
        'summary_one_sided' => ':count في سجل واحد فقط',
        'summary_empty' => ':count فارغ',
        'relations_heading' => 'أثر العلاقات',
        'blockers_heading' => 'لا يمكن دمج هذين السجلين',
        'retirement_warning' => 'سيتم حذف :title حذفًا مؤقتًا ووسمه كمدموج. استعادته لاحقًا ليست إلغاءً للدمج.',
        'confirm' => 'تأكيد الدمج',
        'not_duplicates' => 'ليسا مكررين',
        'choices_required' => 'اختر قيمة لكل حقل مختلف قبل التأكيد.',
        'stale' => 'تغيّرت هذه السجلات بعد المعاينة. راجع الاقتراح المحدّث قبل التأكيد.',
        'failed' => 'لم يكتمل الدمج (رمز السبب: :code). لم يتغيّر أي شيء.',
        'succeeded' => 'تم الدمج في :title.',
        'configuration_title' => 'الدمج غير مُمكّن لهذا التعريف',
        'configuration_description' => 'هذا التعريف مُهيّأ للكشف فقط. لا يمكن دمج أي سجلات.',
        'succeeded_title' => 'اكتمل الدمج',
        'succeeded_body' => 'تم دمج السجلات في :title.',
    ],

    'audit' => [
        'title' => 'سجل الدمج',
        'heading' => 'سجل عملية دمج',
        'operation_label' => 'العملية',
        'unreadable_title' => 'تعذّرت قراءة هذا السجل',
        'unreadable_description' => 'تغيّر مفتاح التطبيق الذي يمكنه قراءة هذا السجل. الدمج نفسه غير متأثر.',
        'empty' => 'لا يحتوي هذا السجل على قيم قابلة للقراءة.',
    ],

];
