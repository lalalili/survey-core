<?php

namespace Lalalili\SurveyCore\Tests\Fixtures;

use Lalalili\SurveyCore\Models\GoogleDriveAccount;
use Lalalili\SurveyCore\Support\GoogleDriveClientFactory;

class RecordingDriveClientFactory extends GoogleDriveClientFactory
{
    /** @var list<array{id: string, name: string, parent: ?string, existing: ?string}> */
    public array $folders = [];

    /** @var list<array{folder: ?string, name: string}> */
    public array $uploads = [];

    public function ensureFolder(GoogleDriveAccount $account, string $name, ?string $existingFolderId = null, ?string $parentFolderId = null): string
    {
        foreach ($this->folders as $folder) {
            if ($folder['name'] === $name && $folder['parent'] === $parentFolderId) {
                return $folder['id'];
            }
        }

        if ($existingFolderId !== null && $existingFolderId !== '') {
            foreach ($this->folders as $folder) {
                if ($folder['id'] === $existingFolderId && $folder['parent'] === $parentFolderId) {
                    return $existingFolderId;
                }
            }
        }

        $id = 'folder-'.(count($this->folders) + 1);
        $this->folders[] = ['id' => $id, 'name' => $name, 'parent' => $parentFolderId, 'existing' => $existingFolderId];

        return $id;
    }

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
