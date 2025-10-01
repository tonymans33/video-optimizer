# Video Optimizer for Filament

Optimize your Filament videos before they reach your database using FFmpeg.

## Installation

```bash
composer require tonymans33/video-optimizer
```

Publish the config file:

```bash
php artisan vendor:publish --tag="video-optimizer-config"
```

## Requirements

- PHP 8.2+
- Laravel 11.0+
- Filament 4.0+
- FFmpeg installed on your server

## Usage

This package provides custom FileUpload components that automatically optimize videos during upload.

### Basic FileUpload Component

Instead of using Filament's default `FileUpload`, use the custom component from this package:

```php
use Tonymans33\VideoOptimizer\Components\BaseFileUpload;

BaseFileUpload::make('video')
    ->disk('public')
    ->directory('videos')
    ->acceptedFileTypes(['video/*'])
    ->optimize('medium')  // 'low', 'medium', 'high'
    ->format('webm');     // 'webm' or 'mp4'
```

### Spatie Media Library Component

For Spatie Media Library integration:

```php
use Tonymans33\VideoOptimizer\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('videos')
    ->collection('videos')
    ->multiple()
    ->optimize('medium')  // 'low', 'medium', 'high'
    ->format('webm');     // 'webm' or 'mp4'
```

## Configuration

The `config/video-optimizer.php` file allows you to set default optimization settings:

```php
return [
    // Default optimization level: null, 'low', 'medium', 'high'
    'optimize' => null,

    // Default output format: null, 'webm', 'mp4'
    'format' => null,
];
```

### Optimization Levels

- **low**: CRF 36 - Smaller file size, lower quality
- **medium**: CRF 28 - Balanced quality and size (recommended)
- **high**: CRF 20 - Higher quality, larger file size

### Formats

- **webm**: Modern format with good compression (default)
- **mp4**: Universal compatibility using H.264 codec

## How It Works

1. When a video file is uploaded, the component detects it by MIME type
2. If optimization or format conversion is enabled, FFmpeg processes the video
3. The optimized video is saved to your configured disk
4. Original temporary files are cleaned up automatically

## Important Notes

- **FFmpeg Required**: Make sure FFmpeg is installed on your server
- **Direct Component Usage**: This package extends Filament's FileUpload components. You must use the components from this package directly (`Tonymans33\VideoOptimizer\Components\BaseFileUpload` or `Tonymans33\VideoOptimizer\Components\SpatieMediaLibraryFileUpload`)
- **Processing Time**: Video optimization takes time. Consider using queue jobs for large files
- **Disk Space**: Temporary files are created during processing

## Troubleshooting

### Videos not being optimized

1. Verify FFmpeg is installed: `ffmpeg -version`
2. Check Laravel logs for errors
3. Ensure the `local` disk is configured in `config/filesystems.php`
4. Make sure you're using the custom components from this package

### Errors during upload

Check the Laravel log file for detailed error messages. The package will fall back to uploading the original file if optimization fails.

## License

MIT License - see LICENSE.md for details
