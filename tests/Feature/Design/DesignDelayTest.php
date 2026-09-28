<?php

use App\Enums\DesignStatus;
use App\Jobs\DesignDelayJob;
use App\Models\Design;
use App\Services\DesignService;
use Illuminate\Support\Carbon;

function runDelayOn(string $date): void
{
    app(DesignService::class)->recalculateDelays(Carbon::parse($date));
}

test('delay_hari counts days past the deadline and is idempotent on re-run', function () {
    $design = Design::factory()->create([
        'status' => DesignStatus::Desain->value,
        'deadline' => '2026-09-10',
    ]);

    runDelayOn('2026-09-13');
    runDelayOn('2026-09-13');

    expect($design->fresh()->delay_hari)->toBe(3);

    runDelayOn('2026-09-15');

    expect($design->fresh()->delay_hari)->toBe(5);
});

test('designs before their deadline or without one have no delay', function () {
    $onTime = Design::factory()->create(['status' => DesignStatus::Desain->value, 'deadline' => '2026-09-20']);
    $noDeadline = Design::factory()->create(['status' => DesignStatus::Desain->value, 'deadline' => null]);

    runDelayOn('2026-09-20');

    expect($onTime->fresh()->delay_hari)->toBe(0)
        ->and($noDeadline->fresh()->delay_hari)->toBe(0);
});

test('HOLD_CLIENT and REVISI_CLIENT days are not counted as delay', function (DesignStatus $suspended) {
    $design = Design::factory()->create([
        'status' => DesignStatus::Desain->value,
        'deadline' => '2026-09-10',
    ]);

    runDelayOn('2026-09-12'); // 2 days delay
    $design->update(['status' => $suspended->value]);
    runDelayOn('2026-09-20'); // 8 suspended days — not counted
    $design->update(['status' => DesignStatus::Desain->value]);
    runDelayOn('2026-09-21'); // 1 more day

    expect($design->fresh()->delay_hari)->toBe(3);
})->with([DesignStatus::HoldClient, DesignStatus::RevisiClient]);

test('DONE_PRODUKSI freezes the final delay', function () {
    $design = Design::factory()->create([
        'status' => DesignStatus::Desain->value,
        'deadline' => '2026-09-10',
    ]);

    runDelayOn('2026-09-12');
    $design->update(['status' => DesignStatus::DoneProduksi->value]);
    runDelayOn('2026-09-30');

    expect($design->fresh()->delay_hari)->toBe(2);
});

test('extending the deadline into the future resets the delay', function () {
    $design = Design::factory()->create([
        'status' => DesignStatus::Desain->value,
        'deadline' => '2026-09-10',
    ]);

    runDelayOn('2026-09-12');
    $design->update(['deadline' => '2026-09-25']);
    runDelayOn('2026-09-13');

    expect($design->fresh())
        ->delay_hari->toBe(0)
        ->delay_counted_on->toBeNull();
});

test('DesignDelayJob delegates to the service', function () {
    $design = Design::factory()->create([
        'status' => DesignStatus::Desain->value,
        'deadline' => Carbon::today()->subDays(4)->toDateString(),
    ]);

    (new DesignDelayJob)->handle(app(DesignService::class));

    expect($design->fresh()->delay_hari)->toBe(4);
});
