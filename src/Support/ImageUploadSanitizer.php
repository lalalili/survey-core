<?php

namespace Lalalili\SurveyCore\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LaravelAt\ImageSanitize\ImageSanitize;
use RuntimeException;

class ImageUploadSanitizer
{
    public function __construct(
        private readonly ImageSanitize $imageSanitize,
    ) {}

    public function sanitize(UploadedFile $file): void
    {
        if (! $this->isSanitizableImage($file)) {
            return;
        }

        $contents = $file->get();

        if ($contents === false) {
            throw new RuntimeException('The uploaded image could not be read.');
        }

        if (! $this->imageSanitize->detect($contents)) {
            return;
        }

        if (file_put_contents($file->getPathname(), (string) $this->imageSanitize->sanitize($contents)) === false) {
            throw new RuntimeException('The uploaded image could not be sanitized.');
        }
    }

    public function store(
        UploadedFile $file,
        string $directory,
        string $disk,
        ?string $visibility = null,
    ): string|false {
        $this->sanitize($file);

        $path = $file->store($directory, $disk);

        if (is_string($path) && $visibility === 'public') {
            rescue(fn () => Storage::disk($disk)->setVisibility($path, 'public'), report: false);
        }

        return $path;
    }

    private function isSanitizableImage(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), (array) config('image-sanitize.allowed_mime_types', []), true);
    }
}
