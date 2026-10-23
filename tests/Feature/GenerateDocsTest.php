<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('writes the permission matrix and route inventory from the code', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'formezy-docs-'.bin2hex(random_bytes(4));

    try {
        expect(Artisan::call('formezy:docs', ['--path' => $directory]))->toBe(0);

        $matrix = File::get($directory.'/permission-matrix.md');
        expect($matrix)
            ->toContain('| Form | Group |')
            ->toContain('Item master (`item`)')
            ->toContain('Assign / transfer')
            ->toContain('`approval.override`')
            ->toContain('### Operations User')
            ->toContain('- `item`: view, comment');

        $routes = File::get($directory.'/route-inventory.md');
        expect($routes)
            ->toContain('`/admin/users`')
            ->toContain('`admin.users.index`')
            ->toContain('`login`')
            ->not->toContain('livewire-');
    } finally {
        File::deleteDirectory($directory);
    }
});
