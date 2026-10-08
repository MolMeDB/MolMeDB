<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Documentation articles kept in the repository
    |--------------------------------------------------------------------------
    |
    | Markdown files (Blade templates) published to the documentation pages
    | by `php artisan docs:sync`, and where their source can be read online,
    | linked from the administration.
    |
    */

    'directory' => resource_path('docs'),

    'source_url' => env('DOCUMENTATION_SOURCE_URL', 'https://github.com/MolMeDB/MolMeDB/blob/main/resources/docs'),

];
