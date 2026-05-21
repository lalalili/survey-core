<?php

namespace Lalalili\SurveyCore\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Minimal base rules — field-level validation is handled in ValidateSurveySubmissionAction.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'answers' => 'sometimes|array',
        ];
    }

    /** @return array<string, mixed> */
    public function answers(): array
    {
        return $this->input('answers', []);
    }
}
