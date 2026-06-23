<?php

namespace Lalalili\SurveyCore\Support;

class EmailCampaignIntegration
{
    public static function enabled(): bool
    {
        if (config('survey-core.integrations.email_campaign.enabled') === false) {
            return false;
        }

        return class_exists(self::variableProviderRegistryClass())
            && interface_exists('Lalalili\\EmailCampaign\\Contracts\\VariableProvider');
    }

    public static function variableProviderRegistryClass(): string
    {
        return 'Lalalili\\EmailCampaign\\Support\\VariableProviderRegistry';
    }

    public static function transactionalEmailActionClass(): string
    {
        return 'Lalalili\\EmailCampaign\\Actions\\SendTransactionalEmailAction';
    }
}
