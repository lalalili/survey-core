<?php

namespace Lalalili\SurveyCore\Enums;

enum DmsParameterConfirmation: string
{
    case Pending = 'pending';
    case Tested = 'tested';
    case Confirmed = 'confirmed';
}
