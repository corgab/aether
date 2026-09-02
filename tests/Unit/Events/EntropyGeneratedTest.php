<?php

declare(strict_types=1);

use Aether\Events\EntropyGenerated;

it('exposes the driver and requested bit count', function () {
    $event = new EntropyGenerated(driver: 'aws', bits: 256);

    expect($event->driver)->toBe('aws')
        ->and($event->bits)->toBe(256);
});

it('does not expose any generated entropy value', function () {
    // EntropyGenerated is metadata-only by design: the raw bytes/bits must
    // never travel through the event system. Asserting the public property
    // list stays exactly [driver, bits] guards against a future change
    // accidentally reintroducing the value.
    $event = new EntropyGenerated(driver: 'local', bits: 8);

    $properties = array_keys(get_object_vars($event));
    sort($properties);

    expect($properties)->toBe(['bits', 'driver']);
});
