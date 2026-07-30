<?php

namespace Lalalili\SurveyCore\Enums;

enum SurveyTriggerActionAttemptStatus: string
{
    case PendingReview = 'pending_review';
    case Skipped = 'skipped';
    case Success = 'success';
    case BusinessError = 'business_error';
    case SoapFault = 'soap_fault';
    case HttpError = 'http_error';
    case ConnectionError = 'connection_error';
}
