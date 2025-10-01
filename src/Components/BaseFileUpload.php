<?php

namespace Tonymans33\VideoOptimizer\Components;

use Closure;
use Filament\Forms\Components\FileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;
use FFMpeg\Format\Video\WebM as WebMFormat;
use FFMpeg\Format\Video\X264;
use Throwable;

class BaseFileUpload extends FileUpload
{
    protected string | Closure | null $optimize = null;
    protected string | Closure | null $format = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Override the saveUploadedFileUsing callback to add video optimization
        $this->saveUploadedFileUsing(static function (BaseFileUpload $component, TemporaryUploadedFile $file): ?string {
            try {
                if (! $file->exists()) {
                    return null;
                }
            } catch (\Exception $exception) {
                return null;
            }

            // Check if it's a video file and needs optimization
            if (
                str_starts_with($file->getMimeType(), 'video/') &&
                ($component->getOptimization() || $component->getFormat())
            ) {
                try {
                    // Store temporary file for FFmpeg processing
                    $tmpPath = $file->store('tmp/video-optimizer', 'local');
                    $srcDisk = 'local';

                    // Determine output format
                    $outputFormat = $component->getFormat() ?: 'webm';

                    // Output name with new extension
                    $base = pathinfo($component->getUploadedFileNameForStorage($file), PATHINFO_FILENAME);
                    $dest = trim($component->getDirectory() . '/' . $base . '.' . $outputFormat, '/');

                    // Create format instance based on output format
                    $videoFormat = match ($outputFormat) {
                        'webm' => (new WebMFormat())
                            ->setVideoCodec('libvpx-vp9')
                            ->setAudioCodec('libvorbis'),
                        'mp4' => new X264(),
                        default => (new WebMFormat())
                            ->setVideoCodec('libvpx-vp9')
                            ->setAudioCodec('libvorbis'),
                    };

                    // Set quality based on optimization level
                    if ($optimize = $component->getOptimization()) {
                        $crf = match ($optimize) {
                            'low' => '36',
                            'medium' => '34',
                            'high' => '28',
                            default => '34',
                        };

                        if ($outputFormat === 'webm') {
                            $videoFormat->setAdditionalParameters([
                                '-crf', $crf,
                                '-b:v', '0',
                                '-row-mt', '1',
                                '-deadline', 'good',
                                '-cpu-used', '4',
                            ]);
                        } else {
                            $videoFormat->setAdditionalParameters(['-crf', $crf]);
                        }
                    }

                    // Export with FFmpeg
                    $export = FFMpeg::fromDisk($srcDisk)
                        ->open($tmpPath)
                        ->export()
                        ->toDisk($component->getDiskName())
                        ->inFormat($videoFormat);

                    // Add scaling filter if needed (optional - you can make this configurable)
                    if ($outputFormat === 'webm') {
                        $export->addFilter('-vf', 'scale=w=1280:h=720:force_original_aspect_ratio=decrease');
                    }

                    // Save to destination disk
                    $export->save($dest);

                    // Clean up temporary file
                    Storage::disk($srcDisk)->delete($tmpPath);

                    return $dest;
                } catch (Throwable $e) {
                    // Log error and fall back to normal upload
                    report($e);
                }
            }

            // Fall back to normal file upload behavior (for non-videos or if optimization fails)
            if (
                $component->shouldMoveFiles() &&
                ($component->getDiskName() === (fn (): string => $this->disk)->call($file))
            ) {
                $newPath = trim($component->getDirectory() . '/' . $component->getUploadedFileNameForStorage($file), '/');

                $component->getDisk()->move((fn (): string => $this->path)->call($file), $newPath);

                return $newPath;
            }

            $storeMethod = $component->getVisibility() === 'public' ? 'storePubliclyAs' : 'storeAs';

            return $file->{$storeMethod}(
                $component->getDirectory(),
                $component->getUploadedFileNameForStorage($file),
                $component->getDiskName(),
            );
        });
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
}
