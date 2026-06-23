<?php

use Lalalili\SurveyCore\Support\EmailCampaignIntegration;

it('can disable the optional email campaign integration through config', function () {
    config()->set('survey-core.integrations.email_campaign.enabled', false);

    expect(EmailCampaignIntegration::enabled())->toBeFalse();
});

it('detects the optional email campaign integration when available', function () {
    config()->set('survey-core.integrations.email_campaign.enabled', null);

    expect(EmailCampaignIntegration::enabled())->toBeTrue();
});
