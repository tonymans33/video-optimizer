<?php

namespace Tonymans33\VideoOptimizer;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\AliasLoader;
use Tonymans33\VideoOptimizer\Components\BaseFileUpload as CustomBaseFileUpload;
use Tonymans33\VideoOptimizer\Components\SpatieMediaLibraryFileUpload as CustomSpatieMediaLibraryFileUpload;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class VideoOptimizerServiceProvider extends PackageServiceProvider
{
    public static string $name = 'video-optimizer';

    public function boot()
    {
        AliasLoader::getInstance()->alias(BaseFileUpload::class, CustomBaseFileUpload::class);
        AliasLoader::getInstance()->alias(SpatieMediaLibraryFileUpload::class, CustomSpatieMediaLibraryFileUpload::class);
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