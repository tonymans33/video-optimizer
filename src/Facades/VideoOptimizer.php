<?php

namespace Tonymans33\VideoOptimizer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Tonymans33\VideoOptimizer\VideoOptimizer
 */
class VideoOptimizer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'video-optimizer';
    }
}