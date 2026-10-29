<?php
/**
 * Public stats page: big type, read-only.
 * @var WynAnalytics\View $v @var WynAnalytics\Report $report @var array $site
 */
use WynAnalytics\Period;

$v->layout('layout/public', ['title' => $title, 'bodyClass' => 'wyn-public-stats', 'withScript' => true]);
$period = $report->period;
$visitors = $report->totals['visitors'];
$base = 'stats/' . rawurlencode($site['domain']);
$headline = rtrim($site['domain'], '.');
$panels = [
    'page'    => ['Top pages', 'Page'],
    'source'  => ['Sources', 'Source'],
    'country' => ['Countries', 'Country'],
    'device'  => ['Devices', 'Device'],
];
?>
<section class="wyn-show">
  <div class="wyn-show-in">
    <h1 class="wyn-display"><?= $v->e($headline) ?><span class="wyn-dot">.</span></h1>
    <p class="wyn-outline"><?= $v->e($v->compact($visitors)) ?> <?= $visitors === 1 ? 'visitor' : 'visitors' ?> · <?= $v->e($period->label) ?></p>
    <div class="wyn-show-meta">
      <p><span class="wyn-pip<?= $report->now > 0 ? ' is-live' : '' ?>" aria-hidden="true"></span><?= $report->now === 1 ? '1 visitor' : number_format($report->now) . ' visitors' ?> on the site right now</p>
      <nav class="wyn-range" aria-label="Date range">
        <?php foreach (Period::RANGES as $key => $label): ?>
        <a href="<?= $v->e($v->link($base, [], ['range' => $key === '30d' ? null : $key])) ?>"<?= $period->key === $key ? ' aria-current="page"' : '' ?>><?= str_replace('Last ', '<span class="wyn-hide-sm">Last </span>', $v->e($label)) ?></a>
        <?php endforeach ?>
      </nav>
    </div>
  </div>
</section>

<div class="wyn-wrap">
  <?= $v->partial('partials/kpis', ['report' => $report, 'metric' => 'visitors', 'base' => $base, 'query' => [], 'linked' => false]) ?>
  <?= $v->partial('partials/chart', ['report' => $report, 'metric' => 'visitors']) ?>

  <div class="wyn-panels">
    <?php foreach ($panels as $dim => [$heading, $column]): ?>
    <section class="wyn-panel" aria-labelledby="p-<?= $dim ?>">
      <div class="wyn-panel-head"><h2 id="p-<?= $dim ?>"><?= $v->e($heading) ?><span class="wyn-dot">.</span></h2></div>
      <?= $v->partial('partials/rows', ['dim' => $dim, 'rows' => $report->breakdowns[$dim] ?? [], 'total' => $visitors, 'base' => null, 'query' => [], 'columnLabel' => $column]) ?>
    </section>
    <?php endforeach ?>
  </div>
  <p class="wyn-foot">Times in <?= $v->e($site['timezone']) ?>. Counted without cookies: visitor IDs change every day and IP addresses are never stored.</p>
</div>
