<?php

namespace Lalalili\SurveyCore\Contracts;

use Symfony\Component\HttpFoundation\StreamedResponse;

interface SurveyExportDriver
{
    /**
     * @param  iterable<array<array-key, mixed>>  $rows
     * @param  array<int, string>  $headers
     */
    public function write(iterable $rows, array $headers): StreamedResponse;
}
