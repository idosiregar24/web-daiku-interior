<?php

use App\Support\Phone;

test('normalize turns any common Indonesian mobile form into 08 digits', function (string $input, string $expected) {
    expect(Phone::normalize($input))->toBe($expected);
})->with([
    ['0812-3456-7890', '081234567890'],
    ['+62 812 3456 7890', '081234567890'],
    ['62812.3456.7890', '081234567890'],
    ['812 3456 7890', '081234567890'],
    [' 081234567890 ', '081234567890'],
]);

test('normalize leaves non-numeric input for validation to reject', function () {
    expect(Phone::normalize('@budi.interior'))->toBe('@budi.interior')
        ->and(Phone::normalize('0812abc'))->toBe('0812abc')
        ->and(Phone::normalize('   '))->toBeNull();
});

test('isValid accepts only 08 followed by 8–11 digits', function () {
    expect(Phone::isValid('0812345678'))->toBeTrue()
        ->and(Phone::isValid('0812345678901'))->toBeTrue()
        ->and(Phone::isValid('081234567'))->toBeFalse()
        ->and(Phone::isValid('08123456789012'))->toBeFalse()
        ->and(Phone::isValid('0761123456'))->toBeFalse()
        ->and(Phone::isValid(null))->toBeFalse();
});

test('format and whatsapp derive from the stored digits', function () {
    expect(Phone::format('081234567890'))->toBe('0812-3456-7890')
        ->and(Phone::whatsapp('081234567890'))->toBe('6281234567890')
        ->and(Phone::whatsapp('budi@gmail.com'))->toBeNull();
});
