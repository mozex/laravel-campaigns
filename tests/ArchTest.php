<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'ddd', 'dump', 'ray', 'die', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('it uses strict types')
    ->expect('Mozex\Campaigns')
    ->toUseStrictTypes();

arch('it asks Laravel\'s gate nothing, so an app\'s gate hooks grant no rights')
    ->expect('Mozex\Campaigns')
    ->not->toUse([
        'Illuminate\Support\Facades\Gate',
        'Illuminate\Contracts\Auth\Access',
        'Illuminate\Auth\Access',
        'Illuminate\Foundation\Auth\Access',
        'Illuminate\Auth\Middleware\Authorize',
    ]);
