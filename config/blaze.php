<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Blaze
    |--------------------------------------------------------------------------
    |
    | When set to false, Blaze will skip all compilation and optimization.
    | Templates will be rendered using standard Blade without any Blaze
    | processing. This is useful for debugging or disabling Blaze in
    | specific environments.
    |
    | Disabled by default in hc-tracking: Blaze folds Flux components at
    | compile time, which bakes translated labels (e.g. "Toggle sidebar")
    | into the cached view in whichever language rendered first, so English
    | pages could show Arabic labels and the other way round.
    |
    */

    'enabled' => env('BLAZE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, Blaze will register the debugger middleware and output
    | additional diagnostic information about the compilation pipeline.
    |
    */

    'debug' => env('BLAZE_DEBUG', false),

];
