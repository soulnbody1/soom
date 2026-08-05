<?php

declare(strict_types=1);

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\DTO\ContentReview\AutomationContext;
use App\DTO\ContentReview\DeterministicCheckResult;
use App\DTO\ContentReview\StructuredReviewResult;
use App\Services\ContentReview\Support\ContentReviewDecisionEngine;

function crPolicy(array $overrides = []): ReviewPolicy
{
    return new ReviewPolicy(array_replace([
        'prohibited_categories' => ['weapons', 'drugs', 'counterfeit', 'medical_claims'],
        'auto_reject_categories' => ['prohibited_item'],
        'human_review_categories' => ['counterfeit', 'medical_claims'],
        'violation_codes' => ['prohibited_item', 'misleading_description'],
        'thresholds' => [
            'min_confidence_approve' => 85,
            'min_confidence_reject' => 90,
        ],
        'max_risk_level_for_auto_approve' => 'low',
    ], $overrides));
}

function crResult(array $overrides = []): StructuredReviewResult
{
    $data = array_replace([
        'recommendation' => ReviewRecommendation::Approve,
        'confidence' => 95,
        'risk_level' => ReviewRiskLevel::Low,
        'requires_human_review' => false,
        'summary_ar' => 'ملخص',
        'summary_en' => 'summary',
        'categories' => [],
        'violations' => [],
        'findings' => [],
        'policy_checks' => [],
        'missing_information' => [],
    ], $overrides);

    return new StructuredReviewResult(
        $data['recommendation'],
        $data['confidence'],
        $data['risk_level'],
        $data['requires_human_review'],
        $data['summary_ar'],
        $data['summary_en'],
        $data['categories'],
        $data['violations'],
        $data['findings'],
        $data['policy_checks'],
        $data['missing_information'],
    );
}

function crDecide(
    ?StructuredReviewResult $result,
    ReviewMode $mode = ReviewMode::AiAutomatic,
    ?DeterministicCheckResult $deterministic = null,
    ?AutomationContext $context = null,
    ?ContentReviewErrorCode $error = null,
    ?ReviewPolicy $policy = null,
) {
    return (new ContentReviewDecisionEngine)->decide(
        $result,
        $deterministic ?? DeterministicCheckResult::pass(),
        $policy ?? crPolicy(),
        $mode,
        $context ?? AutomationContext::eligible(),
        $error,
    );
}

test('R1 manual and shadow modes never apply a decision', function (ReviewMode $mode) {
    $decision = crDecide(crResult(), $mode);

    expect($decision->outcome)->toBe(ContentReviewOutcome::AdvisoryOnly)
        ->and($decision->reasonCode)->toBe('mode_does_not_apply_decisions')
        ->and($decision->mutatesSubject())->toBeFalse();
})->with([ReviewMode::Manual, ReviewMode::Shadow]);

test('R2 any provider error escalates to a human', function (ContentReviewErrorCode $error) {
    $decision = crDecide(null, ReviewMode::AiAutomatic, error: $error);

    expect($decision->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman)
        ->and($decision->reasonCode)->toBe('provider_failure');
})->with(ContentReviewErrorCode::cases());

test('R2 a missing result escalates even without an error code', function () {
    expect(crDecide(null)->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman);
});

test('R3 a hard deterministic failure escalates before the model is trusted', function () {
    $deterministic = new DeterministicCheckResult(
        [['rule_code' => 'require_at_least_one_image', 'passed' => false, 'hard' => true]],
        true
    );

    $decision = crDecide(crResult(), ReviewMode::AiAutomatic, $deterministic);

    expect($decision->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman)
        ->and($decision->reasonCode)->toBe('deterministic_hard_failure')
        ->and($decision->reasonParams['rules'])->toBe(['require_at_least_one_image']);
});

test('R4 the model can always demand a human reviewer', function () {
    $decision = crDecide(crResult(['requires_human_review' => true]));

    expect($decision->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman)
        ->and($decision->reasonCode)->toBe('model_requested_human');
});

test('R5 a policy human review category escalates', function () {
    $decision = crDecide(crResult(['categories' => ['counterfeit']]));

    expect($decision->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman)
        ->and($decision->reasonCode)->toBe('policy_requires_human')
        ->and($decision->reasonParams['categories'])->toBe(['counterfeit']);
});

test('R6 a soft rule failure that the model missed escalates', function () {
    $deterministic = new DeterministicCheckResult(
        [['rule_code' => 'forbid_contact_patterns', 'passed' => false, 'hard' => false]],
        false
    );

    $decision = crDecide(crResult(), ReviewMode::AiAutomatic, $deterministic);

    expect($decision->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman)
        ->and($decision->reasonCode)->toBe('rule_model_disagreement');
});

test('R7 assisted mode only ever records advice', function () {
    $decision = crDecide(crResult(), ReviewMode::AiAssisted);

    expect($decision->outcome)->toBe(ContentReviewOutcome::AdvisoryOnly)
        ->and($decision->reasonCode)->toBe('assisted_mode')
        ->and($decision->recommendation)->toBe(ReviewRecommendation::Approve);
});

test('R8 an ineligible subject escalates even with a perfect result', function () {
    $decision = crDecide(
        crResult(),
        ReviewMode::AiAutomatic,
        null,
        AutomationContext::ineligible(['category_not_allowlisted'])
    );

    expect($decision->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman)
        ->and($decision->reasonCode)->toBe('subject_not_automation_eligible')
        ->and($decision->reasonParams['reasons'])->toBe(['category_not_allowlisted']);
});

