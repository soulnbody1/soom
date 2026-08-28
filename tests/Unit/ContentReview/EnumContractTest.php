<?php

declare(strict_types=1);

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Domain\ContentReview\Enums\StructuredOutputStrategy;
use App\Domain\ContentReview\Enums\ViolationSeverity;
use Illuminate\Support\Facades\Lang;

dataset('content review enum groups', [
    'subject types' => [ReviewableSubjectType::class, 'subject_types'],
    'modes' => [ReviewMode::class, 'modes'],
    'statuses' => [ContentReviewStatus::class, 'statuses'],
    'outcomes' => [ContentReviewOutcome::class, 'outcomes'],
    'recommendations' => [ReviewRecommendation::class, 'recommendations'],
    'risk levels' => [ReviewRiskLevel::class, 'risk_levels'],
    'severities' => [ViolationSeverity::class, 'severities'],
    'triggers' => [ReviewTrigger::class, 'triggers'],
    'actor types' => [DecisionActorType::class, 'actor_types'],
    'relations' => [DecisionRelation::class, 'relations'],
    'decisions' => [ContentReviewDecisionType::class, 'decisions'],
    'error codes' => [ContentReviewErrorCode::class, 'errors'],
    'structured output strategies' => [StructuredOutputStrategy::class, 'structured_output'],
]);

test('every enum value has an arabic and an english label', function (string $enum, string $group) {
    foreach (['en', 'ar'] as $locale) {
        $messages = Lang::get('content_review.'.$group, [], $locale);

        expect($messages)->toBeArray();

        foreach ($enum::cases() as $case) {
            expect($messages)->toHaveKey($case->value);
            expect($messages[$case->value])->toBeString()->not->toBe('');
        }
    }
})->with('content review enum groups');

test('the arabic and english files expose the same key structure', function () {
    $flatten = static function (array $items, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = is_array($value) ? array_merge($keys, $flatten($value, $path)) : array_merge($keys, [$path]);
        }

        return $keys;
    };

    $en = $flatten(Lang::get('content_review', [], 'en'));
    $ar = $flatten(Lang::get('content_review', [], 'ar'));

    sort($en);
    sort($ar);

    expect($ar)->toBe($en);
});

test('every decision engine reason code has a label', function () {
    $reasons = [
        'mode_does_not_apply_decisions',
        'provider_failure',
        'deterministic_hard_failure',
        'model_requested_human',
        'policy_requires_human',
        'rule_model_disagreement',
        'assisted_mode',
        'subject_not_automation_eligible',
        'high_confidence_critical_violation',
        'high_confidence_clean',
        'grey_zone',
    ];

    foreach (['en', 'ar'] as $locale) {
        $messages = Lang::get('content_review.reasons', [], $locale);

        foreach ($reasons as $reason) {
            expect($messages)->toHaveKey($reason);
        }
    }
});

test('the subsystem defines no admin permission of its own', function () {
    $config = config('content_review');

    expect($config)->not->toHaveKey('admin_permissions')
        ->and($config)->not->toHaveKey('role_admin_permissions')
        ->and(json_encode($config, JSON_THROW_ON_ERROR))->not->toContain('content_review.view');
});

test('sensitive settings never live in the content review config file', function () {
    $config = config('content_review');
    $flat = json_encode($config, JSON_THROW_ON_ERROR);

    expect($flat)->not->toContain('api_key')
        ->and($flat)->not->toContain('secret')
        ->and($config['enabled'])->toBeFalse()
        ->and($config['default_mode'])->toBe('manual');
});

test('risk and severity ordering is total and consistent', function () {
    expect(ReviewRiskLevel::Low->isAtMost(ReviewRiskLevel::Low))->toBeTrue()
        ->and(ReviewRiskLevel::Medium->isAtMost(ReviewRiskLevel::Low))->toBeFalse()
        ->and(ReviewRiskLevel::Critical->isAtMost(ReviewRiskLevel::High))->toBeFalse()
        ->and(ViolationSeverity::Critical->isAtLeast(ViolationSeverity::High))->toBeTrue()
        ->and(ViolationSeverity::Low->isAtLeast(ViolationSeverity::Medium))->toBeFalse();
});

test('only the automatic mode allows automatic decisions', function () {
    foreach (ReviewMode::cases() as $mode) {
        expect($mode->allowsAutomaticDecision())->toBe($mode === ReviewMode::AiAutomatic);
    }

    expect(ReviewMode::Manual->callsProvider())->toBeFalse()
        ->and(ReviewMode::Shadow->callsProvider())->toBeTrue();
});

test('only automatic outcomes mutate the subject', function () {
    foreach (ContentReviewOutcome::cases() as $outcome) {
        expect($outcome->mutatesSubject())->toBe(
            in_array($outcome, [ContentReviewOutcome::AutoApproved, ContentReviewOutcome::AutoRejected], true)
        );
    }
});

test('only the admin actor type carries a user id', function () {
    expect(DecisionActorType::Admin->carriesUserId())->toBeTrue()
        ->and(DecisionActorType::Ai->carriesUserId())->toBeFalse()
        ->and(DecisionActorType::System->carriesUserId())->toBeFalse();
});

test('non retryable error codes are terminal', function () {
    expect(ContentReviewErrorCode::InvalidStructuredOutput->isRetryable())->toBeFalse()
        ->and(ContentReviewErrorCode::ProviderAuthFailed->isRetryable())->toBeFalse()
        ->and(ContentReviewErrorCode::BudgetExhausted->isRetryable())->toBeFalse()
        ->and(ContentReviewErrorCode::CircuitOpen->isRetryable())->toBeFalse()
        ->and(ContentReviewErrorCode::ContentChanged->isRetryable())->toBeFalse()
        ->and(ContentReviewErrorCode::ProviderTimeout->isRetryable())->toBeTrue();
});

test('an unknown error is a defect in our own code, so it is never retried', function () {
    // Every transient condition around a provider call already has its own code, so retrying an
    // unknown one would only spend the same money again to reach the same escalation.
    expect(ContentReviewErrorCode::UnknownError->isRetryable())->toBeFalse();
});

test('only a self-clearing block defers a review rather than failing it', function () {
    $deferring = array_values(array_filter(
        ContentReviewErrorCode::cases(),
        static fn (ContentReviewErrorCode $code): bool => $code->isTransientBlock()
    ));

    expect($deferring)->toBe([ContentReviewErrorCode::CircuitOpen])
        ->and(ContentReviewErrorCode::CircuitOpen->isRetryable())->toBeFalse();
});
