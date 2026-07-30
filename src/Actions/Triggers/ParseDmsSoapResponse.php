<?php

namespace Lalalili\SurveyCore\Actions\Triggers;

use DOMDocument;
use DOMXPath;
use Lalalili\SurveyCore\Data\DmsSoapResponseResult;
use Lalalili\SurveyCore\Enums\SurveyTriggerActionAttemptStatus;

final class ParseDmsSoapResponse
{
    /**
     * @param  list<string>  $successCodes
     */
    public function execute(
        string $body,
        int $httpStatus,
        array $successCodes,
        bool $emptyResponseIsSuccess = false,
    ): DmsSoapResponseResult {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return new DmsSoapResponseResult(
                SurveyTriggerActionAttemptStatus::HttpError,
                ['http_status' => $httpStatus],
                "DMS returned HTTP {$httpStatus}.",
            );
        }

        $document = new DOMDocument;

        if (! @$document->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return new DmsSoapResponseResult(
                SurveyTriggerActionAttemptStatus::PendingReview,
                ['unparseable_xml' => true],
                'DMS returned an unparseable XML response.',
            );
        }

        $xpath = new DOMXPath($document);
        $fault = trim((string) $xpath->evaluate('string(//*[local-name()="Fault"]/*[local-name()="faultstring"][1])'));

        if ($fault !== '') {
            return new DmsSoapResponseResult(
                SurveyTriggerActionAttemptStatus::SoapFault,
                ['fault' => $fault],
                $fault,
            );
        }

        $errorCode = trim((string) $xpath->evaluate('string(//*[local-name()="error_code"][1])'));
        $errorMessage = trim((string) $xpath->evaluate('string(//*[local-name()="error_msg"][1])'));
        $parsed = ['error_code' => $errorCode, 'error_msg' => $errorMessage];

        if ($successCodes !== [] && in_array($errorCode, $successCodes, true)) {
            return new DmsSoapResponseResult(
                SurveyTriggerActionAttemptStatus::Success,
                $parsed,
                null,
            );
        }

        if ($errorCode !== '' || $errorMessage !== '') {
            return new DmsSoapResponseResult(
                SurveyTriggerActionAttemptStatus::BusinessError,
                $parsed,
                $errorMessage !== '' ? $errorMessage : "DMS business error {$errorCode}.",
            );
        }

        if ($emptyResponseIsSuccess) {
            return new DmsSoapResponseResult(
                SurveyTriggerActionAttemptStatus::Success,
                $parsed,
                null,
            );
        }

        return new DmsSoapResponseResult(
            SurveyTriggerActionAttemptStatus::PendingReview,
            $parsed,
            'DMS response semantics are not confirmed.',
        );
    }
}
