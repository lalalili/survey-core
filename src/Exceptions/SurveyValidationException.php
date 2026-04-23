<?php

namespace Lalalili\SurveyCore\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SurveyValidationException extends RuntimeException
{
    /** @param  array<string, array<int, string>>  $errors */
    public function __construct(
        private readonly array $errors,
        string $message = 'Survey submission validation failed.',
        int $code = Response::HTTP_UNPROCESSABLE_ENTITY,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, array<int, string>> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
