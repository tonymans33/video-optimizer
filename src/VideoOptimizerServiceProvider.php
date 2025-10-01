<?php

namespace Tonymans33\VideoOptimizer;

use Illuminate\Filesystem\Filesystem;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class VideoOptimizerServiceProvider extends PackageServiceProvider
{
    public static string $name = 'video-optimizer';

    public function boot()
    {
        parent::boot();

        // Note: Class aliasing doesn't work for overriding Filament components
        // Users should directly use the custom components instead
        // AliasLoader::getInstance()->alias(BaseFileUpload::class, CustomBaseFileUpload::class);
        // AliasLoader::getInstance()->alias(SpatieMediaLibraryFileUpload::class, CustomSpatieMediaLibraryFileUpload::class);
    }

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('tonymans33/video-optimizer');
            });

        $configFileName = $package->shortName();

        if (file_exists($package->basePath("/../config/{$configFileName}.php"))) {
            $package->hasConfigFile();
        }

        if (file_exists($package->basePath('/../resources/lang'))) {
            $package->hasTranslations();
        }
    }

    public function packageBooted(): void
    {
        // Handle Stubs
        if (app()->runningInConsole()) {
            foreach (app(Filesystem::class)->files(__DIR__ . '/../stubs/') as $file) {
                $this->publishes([
                    $file->getRealPath() => base_path("stubs/video-optimizer/{$file->getFilename()}"),
                ], 'video-optimizer-stubs');
            }
        }
    }
}