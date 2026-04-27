<?php

namespace Lalalili\SurveyCore\Enums;

enum SurveyPageKind: string
{
    case Welcome = 'welcome';
    case Question = 'question';
    case ThankYou = 'thank_you';
}
