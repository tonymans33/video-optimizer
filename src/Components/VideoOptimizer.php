<?php

namespace Tonymans33\VideoOptimizer\Components;

class VideoOptimizer extends BaseFileUpload
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set video-specific accepted file types by default
        $this->acceptedFileTypes([
            'video/mp4',
            'video/webm',
            'video/ogg',
            'video/quicktime',
            'video/x-msvideo',
            'video/x-matroska',
        ]);
    }
}
