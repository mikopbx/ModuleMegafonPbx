<?php

declare(strict_types=1);

$helperFile = dirname(__DIR__) . '/Lib/CdrTimeline.php';
if (!is_file($helperFile)) {
    fwrite(STDERR, "FAIL: CdrTimeline helper is missing\n");
    exit(1);
}

require $helperFile;

use Modules\ModuleMegafonPbx\Lib\CdrTimeline;

$cases = [
    'answered call does not add wait twice' => [
        'start' => '2026-08-13 11:34:39.000000',
        'wait' => 37,
        'duration' => 315,
        'expected' => [
            'start' => '2026-08-13 11:34:39.000000',
            'answer' => '2026-08-13 11:35:16.000000',
            'endtime' => '2026-08-13 11:39:54.000000',
        ],
    ],
    'unanswered call ends after its total duration' => [
        'start' => '2026-08-13 11:37:52.000000',
        'wait' => 50,
        'duration' => 50,
        'expected' => [
            'start' => '2026-08-13 11:37:52.000000',
            'answer' => '2026-08-13 11:38:42.000000',
            'endtime' => '2026-08-13 11:38:42.000000',
        ],
    ],
];

foreach ($cases as $name => $case) {
    $actual = CdrTimeline::fromStart(
        new DateTime($case['start']),
        $case['wait'],
        $case['duration']
    );
    if ($actual !== $case['expected']) {
        fwrite(STDERR, 'FAIL: ' . $name . ' ' . json_encode([
            'expected' => $case['expected'],
            'actual' => $actual,
        ]) . PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, "PASS: CDR timeline does not count wait twice\n");
