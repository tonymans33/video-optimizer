<?php

namespace Tonymans33\VideoOptimizer\Components;

use Closure;
use Filament\Forms\Components\Concerns;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\WebM;
use FFMpeg\Format\Video\X264;
use League\Flysystem\UnableToCheckFileExistence;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;


class BaseFileUpload extends Field
{
    use Concerns\HasUploadingMessage;

    /**
     * @var array<string> | Arrayable | Closure | null
     */
    protected array | Arrayable | Closure | null $acceptedFileTypes = null;

    protected bool | Closure $isDeletable = true;

    protected bool | Closure $isDownloadable = false;

    protected bool | Closure $isOpenable = false;

    protected bool | Closure $isPreviewable = true;

    protected bool | Closure $isReorderable = false;

    protected string | Closure | null $directory = null;

    protected string | Closure | null $diskName = null;

    protected bool | Closure $isMultiple = false;

    protected int | Closure | null $maxSize = null;

    protected int | Closure | null $minSize = null;

    protected int | Closure | null $maxParallelUploads = null;

    protected int | Closure | null $maxFiles = null;

    protected int | Closure | null $minFiles = null;

    protected string | Closure | null $optimize = null;

    protected string | Closure | null $format = null;

    protected bool | Closure $shouldPreserveFilenames = false;

    protected bool | Closure $shouldMoveFiles = false;

    protected bool | Closure $shouldStoreFiles = true;

    protected bool | Closure $shouldFetchFileInformation = true;

    protected string | Closure | null $fileNamesStatePath = null;

    protected string | Closure $visibility = 'public';

    protected ?Closure $deleteUploadedFileUsing = null;

    protected ?Closure $getUploadedFileNameForStorageUsing = null;

    protected ?Closure $getUploadedFileUsing = null;

    protected ?Closure $reorderUploadedFilesUsing = null;

    protected ?Closure $saveUploadedFileUsing = null;

    protected bool | Closure $isPasteable = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->afterStateHydrated(static function (BaseFileUpload $component, string | array | null $state): void {
            if (blank($state)) {
                $component->state([]);

                return;
            }

            $shouldFetchFileInformation = $component->shouldFetchFileInformation();

            $files = collect(Arr::wrap($state))
                ->filter(static function (string $file) use ($component, $shouldFetchFileInformation): bool {
                    if (blank($file)) {
                        return false;
                    }

                    if (! $shouldFetchFileInformation) {
                        return true;
                    }

                    try {
                        return $component->getDisk()->exists($file);
                    } catch (UnableToCheckFileExistence $exception) {
                        return false;
                    }
                })
                ->mapWithKeys(static fn(string $file): array => [((string) Str::uuid()) => $file])
                ->all();

            $component->state($files);
        });

        $this->afterStateUpdated(static function (BaseFileUpload $component, $state) {
            if ($state instanceof TemporaryUploadedFile) {
                return;
            }

            if (blank($state)) {
                return;
            }

            if (is_array($state)) {
                return;
            }

            $component->state([(string) Str::uuid() => $state]);
        });

        $this->beforeStateDehydrated(static function (BaseFileUpload $component): void {
            $component->saveUploadedFiles();
        });

        $this->dehydrateStateUsing(static function (BaseFileUpload $component, ?array $state): string | array | null | TemporaryUploadedFile {
            $files = array_values($state ?? []);

            if ($component->isMultiple()) {
                return $files;
            }

            return $files[0] ?? null;
        });

        $this->getUploadedFileUsing(static function (BaseFileUpload $component, string $file, string | array | null $storedFileNames): ?array {
            /** @var FilesystemAdapter $storage */
            $storage = $component->getDisk();

            $shouldFetchFileInformation = $component->shouldFetchFileInformation();

            if ($shouldFetchFileInformation) {
                try {
                    if (! $storage->exists($file)) {
                        return null;
                    }
                } catch (UnableToCheckFileExistence $exception) {
                    return null;
                }
            }

            $url = null;

            if ($component->getVisibility() === 'private') {
                try {
                    $url = $storage->temporaryUrl(
                        $file,
                        now()->addMinutes(5),
                    );
                } catch (Throwable $exception) {
                    // This driver does not support creating temporary URLs.
                }
            }

            $url ??= $storage->url($file);

            return [
                'name' => ($component->isMultiple() ? ($storedFileNames[$file] ?? null) : $storedFileNames) ?? basename($file),
                'size' => $shouldFetchFileInformation ? $storage->size($file) : 0,
                'type' => $shouldFetchFileInformation ? $storage->mimeType($file) : null,
                'url' => $url,
            ];
        });

