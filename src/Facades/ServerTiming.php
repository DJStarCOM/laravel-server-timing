<?php

namespace DJStarCOM\ServerTiming\Facades;

use Illuminate\Support\Facades\Facade;

class ServerTiming extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \DJStarCOM\ServerTiming\ServerTiming::class;
    }
}
