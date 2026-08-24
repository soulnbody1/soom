<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use App\Services\ContentReview\Support\ProviderModelCatalog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PublishContentReviewSettingsRequest extends FormRequest
{
    private const ALLOWED_KEYS = [
        'enabled',
        'mode',
        'provider',
        'model',
        'timeout_seconds',
        'max_attempts',
        'backoff_seconds',
        'max_concurrent',
        'max_output_tokens',
        'daily_budget_micros',
        'monthly_budget_micros',
        'analyze_images',
        'circuit_breaker',
        'automation',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::in($this->allowedScopes())],

            'settings' => ['required', 'array'],
            'settings.enabled' => ['required', 'boolean'],
            'settings.mode' => ['required', 'string', Rule::in(array_column(ReviewMode::cases(), 'value'))],
            'settings.provider' => ['required', 'string', Rule::in(app(ContentReviewProviderFactory::class)->available())],
            'settings.model' => ['required', 'string', 'max:80'],
            'settings.timeout_seconds' => ['required', 'integer', 'min:5', 'max:300'],
            'settings.max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'settings.backoff_seconds' => ['required', 'array', 'min:1', 'max:10'],
            'settings.backoff_seconds.*' => ['integer', 'min:1', 'max:86400'],
            'settings.max_concurrent' => ['required', 'integer', 'min:1', 'max:100'],
            'settings.max_output_tokens' => ['required', 'integer', 'min:256', 'max:32000'],
            'settings.daily_budget_micros' => ['required', 'integer', 'min:0'],
            'settings.monthly_budget_micros' => ['required', 'integer', 'min:0'],
            'settings.analyze_images' => ['required', 'boolean'],

            'settings.circuit_breaker' => ['required', 'array'],
            'settings.circuit_breaker.failure_threshold' => ['required', 'integer', 'min:1', 'max:1000'],
            'settings.circuit_breaker.window_seconds' => ['required', 'integer', 'min:1', 'max:86400'],
            'settings.circuit_breaker.open_seconds' => ['required', 'integer', 'min:1', 'max:86400'],

            'settings.automation' => ['required', 'array'],
            'settings.automation.allowed_category_ids' => ['present', 'array', 'max:200'],
            'settings.automation.allowed_category_ids.*' => ['integer', 'min:1'],
            'settings.automation.max_starting_amount_minor' => ['required', 'integer', 'min:0'],
            'settings.automation.require_images' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $settings = (array) $this->input('settings', []);

            $daily = (int) ($settings['daily_budget_micros'] ?? 0);
            $monthly = (int) ($settings['monthly_budget_micros'] ?? 0);

            if ($daily > $monthly) {
                $validator->errors()->add('settings.daily_budget_micros', __('content_review.errors.settings_invalid'));
            }

            $provider = (string) ($settings['provider'] ?? '');
            $model = (string) ($settings['model'] ?? '');

            if ($provider !== '' && $model !== '' && ! $this->catalog()->has($provider, $model)) {
                $validator->errors()->add(
                    'settings.model',
                    __('content_review.errors.model_not_available_for_provider', ['provider' => $provider])
                );
            }

            $mode = ReviewMode::tryFrom((string) ($settings['mode'] ?? ''));

            if ($mode !== null && $mode !== ReviewMode::Manual && ($settings['enabled'] ?? false) !== true) {
                $validator->errors()->add('settings.mode', __('content_review.errors.settings_invalid'));
            }

            foreach (array_keys($settings) as $key) {
                if (str_contains(strtolower((string) $key), 'key') || str_contains(strtolower((string) $key), 'secret')) {
                    $validator->errors()->add('settings', __('content_review.errors.settings_invalid'));

                    break;
                }
            }
        });
    }

    public function scope(): string
    {
        return (string) $this->validated('scope');
    }

    public function settingsPayload(): array
    {
        $settings = (array) $this->validated('settings');
        $allowed = array_intersect_key($settings, array_flip(self::ALLOWED_KEYS));

        $allowed['circuit_breaker'] = array_intersect_key(
            (array) ($allowed['circuit_breaker'] ?? []),
            array_flip(['failure_threshold', 'window_seconds', 'open_seconds'])
        );

        $allowed['automation'] = array_intersect_key(
            (array) ($allowed['automation'] ?? []),
            array_flip(['allowed_category_ids', 'max_starting_amount_minor', 'require_images'])
        );

        return $allowed;
    }

    /**
     * @return array<int, string>
     */
    private function allowedScopes(): array
    {
        return array_merge(['global'], array_column(ReviewableSubjectType::cases(), 'value'));
    }

    private function catalog(): ProviderModelCatalog
    {
        return app(ProviderModelCatalog::class);
    }
}
