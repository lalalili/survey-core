<?php

namespace Lalalili\SurveyCore\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lalalili\SurveyCore\Models\Survey;
use Lalalili\SurveyCore\Models\SurveyField;

/**
 * @mixin Survey
 */
class PublicSurveyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_key' => $this->public_key,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'allow_anonymous' => $this->allow_anonymous,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'submit_success_message' => $this->submit_success_message,
            'fields' => $this->fields
                ->filter(fn (SurveyField $f): bool => ! $f->is_hidden)
                ->values()
                ->map(fn (SurveyField $f): array => [
                    'field_key' => $f->field_key,
                    'type' => $f->type->value,
                    'label' => $f->label,
                    'description' => $f->description,
                    'is_required' => $f->is_required,
                    'placeholder' => $f->placeholder,
                    'default_value' => $f->default_value,
                    'options' => $f->options_json,
                    'sort_order' => $f->sort_order,
                ]),
        ];
    }
}
