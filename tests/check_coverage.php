<?php

declare(strict_types=1);

$coverageFile = $argv[1] ?? 'build/coverage.xml';
$globalMinimum = isset($argv[2]) ? (float) $argv[2] : 90.0;
$criticalMinimum = isset($argv[3]) ? (float) $argv[3] : 90.0;

if (!is_readable($coverageFile)) {
    fwrite(STDERR, "Coverage report is not readable: {$coverageFile}\n");
    exit(1);
}

$xml = simplexml_load_file($coverageFile);
if ($xml === false || !isset($xml->project, $xml->project->metrics)) {
    fwrite(STDERR, "Coverage report is not a valid Clover document.\n");
    exit(1);
}

$coverageMetrics = static function (SimpleXMLElement $metrics): array {
    return [
        (int) $metrics['statements'],
        (int) $metrics['coveredstatements'],
    ];
};

$coveragePercent = static function (int $statements, int $coveredStatements): float {
    return $statements > 0 ? ($coveredStatements / $statements) * 100 : 100.0;
};

[$globalStatements, $globalCovered] = $coverageMetrics($xml->project->metrics);

$criticalSuffixes = [
    '/src/Http/',
    '/src/Services/WebhookService.php',
    '/src/Support/InputValidator.php',
    '/src/Support/Rfc3339.php',
    '/src/Support/SensitiveData.php',
    '/src/Webhooks/',
];
$criticalStatements = 0;
$criticalCovered = 0;

foreach ($xml->project->file as $file) {
    $name = str_replace('\\', '/', (string) $file['name']);
    $isCritical = false;

    foreach ($criticalSuffixes as $suffix) {
        if (str_contains($name, $suffix)) {
            $isCritical = true;
            break;
        }
    }

    if (!$isCritical || !isset($file->metrics)) {
        continue;
    }

    [$statements, $covered] = $coverageMetrics($file->metrics);
    $criticalStatements += $statements;
    $criticalCovered += $covered;
}

$globalPercent = $coveragePercent($globalStatements, $globalCovered);
$criticalPercent = $coveragePercent($criticalStatements, $criticalCovered);

printf(
    "Coverage: global %.2f%% (%d/%d), critical %.2f%% (%d/%d).\n",
    $globalPercent,
    $globalCovered,
    $globalStatements,
    $criticalPercent,
    $criticalCovered,
    $criticalStatements
);

if ($globalPercent < $globalMinimum || $criticalPercent < $criticalMinimum) {
    fwrite(
        STDERR,
        sprintf(
            "Coverage gate failed: required global %.2f%% and critical %.2f%%.\n",
            $globalMinimum,
            $criticalMinimum
        )
    );
    exit(1);
}
