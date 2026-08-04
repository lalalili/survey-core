<?php

use Lalalili\SurveyCore\Actions\CreateCascadeSelectTemplateAction;
use Lalalili\SurveyCore\Actions\ParseCascadeSelectImportAction;

it('creates a valid xlsx cascade select template that can be imported', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'cascade-select-template-test-');

    expect($path)->toBeString();

    $xlsxPath = $path.'.xlsx';
    rename($path, $xlsxPath);

    (new CreateCascadeSelectTemplateAction())->writeToPath($xlsxPath);

    $payload = (new ParseCascadeSelectImportAction())->execute($xlsxPath);

    expect($payload['levels'])
        ->toHaveCount(2)
        ->sequence(
            fn ($level) => $level->label->toBe('縣市'),
            fn ($level) => $level->label->toBe('鄉鎮區'),
        )
        ->and($payload['data'])
        ->toHaveCount(2)
        ->sequence(
            fn ($node) => $node->label->toBe('台北市')
                ->children->toHaveCount(3),
            fn ($node) => $node->label->toBe('新北市')
                ->children->toHaveCount(3),
        );

    unlink($xlsxPath);
});
