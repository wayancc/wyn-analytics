<?php
/**
 * Main chart with a text summary and a table view for screen readers and keyboard users.
 * Vars: report, metric.
 * @var WynAnalytics\Report $report
 */
use WynAnalytics\Charts;
use WynAnalytics\Report;

$label = Report::METRICS[$metric];
$values = [];
foreach ($report->series as $k => $m) {
    $values[$k] = isset($report->future[$k]) ? null : Report::bucketValue($m, $metric);
}
$title = $label . ', ' . $report->period->label;

// Plain-language summary: total, change and the busiest bucket.
$best = null;
foreach ($values as $k => $val) {
    if ($val !== null && ($best === null || $val > $values[$best])) {
        $best = $k;
    }
}
$change = $report->change($metric);
$summary = $label . ' for ' . strtolower($report->period->label[0]) . substr($report->period->label, 1) . ': ' . $v->metric($metric, $report->totals[$metric]);
if ($change !== null && round($change) != 0) {
    $summary .= ', ' . ($change > 0 ? 'up ' : 'down ') . abs(round($change)) . ($metric === 'bounce_rate' ? ' points' : '%') . ' on ' . $report->period->previousLabel();
}
$summary .= '.';
if ($best !== null && $values[$best] > 0 && count($values) > 1) {
    $summary .= ' ' . ($metric === 'bounce_rate' ? 'Highest' : 'Busiest') . ': ' . $report->labels[$best] . ' (' . $v->metric($metric, $values[$best]) . ').';
}
?>
<div class="wyn-chart-box">
  <div class="wyn-chart-wide"><?= Charts::line($values, $report->labels, $metric, $title, 'wyn-chart-w') ?></div>
  <div class="wyn-chart-narrow"><?= Charts::line($values, $report->labels, $metric, $title, 'wyn-chart-n', 420, 230) ?></div>
</div>
<p class="wyn-summary"><?= $v->e($summary) ?></p>
<details class="wyn-more">
  <summary>Show as a table</summary>
  <div class="wyn-table-wrap">
    <table class="wyn-table">
      <caption class="wyn-sr"><?= $v->e($title) ?></caption>
      <thead><tr><th scope="col"><?= $report->period->interval === 'hour' ? 'Hour' : ($report->period->interval === 'month' ? 'Month' : 'Day') ?></th>
        <?php foreach (Report::METRICS as $mk => $ml): ?><th scope="col"><?= $v->e($ml) ?></th><?php endforeach ?></tr></thead>
      <tbody>
        <?php foreach ($report->series as $k => $m): if (isset($report->future[$k])) { continue; } ?>
        <tr><th scope="row"><?= $v->e($report->labels[$k]) ?></th>
          <?php foreach (array_keys(Report::METRICS) as $mk): ?><td><?= $v->e($v->metric($mk, Report::bucketValue($m, $mk))) ?></td><?php endforeach ?></tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
</details>
