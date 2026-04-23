<?php

namespace Lalalili\SurveyCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Lalalili\SurveyCore\Actions\ResolveSurveyTokenAction;
use Lalalili\SurveyCore\Actions\SubmitSurveyResponseAction;
use Lalalili\SurveyCore\Data\SubmissionPayload;
use Lalalili\SurveyCore\Events\SurveyTokenResolved;
use Lalalili\SurveyCore\Events\SurveyViewed;
use Lalalili\SurveyCore\Exceptions\InvalidSurveyTokenException;
use Lalalili\SurveyCore\Exceptions\SurveyNotAvailableException;
use Lalalili\SurveyCore\Exceptions\SurveyValidationException;
use Lalalili\SurveyCore\Http\Requests\SubmitSurveyRequest;
use Lalalili\SurveyCore\Http\Resources\PublicSurveyResource;
use Lalalili\SurveyCore\Models\Survey;

class PublicSurveyController extends Controller
{
    public function show(string $publicKey, ResolveSurveyTokenAction $resolveToken): Response|JsonResponse
    {
        $survey = Survey::with(['fields' => fn ($q) => $q->orderBy('sort_order')])
            ->where('public_key', $publicKey)
            ->firstOrFail();

        if (! $survey->isPubliclyVisible()) {
            abort(403, '問卷目前不開放填寫。');
        }

        $resolved = null;

        if ($rawToken = request()->query('t')) {
            try {
                $resolved = $resolveToken->execute($survey, (string) $rawToken);
                SurveyTokenResolved::dispatch($resolved->token, $resolved->recipient);
            } catch (InvalidSurveyTokenException) {
                abort(403, '連結無效或已過期。');
            }
        }

        SurveyViewed::dispatch($survey, $resolved?->recipient);

        return response()->view('survey-core::survey.show', compact('survey', 'resolved'));
    }

    public function submit(
        string $publicKey,
        SubmitSurveyRequest $request,
        ResolveSurveyTokenAction $resolveToken,
        SubmitSurveyResponseAction $submitResponse,
    ): JsonResponse {
        $survey = Survey::with('fields')->where('public_key', $publicKey)->firstOrFail();

        $resolved = null;

        if ($rawToken = $request->query('t')) {
            try {
                $resolved = $resolveToken->execute($survey, (string) $rawToken);
            } catch (InvalidSurveyTokenException $e) {
                return response()->json(['message' => $e->getMessage()], 403);
            }
        }

        $payload = new SubmissionPayload(
            visibleAnswers: $request->answers(),
            tokenContext: $resolved,
        );

        try {
            $response = $submitResponse->execute(
                $survey,
                $payload,
                ip: $request->ip() ?? '',
                userAgent: $request->userAgent() ?? '',
            );
        } catch (SurveyNotAvailableException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (SurveyValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->getErrors()], 422);
        }

        return response()->json([
            'message'     => $survey->submit_success_message ?? 'Thank you for your submission.',
            'response_id' => $response->id,
        ], 201);
    }
}
