<?php

namespace Lalalili\SurveyCore\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class InvalidSurveyTokenException extends RuntimeException
{
    public function __construct(string $message = 'Survey token is invalid, expired, or exhausted.', int $code = Response::HTTP_FORBIDDEN, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
