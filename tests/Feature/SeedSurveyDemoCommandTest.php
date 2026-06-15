<?php

it('seeds survey demo data without the optional marketing automation package', function () {
    expect(class_exists('Lalalili\\MarketingAutomation\\Models\\MarketingActivity'))->toBeFalse();

    $this->artisan('survey:seed-demo')
        ->expectsOutputToContain('MarketingActivity 綁定：略過')
        ->assertSuccessful();
});
