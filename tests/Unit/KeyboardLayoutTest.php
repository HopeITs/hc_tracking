<?php

use App\Support\KeyboardLayout;

test('reads text as typed on the other keyboard layout', function (string $typed, array $readings) {
    expect(KeyboardLayout::candidates($typed))->toBe($readings);
})->with([
    'Arabic layout, English meant' => ['حقخلقشةس', ['programs']],
    'lam-alef key is b' => ['لاعيلثف', ['budget']],
    'English layout, Arabic meant' => ['fvhl[', ['برامج']],
    'capitals still map' => ['FVHL[', ['برامج']],
    'mixed text converts word by word' => ['حقخلقشةس report', ['programs report', 'حقخلقشةس قثحخقف']],
]);

test('offers nothing for text with no letters', function (string $typed) {
    expect(KeyboardLayout::candidates($typed))->toBe([]);
})->with(['2026', '', '   ']);

test('searches under three letters are never read on the other layout', function () {
    expect(KeyboardLayout::readings('في'))->toBe(['في'])
        ->and(KeyboardLayout::readings('hd'))->toBe(['hd'])
        ->and(KeyboardLayout::readings('سؤا'))->toBe(['سؤا', 'sch']);
});

test('tashkeel and tatweel do not change how a search is read on the other layout', function () {
    expect(KeyboardLayout::readings('شَمَّه'))->toBe(KeyboardLayout::readings('شمه'))
        ->and(KeyboardLayout::readings('شمـــه'))->toBe(KeyboardLayout::readings('شمه'))
        ->and(KeyboardLayout::readings('فِي'))->toBe(['في']);
});
