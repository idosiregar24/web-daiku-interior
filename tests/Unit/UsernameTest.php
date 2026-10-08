<?php

use App\Support\Username;

test('normalize lower-cases and trims, empty becomes null', function () {
    expect(Username::normalize(' Budi '))->toBe('budi')
        ->and(Username::normalize('BUDI2'))->toBe('budi2')
        ->and(Username::normalize('   '))->toBeNull()
        ->and(Username::normalize(null))->toBeNull();
});

test('valid usernames are lowercase letters and digits starting with a letter', function (string $value) {
    expect(Username::isValid($value))->toBeTrue();
})->with(['budi', 'budi2', 'budisantoso', 'abc', str_repeat('a', 30)]);

test('invalid usernames are rejected', function (string $value) {
    expect(Username::isValid($value))->toBeFalse();
})->with(['bu', '1budi', 'budi.s', 'budi_s', 'budi s', 'budi-s', 'Budi', 'budi@', str_repeat('a', 31), '']);

test('reserved names are recognised whatever their case', function () {
    expect(Username::isReserved('admin'))->toBeTrue()
        ->and(Username::isReserved('SuperAdmin'))->toBeTrue()
        ->and(Username::isReserved('fieldstaff'))->toBeTrue()
        ->and(Username::isReserved('budi'))->toBeFalse();
});