test('R9 a critical listed violation at the threshold is rejected automatically', function () {
    $result = crResult([
        'recommendation' => ReviewRecommendation::Reject,
        'confidence' => 90,
        'risk_level' => ReviewRiskLevel::Critical,
        'violations' => [['code' => 'prohibited_item', 'severity' => 'critical', 'field' => 'title', 'evidence' => 'x']],
    ]);

    $decision = crDecide($result);

    expect($decision->outcome)->toBe(ContentReviewOutcome::AutoRejected)
        ->and($decision->reasonCode)->toBe('high_confidence_critical_violation');
});

test('R9 does not fire when the policy lists no auto reject categories', function () {
    $result = crResult([
        'recommendation' => ReviewRecommendation::Reject,
        'confidence' => 99,
        'violations' => [['code' => 'prohibited_item', 'severity' => 'critical', 'field' => 'title', 'evidence' => 'x']],
    ]);

    $decision = crDecide($result, policy: crPolicy(['auto_reject_categories' => []]));

    expect($decision->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman)
        ->and($decision->reasonCode)->toBe('grey_zone');
});

test('R9 does not fire below the rejection threshold', function () {
    $result = crResult([
        'recommendation' => ReviewRecommendation::Reject,
        'confidence' => 89,
        'violations' => [['code' => 'prohibited_item', 'severity' => 'critical', 'field' => 'title', 'evidence' => 'x']],
    ]);

    expect(crDecide($result)->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman);
});

test('R9 does not fire for a non critical violation', function () {
    $result = crResult([
        'recommendation' => ReviewRecommendation::Reject,
        'confidence' => 99,
        'violations' => [['code' => 'prohibited_item', 'severity' => 'high', 'field' => 'title', 'evidence' => 'x']],
    ]);

    expect(crDecide($result)->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman);
});

test('R10 a clean high confidence low risk result is approved automatically', function () {
    $decision = crDecide(crResult(['confidence' => 85]));

    expect($decision->outcome)->toBe(ContentReviewOutcome::AutoApproved)
        ->and($decision->reasonCode)->toBe('high_confidence_clean');
});

test('R10 respects the approval confidence boundary exactly', function (int $confidence, ContentReviewOutcome $expected) {
    expect(crDecide(crResult(['confidence' => $confidence]))->outcome)->toBe($expected);
})->with([
    [84, ContentReviewOutcome::EscalatedToHuman],
    [85, ContentReviewOutcome::AutoApproved],
    [86, ContentReviewOutcome::AutoApproved],
]);

test('R10 refuses to auto approve above the allowed risk ceiling', function (ReviewRiskLevel $risk, ContentReviewOutcome $expected) {
    expect(crDecide(crResult(['risk_level' => $risk]))->outcome)->toBe($expected);
})->with([
    [ReviewRiskLevel::Low, ContentReviewOutcome::AutoApproved],
    [ReviewRiskLevel::Medium, ContentReviewOutcome::EscalatedToHuman],
    [ReviewRiskLevel::High, ContentReviewOutcome::EscalatedToHuman],
    [ReviewRiskLevel::Critical, ContentReviewOutcome::EscalatedToHuman],
]);

test('R10 refuses to auto approve when any violation is present', function () {
    $result = crResult([
        'violations' => [['code' => 'misleading_description', 'severity' => 'low', 'field' => 'description', 'evidence' => 'x']],
    ]);

    expect(crDecide($result)->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman);
});

test('R10 refuses to auto approve when a prohibited category is flagged', function () {
    expect(crDecide(crResult(['categories' => ['weapons']]))->outcome)
        ->toBe(ContentReviewOutcome::EscalatedToHuman);
});

test('R11 an inconclusive result lands in the grey zone', function () {
    $decision = crDecide(crResult(['recommendation' => ReviewRecommendation::NeedsHuman, 'confidence' => 60]));

    expect($decision->outcome)->toBe(ContentReviewOutcome::EscalatedToHuman)
        ->and($decision->reasonCode)->toBe('grey_zone');
});

test('no error code in any mode can ever produce an automatic approval', function () {
    foreach (ContentReviewErrorCode::cases() as $error) {
        foreach (ReviewMode::cases() as $mode) {
            $decision = crDecide(crResult(), $mode, error: $error);

            expect($decision->outcome)->not->toBe(ContentReviewOutcome::AutoApproved);
            expect($decision->outcome)->not->toBe(ContentReviewOutcome::AutoRejected);
        }
    }
});

test('no non automatic mode can ever mutate the subject', function () {
    foreach ([ReviewMode::Manual, ReviewMode::Shadow, ReviewMode::AiAssisted] as $mode) {
        expect(crDecide(crResult(), $mode)->mutatesSubject())->toBeFalse();
    }
});

test('an ineligible subject can never be decided automatically under any result', function () {
    $context = AutomationContext::ineligible(['value_over_cap']);

    foreach ([ReviewRecommendation::Approve, ReviewRecommendation::Reject, ReviewRecommendation::NeedsHuman] as $recommendation) {
        $decision = crDecide(
            crResult(['recommendation' => $recommendation, 'confidence' => 100]),
            ReviewMode::AiAutomatic,
            null,
            $context
        );

        expect($decision->mutatesSubject())->toBeFalse();
    }
});

test('the decision engine is deterministic for identical inputs', function () {
    $first = crDecide(crResult());
    $second = crDecide(crResult());

    expect($first->toArray())->toBe($second->toArray());
});
