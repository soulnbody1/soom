<?php

declare(strict_types=1);

namespace App\Http\Resources\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\DTO\ContentReview\ImageReviewSummary;
use App\Models\ContentReview\ContentReview;
use App\Services\ContentReview\Support\AutomationEligibilityResolver;
use App\Services\ContentReview\Support\ContentReviewActionResolver;
use App\Services\ContentReview\Support\ReviewModeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin ContentReview
 */
final class ContentReviewResource extends JsonResource
{
    private bool $includeAutomation = false;

    public function withAutomation(bool $include = true): self
    {
        $this->includeAutomation = $include;

        return $this;
    }

    /**
     * Resolve the effective mode once per request so a paginated list does not
     * re-query the settings table for every row.
     */
    public static function resolvedMode(Request $request, ReviewableSubjectType $type): ReviewMode
    {
        $key = 'content_review.resolved_mode.'.$type->value;

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, app(ReviewModeResolver::class)->resolve($type));
        }

        return $request->attributes->get($key);
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $resolver = app(ContentReviewActionResolver::class);
        $gate = $user === null ? null : Gate::forUser($user);
        $mode = self::resolvedMode($request, $this->subject_type);

        $data = [
            'id' => $this->public_id,
            'subject_type' => $this->subject_type->value,
            'subject_type_label' => __('content_review.subject_types.'.$this->subject_type->value),
            'trigger' => $this->trigger?->value,
            'trigger_label' => $this->trigger === null ? null : __('content_review.triggers.'.$this->trigger->value),
            'mode' => $this->mode->value,
            'mode_label' => __('content_review.modes.'.$this->mode->value),
            'status' => $this->status->value,
            'status_label' => __('content_review.statuses.'.$this->status->value),
            'outcome' => $this->outcome?->value,
            'outcome_label' => $this->outcome === null ? null : __('content_review.outcomes.'.$this->outcome->value),
            'reason_code' => $this->reason_code,
            'reason_label' => $this->reasonLabel(),
            'recommendation' => $this->recommendation?->value,
            'recommendation_label' => $this->recommendation === null
                ? null
                : __('content_review.recommendations.'.$this->recommendation->value),
            'confidence' => $this->confidence === null ? null : (int) $this->confidence,
            'risk_level' => $this->risk_level?->value,
            'risk_level_label' => $this->risk_level === null ? null : __('content_review.risk_levels.'.$this->risk_level->value),
            'requires_human_review' => (bool) $this->requires_human_review,
            'summary_ar' => $this->summary_ar,
            'summary_en' => $this->summary_en,
            'violations' => $this->violationsPayload(),
            'findings' => $this->findingsPayload(),
            'policy_checks' => $this->policyChecksPayload(),
            'missing_information' => $this->stringList($this->missing_information),
            'categories' => $this->stringList($this->categories),
            'deterministic_findings' => $this->deterministicFindingsPayload(),
            'image_analysis' => $this->imageAnalysisPayload(),
            'is_stale' => $resolver->isStale($this->resource),
            'is_active' => $resolver->isActive($this->resource),
            'error_code' => $this->error_code?->value,
            'error_label' => $this->error_code === null ? null : __('content_review.errors.'.$this->error_code->value),
            'attempt' => (int) $this->attempt,
            'max_attempts' => (int) $this->max_attempts,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'decisions' => ContentReviewDecisionResource::collection(
                $this->relationLoaded('decisions') ? $this->decisions : collect()
            ),
            'available_actions' => $resolver->for($user, $this->resource, $mode),
        ];

        if ($gate !== null && $gate->allows('viewTechnical', ContentReview::class)) {
            $data['technical'] = [
                'provider' => $this->provider,
                'model' => $this->model,
                'prompt_version' => $this->prompt_version,
                'result_schema_version' => $this->result_schema_version === null ? null : (int) $this->result_schema_version,
                'policy_version' => $this->policy_version === null ? null : (int) $this->policy_version,
                'settings_version' => $this->settings_version === null ? null : (int) $this->settings_version,
                'duration_ms' => $this->duration_ms === null ? null : (int) $this->duration_ms,
                'error_message' => $this->error_message,
            ];
        }

        if ($this->includeAutomation) {
            $data['automation'] = $this->automationPayload($mode);
        }

        if ($gate !== null && $gate->allows('viewCosts', ContentReview::class)) {
            $data['cost'] = [
                'input_tokens' => $this->input_tokens === null ? null : (int) $this->input_tokens,
                'output_tokens' => $this->output_tokens === null ? null : (int) $this->output_tokens,
                'cost_micros' => $this->cost_micros === null ? null : (int) $this->cost_micros,
                'currency' => (string) config('content_review.pricing.currency', 'USD'),
                'pricing_version' => (string) config('content_review.pricing.version', ''),
            ];
        }

        return $data;
    }

    private function imageAnalysisPayload(): array
    {
        $summary = ImageReviewSummary::fromArray($this->image_review);

        return [
            'analysis_enabled' => $summary->analysisEnabled,
            'total' => (int) $this->image_count,
            'selected' => $summary->selected,
            'analyzed' => (int) $this->images_analyzed,
            'cache_hits' => $summary->cacheHits,
            'failed' => $summary->failed,
            'unscreened' => $summary->unscreened,
            'checks' => array_values(array_map(fn (array $check): array => [
                'ref' => (string) ($check['ref'] ?? ''),
                'verdict' => (string) ($check['verdict'] ?? ''),
                'verdict_label' => $this->translated('content_review.image_verdicts.'.($check['verdict'] ?? '')),
                'risk_level' => $check['risk_level'] === null ? null : (string) $check['risk_level'],
                'risk_level_label' => $this->translated('content_review.risk_levels.'.($check['risk_level'] ?? '')),
                'from_cache' => ($check['from_cache'] ?? false) === true,
            ], $summary->checks)),
            'failures' => array_values(array_map(fn (array $failure): array => [
                'ref' => (string) ($failure['ref'] ?? ''),
                'failure_code' => (string) ($failure['failure_code'] ?? ''),
                'failure_label' => $this->translated('content_review.image_failures.'.($failure['failure_code'] ?? '')),
            ], $summary->failures)),
        ];
    }

    private function automationPayload(ReviewMode $mode): array
    {
        $context = app(AutomationEligibilityResolver::class)->resolve($this->resource);

        return [
            'mode' => $mode->value,
            'is_eligible' => $context->isAutomationEligible,
            'reasons' => array_values(array_map(static fn ($reason): string => (string) $reason, $context->reasons)),
            'reason_labels' => array_values(array_map(
                fn ($reason): string => $this->translated('content_review.automation_reasons.'.$reason) ?? (string) $reason,
                $context->reasons
            )),
            'approval_blockers' => array_values(array_map(
                static fn ($reason): string => (string) $reason,
                $context->approvalBlockers
            )),
            'approval_blocker_labels' => array_values(array_map(
                fn ($reason): string => $this->translated('content_review.automation_reasons.'.$reason) ?? (string) $reason,
                $context->approvalBlockers
            )),
        ];
    }

    private function reasonLabel(): ?string
    {
        $code = (string) $this->reason_code;

        if ($code === '') {
            return null;
        }

        $key = 'content_review.reasons.'.$code;
        $label = (string) __($key);

        return $label === $key ? null : $label;
    }

    private function violationsPayload(): array
    {
        return array_values(array_map(fn (array $violation): array => [
            'code' => (string) ($violation['code'] ?? ''),
            'code_label' => $this->translated('content_review.violations.'.($violation['code'] ?? '')),
            'severity' => (string) ($violation['severity'] ?? ''),
            'severity_label' => $this->translated('content_review.severities.'.($violation['severity'] ?? '')),
            'field' => (string) ($violation['field'] ?? ''),
            'evidence' => (string) ($violation['evidence'] ?? ''),
        ], $this->arrayOfArrays($this->violations)));
    }

    private function findingsPayload(): array
    {
        return array_values(array_map(fn (array $finding): array => [
            'field' => (string) ($finding['field'] ?? ''),
            'note' => (string) ($finding['note'] ?? ''),
        ], $this->arrayOfArrays($this->findings)));
    }

    private function policyChecksPayload(): array
    {
        return array_values(array_map(fn (array $check): array => [
            'rule_code' => (string) ($check['rule_code'] ?? ''),
            'rule_label' => $this->translated('content_review.rules.'.($check['rule_code'] ?? '')),
            'passed' => (bool) ($check['passed'] ?? false),
        ], $this->arrayOfArrays($this->policy_checks)));
    }

    private function deterministicFindingsPayload(): array
    {
        return array_values(array_map(fn (array $finding): array => [
            'rule_code' => (string) ($finding['rule_code'] ?? ''),
            'rule_label' => $this->translated('content_review.rules.'.($finding['rule_code'] ?? '')),
            'passed' => (bool) ($finding['passed'] ?? false),
            'is_blocking' => (bool) ($finding['hard'] ?? false),
        ], $this->arrayOfArrays($this->deterministic_findings)));
    }

    private function translated(string $key): ?string
    {
        $label = (string) __($key);

        return $label === $key ? null : $label;
    }

    private function arrayOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($item): string => (string) $item, array_filter($value, 'is_scalar')));
    }
}
