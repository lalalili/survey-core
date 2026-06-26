<?php

use Lalalili\SurveyCore\Support\EmailCampaignIntegration;

it('is disabled when the email campaign integration config is false', function () {
    config()->set('survey-core.integrations.email_campaign.enabled', false);

    expect(EmailCampaignIntegration::enabled())->toBeFalse();
});

it('is enabled when email campaign contracts are installed and config does not disable it', function () {
    config()->set('survey-core.integrations.email_campaign.enabled', null);

    expect(EmailCampaignIntegration::enabled())->toBeTrue();
});
