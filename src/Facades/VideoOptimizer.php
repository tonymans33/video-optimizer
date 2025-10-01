<?php

namespace Tonymans33\VideoOptimizer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Tonymans33\VideoOptimizer\Components\VideoOptimizer make(string $name)
 *
 * @see \Tonymans33\VideoOptimizer\Components\VideoOptimizer
 */
class VideoOptimizer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Tonymans33\VideoOptimizer\Components\VideoOptimizer::class;
    }
}