<?php

return [
    'messages' => [
        'review_requested' => 'Content review requested.',
        'review_cancelled' => 'Content review cancelled.',
        'review_forced_manual' => 'The subject was moved to manual review.',
        'settings_published' => 'Content review settings published.',
        'policy_published' => 'Content review policy published.',
        'provider_tested' => 'Provider connectivity checked.',
        'review_fetched' => 'Content review fetched.',
        'recommendation_confirmed' => 'The automated recommendation was confirmed.',
        'recommendation_overridden' => 'A decision was taken against the automated recommendation.',
        'reviews_fetched' => 'Content reviews fetched.',
        'metrics_fetched' => 'Content review metrics fetched.',
        'no_review' => 'No automated review exists for this content.',
    ],

    'errors' => [
        'provider_timeout' => 'The review service did not respond in time.',
        'provider_rate_limited' => 'The review service is rate limiting requests.',
        'provider_unavailable' => 'The review service is unavailable.',
        'provider_auth_failed' => 'The review service rejected the credentials.',
        'invalid_structured_output' => 'The review service returned a result that does not match the required contract.',
        'content_unavailable' => 'The content could not be prepared for review.',
        'image_fetch_failed' => 'One or more images could not be read for review.',
        'budget_exhausted' => 'The automated review budget has been exhausted.',
        'circuit_open' => 'Automated review is paused because the service is unhealthy.',
        'policy_missing' => 'No active review policy is published for this content type.',
        'subject_not_reviewable' => 'This content is not in a reviewable state.',
        'content_changed' => 'The content changed after this review started.',
        'unknown_error' => 'The automated review failed for an unexpected reason.',
        'content_review_override_not_allowed' => 'Overriding the automated recommendation requires a dedicated permission.',
        'review_not_cancellable' => 'Only a queued or running review can be cancelled.',
        'review_already_decided' => 'This review has already been decided.',
        'subject_type_not_supported' => 'This content type is not supported by the review system.',
        'settings_invalid' => 'The submitted review settings are invalid.',
        'policy_invalid' => 'The submitted review policy is invalid.',
        'policy_in_use' => 'A review policy version that has already been used cannot be modified.',
        'settings_in_use' => 'A settings version that has already been used cannot be modified.',
        'automatic_decision_not_allowed' => 'The current mode does not allow automatic decisions.',
        'review_not_found' => 'No automated review was found.',
        'review_not_retryable' => 'Only a failed review of content that has not changed can be retried.',
        'review_stale' => 'The content changed after this review ran, so its result can no longer be used.',
        'review_manual_mode' => 'Automated review is disabled for this content type.',
        'provider_not_configured' => 'No review service is configured.',
        'version_conflict' => 'Another version was published at the same time. Reload and try again.',
        'subject_not_found' => 'The content to review was not found.',
        'review_not_ready' => 'This review has not finished, so its recommendation cannot be acted on yet.',
        'review_superseded' => 'This review was replaced or cancelled, so its recommendation can no longer be used.',
        'recommendation_missing' => 'This review carries no approve or reject recommendation to act on.',
        'override_reason_required' => 'A reason is required to decide against the automated recommendation.',
        'review_not_assisted' => 'The mode this review ran in does not support confirming or overriding a recommendation.',
        'decision_conflict' => 'The state changed while the decision was being taken. Reload and try again.',
        'decision_not_permitted' => 'You are not allowed to take this decision on this content.',
    ],

    'subject_types' => [
        'auction' => 'Auction',
    ],

    'modes' => [
        'manual' => 'Manual review',
        'ai_assisted' => 'AI assisted review',
        'ai_automatic' => 'Automatic AI review',
        'shadow' => 'Shadow mode',
    ],

    'statuses' => [
        'queued' => 'Queued',
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'superseded' => 'Superseded',
    ],

    'outcomes' => [
        'auto_approved' => 'Approved automatically',
        'auto_rejected' => 'Rejected automatically',
        'escalated_to_human' => 'The automated review could not be completed and the content was moved to manual review',
        'advisory_only' => 'Advisory result only',
        'no_decision' => 'No decision applied',
    ],

    'recommendations' => [
        'approve' => 'Recommends approval',
        'reject' => 'Recommends rejection',
        'needs_human' => 'Requires a human reviewer',
    ],

    'risk_levels' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ],

    'severities' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ],

    'triggers' => [
        'submitted_for_review' => 'Submitted for review',
        'admin_manual' => 'Requested by an employee',
        'admin_retry' => 'Retried by an employee',
        'sweeper' => 'Requeued by the scheduler',
    ],

    'actor_types' => [
        'admin' => 'Employee',
        'ai' => 'Artificial intelligence',
        'system' => 'System',
    ],

    'relations' => [
        'none' => 'No prior recommendation',
        'confirmed' => 'Confirmed the recommendation',
        'overridden' => 'Overrode the recommendation',
        'unavailable' => 'No recommendation was available',
    ],

    'decisions' => [
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'escalated' => 'Escalated to a human reviewer',
        'recommended' => 'Recommendation recorded',
    ],

    'reasons' => [
        'mode_does_not_apply_decisions' => 'The current mode does not apply automated decisions',
        'provider_failure' => 'The automated review did not complete',
        'deterministic_hard_failure' => 'A mandatory content rule failed',
        'model_requested_human' => 'The automated review asked for a human reviewer',
        'policy_requires_human' => 'The policy requires a human reviewer for this category',
        'rule_model_disagreement' => 'The system rules and the automated review disagree',
        'assisted_mode' => 'Assisted mode records a recommendation only',
        'subject_not_automation_eligible' => 'This content is not eligible for automatic decisions',
        'high_confidence_critical_violation' => 'A critical violation was detected with high confidence',
        'high_confidence_clean' => 'No violations were detected with high confidence',
        'grey_zone' => 'The result was inconclusive',
        'forced_manual_review' => 'An employee moved this content to manual review',
        'superseded_before_apply' => 'The result was invalidated before it could be applied',
        'mode_became_more_restrictive' => 'The review mode became more restrictive before the result was applied',
        'content_changed' => 'The content changed before the result was applied',
        'subject_not_reviewable' => 'The content was no longer in a reviewable state',
        'content_unavailable' => 'The content could not be rebuilt for the decision',
        'circuit_open' => 'Automated review was paused because the service is unhealthy',
        'budget_exhausted' => 'The automated review budget was exhausted',
        'policy_missing' => 'No active review policy was published',
        'subject_type_not_supported' => 'This content type is not supported by the review system',
    ],

    'violations' => [
        'prohibited_item' => 'Prohibited item',
        'misleading_description' => 'Misleading description',
        'contact_info_in_content' => 'Contact details inside the content',
        'price_manipulation' => 'Price manipulation',
        'missing_images' => 'Missing images',
        'image_mismatch' => 'Images do not match the description',
        'offensive_language' => 'Offensive language',
        'duplicate_listing' => 'Duplicate listing',
        'incomplete_information' => 'Incomplete information',
    ],

    'rules' => [
        'min_description_length' => 'Minimum description length',
        'require_at_least_one_image' => 'At least one image is required',
        'forbid_contact_patterns' => 'Contact details are not allowed in the content',
        'reserve_must_not_exceed_starting_multiplier' => 'The reserve amount is disproportionate to the starting amount',
    ],

    'notifications' => [
        'content_review.escalated' => [
            'title' => 'Content needs manual review',
            'body' => 'The automated review of ":subject" could not be completed and needs a human reviewer.',
        ],
        'content_review.auto_decided' => [
            'title' => 'Automated decision applied',
            'body' => 'The automated review applied a decision to ":subject".',
        ],
        'content_review.failed' => [
            'title' => 'Automated review keeps failing',
            'body' => 'The automated review of ":subject" failed after all attempts.',
        ],
        'content_review.provider_unavailable' => [
            'title' => 'Review service is unavailable',
            'body' => 'The review service keeps failing, so content is going to manual review.',
        ],
        'content_review.circuit_open' => [
            'title' => 'Automated review is paused',
            'body' => 'Automated review is paused because the service is unhealthy.',
        ],
        'content_review.budget_exhausted' => [
            'title' => 'Review budget exhausted',
            'body' => 'The automated review budget has been reached, so reviews now go to manual review.',
        ],
        'content_review.stale' => [
            'title' => 'Review result is no longer valid',
            'body' => 'The content of ":subject" changed, so the automated result is no longer valid.',
        ],
    ],
];
