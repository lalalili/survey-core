<?php

namespace Lalalili\SurveyCore\Support;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Lalalili\SurveyCore\Models\GoogleDriveAccount;
use RuntimeException;

/**
 * Wraps Google\Client for the Drive OAuth + token lifecycle so controllers,
 * the disk factory and the upload job depend on this single seam (and tests can
 * bind a fake in the container instead of calling Google).
 */
class GoogleDriveClientFactory
{
    public function isConfigured(): bool
    {
        return (bool) config('survey-core.google_drive.enabled')
            && (string) config('survey-core.google_drive.client_id') !== ''
            && (string) config('survey-core.google_drive.client_secret') !== '';
    }

    public function baseClient(): Client
    {
        $client = new Client;
        $client->setClientId((string) config('survey-core.google_drive.client_id'));
        $client->setClientSecret((string) config('survey-core.google_drive.client_secret'));
        $client->setRedirectUri((string) config('survey-core.google_drive.redirect_uri'));
        $client->setScopes((array) config('survey-core.google_drive.scopes', ['https://www.googleapis.com/auth/drive.file']));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    public function authorizationUrl(string $state): string
    {
        $client = $this->baseClient();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * Exchange an OAuth code for tokens + the authorising account's identity.
     *
     * @return array{token: array<string, mixed>, google_user_id: string, email: ?string, name: ?string}
     */
    public function exchangeAuthCode(string $code): array
    {
        $client = $this->baseClient();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('Google OAuth 失敗：'.($token['error_description'] ?? $token['error']));
        }

        $client->setAccessToken($token);
        $payload = $client->verifyIdToken();

        if (! is_array($payload) || ! isset($payload['sub'])) {
            throw new RuntimeException('無法取得 Google 帳號識別資訊。');
        }

        return [
            'token' => $token,
            'google_user_id' => (string) $payload['sub'],
            'email' => isset($payload['email']) ? (string) $payload['email'] : null,
            'name' => isset($payload['name']) ? (string) $payload['name'] : null,
        ];
    }

    /**
     * Persist token data onto an account row.
     *
     * @param  array<string, mixed>  $token
     */
    public function storeToken(GoogleDriveAccount $account, array $token): void
    {
        $account->access_token = $token['access_token'] ?? $account->access_token;

        if (! empty($token['refresh_token'])) {
            $account->refresh_token = $token['refresh_token'];
        }

        $expiresIn = (int) ($token['expires_in'] ?? 0);
        $account->token_expires_at = $expiresIn > 0 ? now()->addSeconds($expiresIn) : null;
        $account->save();
    }

    /**
     * Return a non-expired access token, refreshing and persisting if needed.
     */
    public function freshAccessToken(GoogleDriveAccount $account): string
    {
        if (! $account->isTokenExpired() && (string) $account->access_token !== '') {
            return (string) $account->access_token;
        }

        if ((string) $account->refresh_token === '') {
            throw new RuntimeException('Google Drive 帳號缺少 refresh token，請重新綁定。');
        }

        $client = $this->baseClient();
        $token = $client->fetchAccessTokenWithRefreshToken((string) $account->refresh_token);

        if (isset($token['error'])) {
            throw new RuntimeException('Google Drive token 更新失敗：'.($token['error_description'] ?? $token['error']));
        }

        $this->storeToken($account, $token);

        return (string) $account->access_token;
    }

    /**
     * A Drive service authenticated as the account.
     */
    public function driveService(GoogleDriveAccount $account): Drive
    {
        $client = $this->baseClient();
        $client->setAccessToken($this->freshAccessToken($account));

        return new Drive($client);
    }

    /**
     * Upload a file into the account's Drive (optionally inside a folder) and
     * return its id + shareable view link. Does not make the file public.
     *
     * @param  resource|string  $contents
     * @return array{id: string, link: ?string}
     */
    public function uploadFile(GoogleDriveAccount $account, ?string $folderId, string $name, mixed $contents, string $mimeType): array
    {
        $drive = $this->driveService($account);

        $file = new DriveFile(array_filter([
            'name' => $name,
            'parents' => ($folderId !== null && $folderId !== '') ? [$folderId] : null,
        ]));

        $created = $drive->files->create($file, [
            'data' => is_resource($contents) ? stream_get_contents($contents) : $contents,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id, webViewLink',
        ]);

        return [
            'id' => (string) $created->getId(),
            'link' => $created->getWebViewLink(),
        ];
    }

    /**
     * Ensure a folder exists in the account's Drive and return its id.
     */
    public function ensureFolder(GoogleDriveAccount $account, string $name, ?string $existingFolderId = null): string
    {
        $drive = $this->driveService($account);

        if ($existingFolderId !== null && $existingFolderId !== '') {
            try {
                $drive->files->get($existingFolderId, ['fields' => 'id, trashed']);

                return $existingFolderId;
            } catch (\Throwable) {
                // 資料夾不存在或無權限，往下重新建立。
            }
        }

        $folder = new DriveFile([
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);

        $created = $drive->files->create($folder, ['fields' => 'id']);

        return (string) $created->getId();
    }
}
