<?php
/**
 * KPI row. Vars: report, metric (selected), base (path), query, linked (bool: cards switch the chart).
 * @var WynAnalytics\Report $report
 */
$linked ??= true;
?>
<ul class="wyn-kpis">
  <?php foreach (WynAnalytics\Report::METRICS as $key => $label):
      $value = $report->totals[$key];
      $change = $report->change($key);
      $tag = $linked ? 'a' : 'div';
      $points = $key === 'bounce_rate';
  ?>
  <li><<?= $tag ?> class="wyn-kpi"<?= $linked ? ' href="' . $v->e($v->link($base, $query, ['metric' => $key])) . '"' : '' ?><?= $linked && $metric === $key ? ' aria-current="true"' : '' ?>>
    <span class="wyn-label"><?= $v->e($label) ?></span>
    <span class="wyn-kpi-value"><?= $v->e($v->metric($key, $value)) ?></span>
    <?php if ($change === null): ?>
      <span class="wyn-change">No earlier data</span>
    <?php else:
        $rounded = round($change);
        $good = WynAnalytics\Report::isGood($key, $change);
        $text = $rounded == 0 ? 'No change' : abs($rounded) . ($points ? (abs($rounded) == 1 ? ' pt' : ' pts') : '%');
        $word = $rounded == 0 ? 'unchanged' : ($change > 0 ? 'up ' : 'down ') . abs($rounded) . ($points ? (abs($rounded) == 1 ? ' point' : ' points') : ' percent');
    ?>
      <span class="wyn-change<?= $rounded == 0 ? '' : ($good ? ' is-good' : ' is-bad') ?>">
        <span aria-hidden="true"><?= $rounded == 0 ? '' : ($change > 0 ? '▲ ' : '▼ ') ?><?= $v->e($text) ?></span>
        <span class="wyn-sr"><?= $v->e($word) ?> compared with <?= $v->e($report->period->previousLabel()) ?></span>
      </span>
    <?php endif ?>
  </<?= $tag ?>></li>
  <?php endforeach ?>
</ul>
