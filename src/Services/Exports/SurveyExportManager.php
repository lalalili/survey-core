<?php

namespace Lalalili\SurveyCore\Services\Exports;

use Closure;
use InvalidArgumentException;
use Lalalili\SurveyCore\Contracts\SurveyExportDriver;

class SurveyExportManager
{
    /** @var array<string, Closure(): SurveyExportDriver> */
    private array $drivers = [];

    public function extend(string $name, Closure $factory): void
    {
        $this->drivers[$name] = $factory;
    }

    public function driver(string $name): SurveyExportDriver
    {
        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException("Survey export driver [{$name}] is not registered.");
        }

        return ($this->drivers[$name])();
    }
}
