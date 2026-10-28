<?php

use Illuminate\Support\Facades\Blade;

/*
 * <x-filters> (resources/views/components/filters.blade.php): the filters of a list, behind one button on a phone. What the browser does with
 * it (hidden until pressed, kept open while one is used, no button from tablet width) is checked by tests/Browser/run.mjs.
 */

function filtersHtml(int $active): string
{
    return Blade::render('<x-filters :active="$n" id="t-filters"><x-slot:primary><input id="the-search"></x-slot:primary><select id="the-owner"></select></x-filters>', ['n' => $active]);
}

it('keeps the primary field outside the panel and puts every other field inside it', function () {
    $html = filtersHtml(0);

    $panelAt = strpos($html, 'id="t-filters"');
    expect($panelAt)->not->toBeFalse()
        ->and(strpos($html, 'id="the-search"'))->toBeLessThan($panelAt)    // always on the screen
        ->and(strpos($html, 'id="the-owner"'))->toBeGreaterThan($panelAt); // behind the button on a phone
});

it('names the panel on its button and says nothing about a count when no filter is set', function () {
    $html = filtersHtml(0);

    expect($html)->toContain('aria-controls="t-filters"')->toContain('sm:hidden')   // the button exists only below tablet width
        ->and($html)->not->toContain('badge');
});

it('shows how many filters are set', function () {
    expect(filtersHtml(3))->toMatch('/<span class="badge[^"]*">\s*3\s*<\/span>/');
});

it('is hidden on a phone until opened, so a page never flashes the whole panel before the script starts', function () {
    $html = filtersHtml(0);

    // hidden by its own class (not by script), shown by "open" on a phone, laid out in line from tablet width
    expect($html)->toMatch('/id="t-filters"[^>]*class="hidden [^"]*sm:contents"/')->toContain("'max-sm:grid': open");
});
