<?php

namespace Lalalili\SurveyCore\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SurveyNotAvailableException extends RuntimeException
{
    public function __construct(string $message = 'Survey is not available.', int $code = Response::HTTP_FORBIDDEN, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->code ?: Response::HTTP_FORBIDDEN;
    }
}
