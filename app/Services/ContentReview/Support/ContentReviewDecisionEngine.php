<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Domain\ContentReview\Enums\ViolationSeverity;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\DTO\ContentReview\AutomationContext;
use App\DTO\ContentReview\DeterministicCheckResult;
use App\DTO\ContentReview\ReviewDecisionDTO;
use App\DTO\ContentReview\StructuredReviewResult;

final class ContentReviewDecisionEngine
{
    public function decide(
        ?StructuredReviewResult $result,
        DeterministicCheckResult $deterministic,
        ReviewPolicy $policy,
        ReviewMode $mode,
        AutomationContext $context,
        ?ContentReviewErrorCode $error,
    ): ReviewDecisionDTO {
        if ($mode === ReviewMode::Manual || $mode === ReviewMode::Shadow) {
            return $this->advisory($result, 'mode_does_not_apply_decisions');
        }

        if ($error !== null || $result === null) {
            return $this->escalate($result, 'provider_failure', [
                'error_code' => $error?->value ?? ContentReviewErrorCode::UnknownError->value,
            ]);
        }

        if ($deterministic->hasHardFailure()) {
            return $this->escalate($result, 'deterministic_hard_failure', [
                'rules' => $deterministic->failedRuleCodes(),
            ]);
        }

        if ($result->requiresHumanReview) {
            return $this->escalate($result, 'model_requested_human');
        }

        $humanCategories = array_values(array_intersect($result->categories, $policy->humanReviewCategories()));

        if ($humanCategories !== []) {
            return $this->escalate($result, 'policy_requires_human', ['categories' => $humanCategories]);
        }

        if ($this->rulesContradictModel($deterministic, $result)) {
            return $this->escalate($result, 'rule_model_disagreement');
        }

        if ($mode === ReviewMode::AiAssisted) {
            return $this->advisory($result, 'assisted_mode');
        }

        if (! $context->isAutomationEligible) {
            return $this->escalate($result, 'subject_not_automation_eligible', ['reasons' => $context->reasons]);
        }

        if ($this->qualifiesForAutomaticRejection($result, $policy)) {
            return new ReviewDecisionDTO(
                ContentReviewOutcome::AutoRejected,
                $result->recommendation,
                $result->confidence,
                'high_confidence_critical_violation',
            );
        }

        if ($this->qualifiesForAutomaticApproval($result, $policy)) {
            return new ReviewDecisionDTO(
                ContentReviewOutcome::AutoApproved,
                $result->recommendation,
                $result->confidence,
                'high_confidence_clean',
            );
        }

        return $this->escalate($result, 'grey_zone');
    }

    private function rulesContradictModel(DeterministicCheckResult $deterministic, StructuredReviewResult $result): bool
    {
        return $deterministic->failedRuleCodes() !== []
            && $result->recommendation === ReviewRecommendation::Approve
            && ! $result->hasViolations();
    }

    private function qualifiesForAutomaticRejection(StructuredReviewResult $result, ReviewPolicy $policy): bool
    {
        if ($result->recommendation !== ReviewRecommendation::Reject) {
            return false;
        }

        $autoRejectCodes = $policy->autoRejectCategories();

        if ($autoRejectCodes === []) {
            return false;
        }

        if (! $result->hasViolationAtLeast(ViolationSeverity::Critical, $autoRejectCodes)) {
            return false;
        }

        return $result->confidence >= $policy->minConfidenceReject();
    }

    private function qualifiesForAutomaticApproval(StructuredReviewResult $result, ReviewPolicy $policy): bool
    {
        return $result->recommendation === ReviewRecommendation::Approve
            && ! $result->hasViolations()
            && $result->categories === []
            && $result->confidence >= $policy->minConfidenceApprove()
            && $result->riskLevel->isAtMost($policy->maxRiskLevelForAutoApprove());
    }

    private function advisory(?StructuredReviewResult $result, string $reasonCode): ReviewDecisionDTO
    {
        return new ReviewDecisionDTO(
            ContentReviewOutcome::AdvisoryOnly,
            $result?->recommendation,
            $result?->confidence,
            $reasonCode,
        );
    }

    private function escalate(?StructuredReviewResult $result, string $reasonCode, array $params = []): ReviewDecisionDTO
    {
        return new ReviewDecisionDTO(
            ContentReviewOutcome::EscalatedToHuman,
            $result?->recommendation,
            $result?->confidence,
            $reasonCode,
            $params,
        );
    }
}
