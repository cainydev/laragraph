<?php

namespace Cainy\Laragraph\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Cainy\Laragraph\Laragraph
 */
class Laragraph extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Cainy\Laragraph\Laragraph::class;
    }
}
