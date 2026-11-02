<?php

declare(strict_types=1);

if ($argc !== 3 || ! ctype_digit($argv[2]) || (int) $argv[2] > 100) {
    fwrite(STDERR, "Usage: php scripts/check-coverage.php <clover-report> <minimum-percent>\n");
    exit(2);
}

$reportPath = $argv[1];
if (! is_file($reportPath)) {
    fwrite(STDERR, "Coverage report not found: {$reportPath}\n");
    exit(2);
}

$report = simplexml_load_file($reportPath);
$metrics = $report === false ? null : $report->project->metrics;
if ($metrics === null) {
    fwrite(STDERR, "Invalid Clover coverage report: {$reportPath}\n");
    exit(2);
}

$statements = (int) $metrics['statements'];
$coveredStatements = (int) $metrics['coveredstatements'];
if ($statements === 0) {
    fwrite(STDERR, "Coverage report contains no executable statements.\n");
    exit(2);
}

$coverage = $coveredStatements / $statements * 100;
$minimum = (int) $argv[2];
printf("Line coverage: %.2f%% (minimum: %d%%)\n", $coverage, $minimum);

if ($coverage < $minimum) {
    exit(1);
}