        $this->getUploadedFileNameForStorageUsing(static function (BaseFileUpload $component, TemporaryUploadedFile $file) {
            return $component->shouldPreserveFilenames() ? $file->getClientOriginalName() : (Str::ulid() . '.' . $file->getClientOriginalExtension());
        });

        $this->saveUploadedFileUsing(static function (BaseFileUpload $component, TemporaryUploadedFile $file): ?string {
            try {
                if (! $file->exists()) {
                    return null;
                }
            } catch (UnableToCheckFileExistence $exception) {
                return null;
            }

            $optimizedVideo = null;
            $filename = $component->getUploadedFileNameForStorage($file);
            $optimize = $component->getOptimization();
            $format = $component->getFormat();

            if (
                str_contains($file->getMimeType(), 'video') &&
                ($optimize || $format)
            ) {
                try {
                    // Store temporary file for FFmpeg processing
                    $tempDisk = 'local';
                    $tempPath = $file->store('tmp/video-optimizer', $tempDisk);

                    // Initialize FFMpeg
                    $ffmpeg = FFMpeg::create();
                    $video = $ffmpeg->open(Storage::disk($tempDisk)->path($tempPath));

                    // Determine output format
                    $outputFormat = $format ?: 'webm';
                    $filename = self::formatVideoFilename($filename, $outputFormat);

                    // Create format instance
                    $videoFormat = match ($outputFormat) {
                        'webm' => new WebM(),
                        'mp4' => new X264(),
                        default => new WebM(),
                    };

                    // Set quality based on optimization level
                    if ($optimize) {
                        $parameters = match ($optimize) {
                            'low' => ['-crf', '36'],
                            'medium' => ['-crf', '28'],
                            'high' => ['-crf', '20'],
                            default => ['-crf', '28'],
                        };
                        $videoFormat->setAdditionalParameters($parameters);
                    }

                    // Create temporary output file
                    $outputTempPath = $tempPath . '_output.' . $outputFormat;
                    $outputTempFullPath = Storage::disk($tempDisk)->path($outputTempPath);

                    // Save optimized video
                    $video->save($videoFormat, $outputTempFullPath);

                    // Read optimized content
                    $optimizedVideo = Storage::disk($tempDisk)->get($outputTempPath);

                    // Clean up temporary files
                    Storage::disk($tempDisk)->delete($tempPath);
                    Storage::disk($tempDisk)->delete($outputTempPath);
                } catch (Throwable $e) {
                    // Log error and continue with original file
                    report($e);
                }
            }

            if ($optimizedVideo) {
                Storage::disk($component->getDiskName())->put(
                    $component->getDirectory() . '/' . $filename,
                    $optimizedVideo
                );

                return $component->getDirectory() . '/' . $filename;
            }

            if ($component->shouldMoveFiles()) {
                try {
                    $newPath = trim($component->getDirectory() . '/' . $component->getUploadedFileNameForStorage($file), '/');

                    // Use reflection to safely access the private properties
                    $diskProperty = new \ReflectionProperty($file, 'disk');
                    $pathProperty = new \ReflectionProperty($file, 'path');
                    $diskProperty->setAccessible(true);
                    $pathProperty->setAccessible(true);

                    // Check if the file is on the same disk before moving
                    if ($component->getDiskName() === $diskProperty->getValue($file)) {
                        $component->getDisk()->move($pathProperty->getValue($file), $newPath);
                        return $newPath;
                    }
                } catch (\ReflectionException | \Exception $e) {
                    // Fall through to normal storage if move fails or reflection errors occur
                    // This ensures the upload still works even if the optimization fails
                }
            }

            $storeMethod = $component->getVisibility() === 'public' ? 'storePubliclyAs' : 'storeAs';

            return $file->{$storeMethod}(
                $component->getDirectory(),
                $component->getUploadedFileNameForStorage($file),
                $component->getDiskName()
            );
        });
    }

    // All the same methods as the image optimizer...
    protected function callAfterStateUpdatedHook(Closure $hook): void
    {
        $state = $this->getState();

        $this->evaluate($hook, [
            'state' => $this->isMultiple() ? $state : Arr::first($state ?? []),
            'old' => $this->isMultiple() ? $this->getOldState() : Arr::first($this->getOldState() ?? []),
        ]);
    }

    public function callAfterStateUpdated(bool $shouldBubbleToParents = true): static
    {
        $state = $this->getState();

        foreach ($this->afterStateUpdated as $callback) {
            $this->evaluate($callback, [
                'state' => $this->isMultiple() ? $state : Arr::first($state ?? []),
            ]);
        }

        return $this;
    }

    public function acceptedFileTypes(array | Arrayable | Closure $types): static
    {
        $this->acceptedFileTypes = $types;

        $this->rule(static function (BaseFileUpload $component) {
            $types = implode(',', ($component->getAcceptedFileTypes() ?? []));

            return "mimetypes:{$types}";
        });

        return $this;
    }

    public function optimize(string | Closure | null $optimize): static
    {
        $this->optimize = $optimize;

        return $this;
    }

    public function format(string | Closure | null $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function getOptimization(): ?string
    {
        return $this->evaluate($this->optimize) ?? config('video-optimizer.optimize');
    }

    public function getFormat(): ?string
    {
        return $this->evaluate($this->format) ?? config('video-optimizer.format');
    }

    public static function formatVideoFilename(string $filename, string $format): string
    {
        $pathInfo = pathinfo($filename);
        $basename = $pathInfo['filename'] ?? $filename;

        return $basename . '.' . $format;
    }

    // Include all other methods from BaseFileUpload (copied from image optimizer structure)
    public function deletable(bool | Closure $condition = true): static
    {
        $this->isDeletable = $condition;
        return $this;
    }

    public function directory(string | Closure | null $directory): static
    {
        $this->directory = $directory;
        return $this;
    }

    public function disk(string | Closure | null $name): static
    {
        $this->diskName = $name;
        return $this;
    }

    public function downloadable(bool | Closure $condition = true): static
    {
        $this->isDownloadable = $condition;
        return $this;
    }

    public function openable(bool | Closure $condition = true): static
    {
        $this->isOpenable = $condition;
        return $this;
    }

    public function reorderable(bool | Closure $condition = true): static
    {
        $this->isReorderable = $condition;
        return $this;
    }

    public function previewable(bool | Closure $condition = true): static
    {
        $this->isPreviewable = $condition;
        return $this;
    }

    public function multiple(bool | Closure $condition = true): static
    {
        $this->isMultiple = $condition;
        return $this;
    }

    public function maxSize(int | Closure | null $size): static
    {
        $this->maxSize = $size;

        $this->rule(static function (BaseFileUpload $component): string {
            $size = $component->getMaxSize();
            return "max:{$size}";
        });

        return $this;
    }

    public function minSize(int | Closure | null $size): static
    {
        $this->minSize = $size;

        $this->rule(static function (BaseFileUpload $component): string {
            $size = $component->getMinSize();
            return "min:{$size}";
        });

        return $this;
    }

    public function maxFiles(int | Closure | null $count): static
    {
        $this->maxFiles = $count;
        return $this;
    }

    public function minFiles(int | Closure | null $count): static
    {
        $this->minFiles = $count;
        return $this;
    }

    public function visibility(string | Closure | null $visibility): static
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function preserveFilenames(bool | Closure $condition = true): static
    {
        $this->shouldPreserveFilenames = $condition;
        return $this;
    }

    // Getter methods
    public function isDeletable(): bool
    {
        return (bool) $this->evaluate($this->isDeletable);
    }

    public function isDownloadable(): bool
    {
        return (bool) $this->evaluate($this->isDownloadable);
    }

    public function isOpenable(): bool
    {
        return (bool) $this->evaluate($this->isOpenable);
    }

    public function isPreviewable(): bool
    {
        return (bool) $this->evaluate($this->isPreviewable);
    }

    public function isReorderable(): bool
    {
        return (bool) $this->evaluate($this->isReorderable);
    }

    public function getAcceptedFileTypes(): ?array
    {
        $types = $this->evaluate($this->acceptedFileTypes);

        if ($types instanceof Arrayable) {
            $types = $types->toArray();
        }

        return $types;
    }

    public function getDirectory(): ?string
    {
        return $this->evaluate($this->directory);
    }

    public function getDisk(): Filesystem
    {
        return Storage::disk($this->getDiskName());
    }

    public function getDiskName(): string
    {
        return $this->evaluate($this->diskName) ?? config('filament.default_filesystem_disk');
    }

    public function getMaxFiles(): ?int
    {
        return $this->evaluate($this->maxFiles);
    }

    public function getMinFiles(): ?int
    {
        return $this->evaluate($this->minFiles);
    }

    public function getMaxSize(): ?int
    {
        return $this->evaluate($this->maxSize);
    }

    public function getMinSize(): ?int
    {
        return $this->evaluate($this->minSize);
    }

    public function getVisibility(): string
    {
        return $this->evaluate($this->visibility) ?? 'public';
    }

    public function shouldPreserveFilenames(): bool
    {
        return (bool) $this->evaluate($this->shouldPreserveFilenames);
    }

    public function shouldMoveFiles(): bool
    {
        return $this->evaluate($this->shouldMoveFiles);
    }

    public function shouldFetchFileInformation(): bool
    {
        return (bool) $this->evaluate($this->shouldFetchFileInformation);
    }

    public function shouldStoreFiles(): bool
    {
        return $this->evaluate($this->shouldStoreFiles);
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function getUploadedFileNameForStorage(TemporaryUploadedFile $file): string
    {
        return $this->evaluate($this->getUploadedFileNameForStorageUsing, [
            'file' => $file,
        ]) ?? ($this->shouldPreserveFilenames() ? $file->getClientOriginalName() : (Str::ulid() . '.' . $file->getClientOriginalExtension()));
    }

    // Include all other methods to match the complete BaseFileUpload structure...
    public function saveUploadedFiles(): void
    {
        if (blank($this->getState())) {
            $this->state([]);
            return;
        }

        if (! $this->shouldStoreFiles()) {
            return;
        }

        $state = array_filter(array_map(function (TemporaryUploadedFile | string $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                return $file;
            }

            $callback = $this->saveUploadedFileUsing;

            if (! $callback) {
                $file->delete();
                return $file;
            }

            $storedFile = $this->evaluate($callback, [
                'file' => $file,
            ]);

            if ($storedFile === null) {
                return null;
            }

            $this->storeFileName((string) $storedFile, $file->getClientOriginalName());
            $file->delete();

            return $storedFile;
        }, Arr::wrap($this->getState())));

        $this->state($state);
    }

    public function storeFileName(string $file, string $fileName): void
    {
        $statePath = $this->fileNamesStatePath;

        if (blank($statePath)) {
            return;
        }

        $this->evaluate(function (BaseFileUpload $component, Get $get, Set $set) use ($file, $fileName, $statePath) {
            if (! $component->isMultiple()) {
                $set($statePath, $fileName);
                return;
            }

            $fileNames = $get($statePath) ?? [];
            $fileNames[$file] = $fileName;

            $set($statePath, $fileNames);
        });
    }

    public function pasteable(bool | Closure $condition = true): static
    {
        $this->isPasteable = $condition;
        return $this;
    }

    public function isPasteable(): bool
    {
        return (bool) $this->evaluate($this->isPasteable);
    }
}
