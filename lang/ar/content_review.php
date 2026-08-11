<?php

return [
    'messages' => [
        'review_requested' => 'تم طلب مراجعة المحتوى.',
        'review_cancelled' => 'تم إلغاء مراجعة المحتوى.',
        'review_forced_manual' => 'تم تحويل المحتوى إلى المراجعة اليدوية.',
        'settings_published' => 'تم نشر إعدادات مراجعة المحتوى.',
        'policy_published' => 'تم نشر سياسة مراجعة المحتوى.',
        'provider_tested' => 'تم فحص الاتصال بمزود المراجعة.',
        'review_fetched' => 'تم جلب مراجعة المحتوى.',
        'recommendation_confirmed' => 'تم تأكيد توصية الذكاء الاصطناعي.',
        'recommendation_overridden' => 'تم اتخاذ قرار مخالف لتوصية الذكاء الاصطناعي.',
        'reviews_fetched' => 'تم جلب مراجعات المحتوى.',
        'metrics_fetched' => 'تم جلب مؤشرات مراجعة المحتوى.',
        'no_review' => 'لا توجد مراجعة آلية لهذا المحتوى.',
    ],

    'errors' => [
        'provider_timeout' => 'لم يستجب مزود المراجعة في الوقت المحدد.',
        'provider_rate_limited' => 'مزود المراجعة يحد من عدد الطلبات حاليًا.',
        'provider_unavailable' => 'مزود المراجعة غير متاح.',
        'provider_auth_failed' => 'رفض مزود المراجعة بيانات الاعتماد.',
        'invalid_structured_output' => 'أعاد مزود المراجعة نتيجة لا تطابق العقد المطلوب.',
        'content_unavailable' => 'تعذر تجهيز المحتوى للمراجعة.',
        'image_fetch_failed' => 'تعذرت قراءة صورة أو أكثر للمراجعة.',
        'budget_exhausted' => 'تم استنفاد ميزانية المراجعة الآلية.',
        'circuit_open' => 'تم إيقاف المراجعة الآلية مؤقتًا لأن الخدمة غير مستقرة.',
        'policy_missing' => 'لا توجد سياسة مراجعة فعالة منشورة لهذا النوع من المحتوى.',
        'subject_not_reviewable' => 'هذا المحتوى ليس في حالة قابلة للمراجعة.',
        'content_changed' => 'تم تغيير المحتوى بعد بدء هذه المراجعة.',
        'unknown_error' => 'فشلت المراجعة الآلية لسبب غير متوقع.',
        'review_not_cancellable' => 'يمكن إلغاء المراجعة في حالة الانتظار أو التنفيذ فقط.',
        'review_already_decided' => 'تم اتخاذ القرار في هذه المراجعة بالفعل.',
        'subject_type_not_supported' => 'نوع المحتوى هذا غير مدعوم في نظام المراجعة.',
        'settings_invalid' => 'إعدادات المراجعة المرسلة غير صالحة.',
        'policy_invalid' => 'سياسة المراجعة المرسلة غير صالحة.',
        'policy_in_use' => 'لا يمكن تعديل إصدار سياسة تم استخدامه بالفعل.',
        'settings_in_use' => 'لا يمكن تعديل إصدار إعدادات تم استخدامه بالفعل.',
        'automatic_decision_not_allowed' => 'الوضع الحالي لا يسمح بالقرارات التلقائية.',
        'review_not_found' => 'لا توجد مراجعة آلية.',
        'review_not_retryable' => 'لا يمكن إعادة المحاولة إلا لمراجعة فاشلة لمحتوى لم يتغير.',
        'review_stale' => 'تغيّر المحتوى بعد تنفيذ هذه المراجعة، فلم تعد نتيجتها صالحة للاستخدام.',
        'review_manual_mode' => 'المراجعة الآلية معطلة لهذا النوع من المحتوى.',
        'provider_not_configured' => 'لا يوجد مزود مراجعة مهيأ.',
        'version_conflict' => 'تم نشر إصدار آخر في نفس اللحظة. أعد تحميل الصفحة وحاول مرة أخرى.',
        'subject_not_found' => 'المحتوى المطلوب مراجعته غير موجود.',
        'review_not_ready' => 'لم تكتمل هذه المراجعة بعد، فلا يمكن اتخاذ قرار بناءً على توصيتها الآن.',
        'review_superseded' => 'تم استبدال هذه المراجعة أو إلغاؤها، فلم تعد توصيتها صالحة للاستخدام.',
        'recommendation_missing' => 'لا تحمل هذه المراجعة توصية بالموافقة أو الرفض ليُتخذ قرار بشأنها.',
        'override_reason_required' => 'يجب إدخال سبب لاتخاذ قرار مخالف لتوصية الذكاء الاصطناعي.',
        'review_not_assisted' => 'الوضع الذي نُفذت فيه هذه المراجعة لا يسمح بتأكيد التوصية أو تجاوزها.',
        'decision_conflict' => 'تغيّرت الحالة أثناء اتخاذ القرار. أعد تحميل الصفحة وحاول مرة أخرى.',
        'decision_not_permitted' => 'لا تملك صلاحية اتخاذ هذا القرار على هذا المحتوى.',
    ],

    'subject_types' => [
        'auction' => 'مزاد',
    ],

    'modes' => [
        'manual' => 'مراجعة يدوية',
        'ai_assisted' => 'مراجعة بمساعدة الذكاء الاصطناعي',
        'ai_automatic' => 'مراجعة آلية بالذكاء الاصطناعي',
        'shadow' => 'وضع الظل',
    ],

    'statuses' => [
        'queued' => 'في الانتظار',
        'running' => 'قيد التنفيذ',
        'completed' => 'مكتملة',
        'failed' => 'فاشلة',
        'cancelled' => 'ملغاة',
        'superseded' => 'لم تعد صالحة',
    ],

    'outcomes' => [
        'auto_approved' => 'تمت الموافقة تلقائيًا',
        'auto_rejected' => 'تم الرفض تلقائيًا',
        'escalated_to_human' => 'تعذر إكمال المراجعة التلقائية وتم تحويل المحتوى للمراجعة اليدوية',
        'advisory_only' => 'نتيجة استرشادية فقط',
        'no_decision' => 'لم يتم تطبيق أي قرار',
    ],

    'recommendations' => [
        'approve' => 'يوصي بالموافقة',
        'reject' => 'يوصي بالرفض',
        'needs_human' => 'يتطلب مراجعة موظف',
    ],

    'risk_levels' => [
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
        'critical' => 'حرجة',
    ],

    'severities' => [
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
        'critical' => 'حرجة',
    ],

    'triggers' => [
        'submitted_for_review' => 'إرسال للمراجعة',
        'admin_manual' => 'بطلب من موظف',
        'admin_retry' => 'إعادة محاولة بواسطة موظف',
        'sweeper' => 'إعادة جدولة تلقائية',
        'backfill' => 'أنشأها أمر المعالجة التاريخية',
    ],

    'alerts' => [
        'queue_delay_high' => 'المراجعات تنتظر في الطابور مدة أطول من المسموح',
        'circuit_open' => 'المراجعة الآلية متوقفة مؤقتًا لأن الخدمة غير مستقرة',
        'budget_exhausted' => 'ميزانية المراجعة الآلية استُنفدت',
        'provider_unavailable' => 'خدمة المراجعة تفشل بشكل متكرر ونهائي',
        'invalid_output_spike' => 'خدمة المراجعة تعيد نتائج مخالفة للصيغة المطلوبة بشكل متكرر',
        'escalation_backlog' => 'عدد كبير من المراجعات ينتظر قرار موظف',
    ],

    'actor_types' => [
        'admin' => 'موظف',
        'ai' => 'الذكاء الاصطناعي',
        'system' => 'النظام',
    ],

    'relations' => [
        'none' => 'لا توجد توصية سابقة',
        'confirmed' => 'أكد التوصية',
        'overridden' => 'تجاوز التوصية',
        'unavailable' => 'لم تتوفر توصية',
    ],

    'decisions' => [
        'approved' => 'تمت الموافقة',
        'rejected' => 'تم الرفض',
        'escalated' => 'تم التحويل للمراجعة اليدوية',
        'recommended' => 'تم تسجيل توصية',
    ],

    'reasons' => [
        'mode_does_not_apply_decisions' => 'الوضع الحالي لا يطبق القرارات الآلية',
        'provider_failure' => 'لم تكتمل المراجعة الآلية',
        'deterministic_hard_failure' => 'فشلت قاعدة إلزامية في المحتوى',
        'model_requested_human' => 'طلبت المراجعة الآلية تدخل موظف',
        'policy_requires_human' => 'السياسة تتطلب مراجعة موظف لهذه الفئة',
        'rule_model_disagreement' => 'قواعد النظام والمراجعة الآلية غير متطابقتين',
        'assisted_mode' => 'وضع المساعدة يسجل توصية فقط',
        'subject_not_automation_eligible' => 'هذا المحتوى غير مؤهل للقرارات التلقائية',
        'high_confidence_critical_violation' => 'تم رصد مخالفة حرجة بثقة عالية',
        'high_confidence_clean' => 'لم يتم رصد أي مخالفة بثقة عالية',
        'grey_zone' => 'نتيجة غير حاسمة',
        'forced_manual_review' => 'قام موظف بتحويل هذا المحتوى إلى المراجعة اليدوية',
        'superseded_before_apply' => 'لم تعد النتيجة صالحة قبل تطبيقها',
        'mode_became_more_restrictive' => 'أصبح وضع المراجعة أكثر تقييدًا قبل تطبيق النتيجة',
        'content_changed' => 'تم تغيير المحتوى قبل تطبيق النتيجة',
        'subject_not_reviewable' => 'لم يعد المحتوى في حالة قابلة للمراجعة',
        'content_unavailable' => 'تعذر إعادة تجهيز المحتوى لاتخاذ القرار',
        'circuit_open' => 'تم إيقاف المراجعة الآلية مؤقتًا لأن الخدمة غير مستقرة',
        'budget_exhausted' => 'تم استنفاد ميزانية المراجعة الآلية',
        'policy_missing' => 'لا توجد سياسة مراجعة فعالة منشورة',
        'subject_type_not_supported' => 'نوع المحتوى هذا غير مدعوم في نظام المراجعة',
        'image_review_blocks_approval' => 'ملاحظة على إحدى الصور تمنع الموافقة التلقائية',
    ],

    'automation_reasons' => [
        'content_review_disabled' => 'المراجعة الآلية موقوفة على مستوى المنصة',
        'mode_not_automatic' => 'الوضع الحالي ليس الوضع التلقائي',
        'frozen_mode_not_automatic' => 'أُنشئت هذه المحاولة قبل تفعيل الوضع التلقائي',
        'subject_type_not_supported' => 'نوع المحتوى هذا غير مدعوم في نظام المراجعة',
        'review_not_current' => 'هذه المحاولة ليست المحاولة الفعالة',
        'review_not_completed' => 'لم تكتمل المراجعة الآلية',
        'recommendation_missing' => 'لم تنتج المراجعة الآلية أي توصية',
        'deterministic_hard_failure' => 'فشل شرط إلزامي في المحتوى',
        'subject_not_reviewable' => 'المحتوى لم يعد في انتظار المراجعة',
        'content_unavailable' => 'تعذر إعادة تجهيز المحتوى',
        'review_stale' => 'تغيّر المحتوى بعد تنفيذ هذه المحاولة',
        'subject_missing' => 'المحتوى لم يعد موجودًا',
        'category_not_allowed' => 'التصنيف غير مُدرج في قائمة التشغيل التلقائي',
        'value_above_automation_cap' => 'مبلغ البداية أعلى من حد التشغيل التلقائي',
        'required_images_missing' => 'التشغيل التلقائي يتطلب صورة واحدة على الأقل',
        'terms_version_missing' => 'لا توجد نسخة شروط مرتبطة',
        'configuration_version_missing' => 'لا توجد نسخة إعدادات مرتبطة',
        'image_preparation_failed' => 'تعذر تجهيز إحدى الصور للتحليل',
        'image_not_screened' => 'لم يتم فحص إحدى الصور',
        'image_flagged' => 'تم وسم إحدى الصور في المراجعة الآلية',
        'provider_circuit_open' => 'خدمة المراجعة غير مستقرة حاليًا',
        'budget_exhausted' => 'تم استنفاد ميزانية المراجعة الآلية',
    ],

    'image_verdicts' => [
        'clean' => 'سليمة',
        'flagged' => 'موسومة',
        'needs_human' => 'تحتاج مراجعة بشرية',
    ],

    'image_failures' => [
        'unsupported_mime' => 'صيغة صورة غير مدعومة',
        'mime_mismatch' => 'صيغة الصورة لا تطابق سجلها',
        'image_too_large' => 'حجم الصورة أكبر من الحد المسموح للتحليل',
        'image_unreadable' => 'تعذر قراءة الصورة',
        'image_corrupt' => 'ملف الصورة تالف',
    ],

    'violations' => [
        'prohibited_item' => 'سلعة محظورة',
        'misleading_description' => 'وصف مضلل',
        'contact_info_in_content' => 'بيانات تواصل داخل المحتوى',
        'price_manipulation' => 'تلاعب في السعر',
        'missing_images' => 'صور ناقصة',
        'image_mismatch' => 'الصور لا تطابق الوصف',
        'offensive_language' => 'ألفاظ غير لائقة',
        'duplicate_listing' => 'إعلان مكرر',
        'incomplete_information' => 'معلومات ناقصة',
    ],

    'rules' => [
        'min_description_length' => 'الحد الأدنى لطول الوصف',
        'require_at_least_one_image' => 'مطلوب صورة واحدة على الأقل',
        'forbid_contact_patterns' => 'لا يسمح ببيانات التواصل داخل المحتوى',
        'reserve_must_not_exceed_starting_multiplier' => 'السعر الاحتياطي غير متناسب مع سعر البداية',
    ],

    'notifications' => [
        'content_review.escalated' => [
            'title' => 'محتوى يحتاج مراجعة يدوية',
            'body' => 'تعذر إكمال المراجعة التلقائية للمحتوى ":subject" ويحتاج مراجعة موظف.',
        ],
        'content_review.auto_decided' => [
            'title' => 'تم تطبيق قرار تلقائي',
            'body' => 'طبقت المراجعة الآلية قرارًا على المحتوى ":subject".',
        ],
        'content_review.failed' => [
            'title' => 'تكرر فشل المراجعة الآلية',
            'body' => 'فشلت المراجعة الآلية للمحتوى ":subject" بعد استنفاد كل المحاولات.',
        ],
        'content_review.circuit_open' => [
            'title' => 'تم إيقاف المراجعة الآلية مؤقتًا',
            'body' => 'تم إيقاف المراجعة الآلية مؤقتًا لأن الخدمة غير مستقرة.',
        ],
        'content_review.provider_unavailable' => [
            'title' => 'مزود المراجعة غير مستقر',
            'body' => 'تم إيقاف المراجعة الآلية مؤقتًا بسبب تكرار الفشل في الخدمة.',
        ],
        'content_review.budget_exhausted' => [
            'title' => 'تم استنفاد ميزانية المراجعة',
            'body' => 'تم بلوغ حد ميزانية المراجعة الآلية، وستتحول المراجعات إلى المراجعة اليدوية.',
        ],
        'content_review.stale' => [
            'title' => 'نتيجة المراجعة لم تعد صالحة',
            'body' => 'تم تغيير محتوى ":subject" ولذلك لم تعد النتيجة الآلية صالحة.',
        ],
        'content_review.queue_delay_high' => [
            'title' => 'طابور المراجعة الآلية متأخر',
            'body' => 'المراجعات تنتظر مدة أطول من الحد المحدد. تأكد من أن عامل المراجعة الآلية يعمل.',
        ],
        'content_review.invalid_output_spike' => [
            'title' => 'نتائج المراجعة الآلية مخالفة للصيغة المطلوبة',
            'body' => 'عدد كبير من النتائج لم يطابق الصيغة المطلوبة. قد يكون النص التوجيهي أو السياسة أو الموديل قد تغيّر.',
        ],
        'content_review.escalation_backlog' => [
            'title' => 'تراكم في المراجعة اليدوية',
            'body' => 'عدد كبير من المراجعات ينتظر قرار موظف.',
        ],
        'content_review.recovered' => [
            'title' => 'انتهى تنبيه المراجعة الآلية',
            'body' => 'تمت معالجة هذا التنبيه: ":subject".',
        ],
    ],
];
