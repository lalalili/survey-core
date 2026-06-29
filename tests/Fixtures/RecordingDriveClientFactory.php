<?php

namespace Lalalili\SurveyCore\Tests\Fixtures;

use Lalalili\SurveyCore\Models\GoogleDriveAccount;
use Lalalili\SurveyCore\Support\GoogleDriveClientFactory;

class RecordingDriveClientFactory extends GoogleDriveClientFactory
{
    /** @var list<array{folder: ?string, name: string}> */
    public array $uploads = [];

    /**
     * @return array{id: string, link: string}
     */
    public function uploadFile(GoogleDriveAccount $account, ?string $folderId, string $name, mixed $contents, string $mimeType): array
    {
        $this->uploads[] = ['folder' => $folderId, 'name' => $name];

        return [
            'id' => 'drive-'.count($this->uploads),
            'link' => 'https://drive.google.com/file/d/drive-'.count($this->uploads).'/view',
        ];
    }
}
