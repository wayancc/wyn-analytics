<?php
/**
 * Site dashboard.
 * @var WynAnalytics\View $v @var WynAnalytics\Report $report @var array $site @var string $metric
 * @var array $tabs @var array $panels @var array $query @var string $geo @var int $retention
 */
use WynAnalytics\Period;
use WynAnalytics\Report;
use WynAnalytics\Stats;

$base = 'admin/sites/' . $site['id'];
$period = $report->period;
$total = $report->totals['visitors'];

ob_start(); ?>
<nav class="wyn-range" aria-label="Date range">
  <?php foreach (Period::RANGES as $key => $label): ?>
  <a href="<?= $v->e($v->link($base, $query, ['range' => $key, 'from' => null, 'to' => null])) ?>"<?= $period->key === $key ? ' aria-current="page"' : '' ?>><?= str_replace('Last ', '<span class="wyn-hide-sm">Last </span>', $v->e($label)) ?></a>
  <?php endforeach ?>
  <details data-wyn-popover<?= $period->key === 'custom' ? ' data-active' : '' ?>>
    <summary>Custom</summary>
    <form class="wyn-custom" method="get" action="<?= $v->url($base) ?>">
      <?php foreach ($query as $k => $val): if (in_array($k, ['range', 'from', 'to'], true)) { continue; } ?>
      <input type="hidden" name="<?= $v->e($k) ?>" value="<?= $v->e($val) ?>">
      <?php endforeach ?>
      <input type="hidden" name="range" value="custom">
      <label class="wyn-field"><span>From</span><input type="date" name="from" value="<?= $v->e($period->from) ?>" required></label>
      <label class="wyn-field"><span>To</span><input type="date" name="to" value="<?= $v->e($period->to) ?>" required></label>
      <button class="wyn-btn wyn-btn-small" type="submit">Show</button>
    </form>
  </details>
</nav>
<?php $range = ob_get_clean();

$live = '<p class="wyn-live wyn-muted" data-wyn-live="' . $v->e($v->url($base . '/now.json')) . '" style="display:flex;align-items:center;margin:12px 0 0">'
    . '<span class="wyn-pip' . ($report->now > 0 ? ' is-live' : '') . '" aria-hidden="true"></span>'
    . '<span data-wyn-live-count aria-live="polite">' . ($report->now === 1 ? '1 visitor now' : number_format($report->now) . ' visitors now') . '</span>'
    . '<span aria-hidden="true">&nbsp;·&nbsp;</span>' . $v->e($period->label) . '</p>';

$v->layout('layout/admin', [
    'heading'     => $site['name'],
    'crumb'       => '<a href="' . $v->url('admin') . '">Sites</a> / ' . $v->e($site['domain']),
    'heroExtra'   => $live,
    'heroActions' => $range,
    'tabsFor'     => $site,
]);
?>

<?php if ($site['first_event_at'] === null): ?>
<section class="wyn-empty">
  <h2>Waiting for the first visit<span class="wyn-dot">.</span></h2>
  <p>Add the script to <?= $v->e($site['domain']) ?>, then open the site in a browser. This page fills in as soon as the first pageview arrives.</p>
  <a class="wyn-btn" href="<?= $v->url($base . '/setup') ?>">Get the script</a>
</section>
<?php else: ?>

<?php if ($report->filters !== []): ?>
<div class="wyn-filters" aria-label="Active filters">
  <span class="wyn-label wyn-muted">Showing visits where</span>
  <?php foreach ($report->filters as $dim => $value):
      $text = $dim === 'goal'
          ? (function () use ($report, $value) { foreach ($report->goals as $g) { if ((string) $g['goal']['id'] === (string) $value) { return $g['label']; } } return 'goal'; })()
          : $v->dimLabel($dim, (string) $value);
  ?>
  <a class="wyn-chip" href="<?= $v->e($v->link($base, $query, ['f_' . $dim => null])) ?>">
    <span><?= $v->e(Stats::FILTERS[$dim]) ?> is <b><?= $v->e($text) ?></b></span>
    <span aria-hidden="true">×</span><span class="wyn-sr">Remove filter</span>
  </a>
  <?php endforeach ?>
  <a class="wyn-small" href="<?= $v->e($v->link($base, array_filter($query, fn ($k) => !str_starts_with($k, 'f_'), ARRAY_FILTER_USE_KEY))) ?>">Clear all</a>
</div>
<?php if ($report->partial): ?>
<p class="wyn-note">Filters use detailed visit data, which is kept for <?= $retention ?> days. Older days in this range aren’t included.</p>
<?php endif ?>
<?php endif ?>

<?= $v->partial('partials/kpis', ['report' => $report, 'metric' => $metric, 'base' => $base, 'query' => $query]) ?>
<?= $v->partial('partials/chart', ['report' => $report, 'metric' => $metric]) ?>

<div class="wyn-panels">
  <?php foreach ($panels as $panel => [$title, $options]):
      $dim = $tabs[$panel];
      $rows = $report->breakdowns[$dim] ?? [];
  ?>
  <section class="wyn-panel" id="<?= $panel ?>" aria-labelledby="<?= $panel ?>-h">
    <div class="wyn-panel-head">
      <h2 id="<?= $panel ?>-h"><?= $v->e($title) ?><span class="wyn-dot">.</span></h2>
      <?php if (count($options) > 1): ?>
      <nav class="wyn-ptabs wyn-small" aria-label="<?= $v->e($title) ?> view">
        <?php foreach ($options as $key => $label): ?>
        <a href="<?= $v->e($v->link($base, $query, [$panel => $key], $panel)) ?>"<?= $dim === $key ? ' aria-current="true"' : '' ?>><?= $v->e($label) ?></a>
        <?php endforeach ?>
      </nav>
      <?php endif ?>
    </div>
    <?= $v->partial('partials/rows', ['dim' => $dim, 'rows' => $rows, 'total' => $total, 'base' => $base, 'query' => $query,
        'columnLabel' => Report::DIMS[$dim] ?? $title]) ?>
    <div class="wyn-panel-foot">
      <span><?= $panel === 'places' && $geo !== '' ? 'IP geolocation by <a href="https://db-ip.com">DB-IP</a>' : '' ?></span>
      <a href="<?= $v->e($v->link($base . '/export.csv', array_diff_key($query, ['metric' => 1] + $tabs), ['table' => $dim])) ?>">Download CSV</a>
    </div>
  </section>
  <?php endforeach ?>

  <section class="wyn-panel wyn-panel-wide" id="goals" aria-labelledby="goals-h">
    <div class="wyn-panel-head">
      <h2 id="goals-h">Goals<span class="wyn-dot">.</span></h2>
      <a class="wyn-small" href="<?= $v->url($base . '/settings#goals') ?>">Manage goals</a>
    </div>
    <?php if ($report->goals === []): ?>
      <p class="wyn-none">No goals yet. Add one in <a href="<?= $v->url($base . '/settings#goals') ?>">settings</a>, like “visited /thanks” or the event “Signup”, to see how many visitors convert.</p>
    <?php else: ?>
    <div class="wyn-table-wrap" style="max-height:none;border:0">
      <table class="wyn-goals">
        <thead><tr><th scope="col">Goal</th><th scope="col">Converted visitors</th><th scope="col">Completions</th><th scope="col">Conversion rate</th></tr></thead>
        <tbody>
          <?php foreach ($report->goals as $g): ?>
          <tr>
            <td><a href="<?= $v->e($v->link($base, $query, ['f_goal' => $g['goal']['id']])) ?>"><?= $v->e($g['label']) ?></a><?= $g['approx'] ? ' <span class="wyn-muted wyn-small">(estimate)</span>' : '' ?></td>
            <td><?= $v->e($v->compact($g['visitors'])) ?></td>
            <td><?= $v->e($v->compact($g['completions'])) ?></td>
            <td><strong><?= number_format($g['rate'], 1) ?>%</strong></td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>
  </section>

  <section class="wyn-panel wyn-panel-wide" id="events" aria-labelledby="events-h">
    <div class="wyn-panel-head">
      <h2 id="events-h">Events<span class="wyn-dot">.</span></h2>
      <a class="wyn-small" href="<?= $v->url($base . '/setup#events') ?>">How to track events</a>
    </div>
    <?php if (($report->breakdowns['event'] ?? []) === []): ?>
      <p class="wyn-none">No events in this period. Add <code>data-wyn-event="Signup"</code> to a button, or turn on outbound link and download tracking.</p>
    <?php else: ?>
      <?= $v->partial('partials/rows', ['dim' => 'event', 'rows' => $report->breakdowns['event'], 'total' => $total, 'base' => $base, 'query' => $query, 'columnLabel' => 'Event']) ?>
      <?php foreach ($report->props as $event => $keys): ?>
      <details class="wyn-props">
        <summary><?= $v->e($event) ?>: details</summary>
        <?php foreach ($keys as $key => $values): ?>
          <p class="wyn-label wyn-muted" style="margin:10px 0 4px"><?= $v->e($key) ?></p>
          <?= $v->partial('partials/rows', ['dim' => 'prop', 'rows' => $values, 'total' => $total, 'base' => null, 'query' => $query, 'columnLabel' => $key]) ?>
        <?php endforeach ?>
      </details>
      <?php endforeach ?>
      <div class="wyn-panel-foot"><span></span><a href="<?= $v->e($v->link($base . '/export.csv', array_diff_key($query, ['metric' => 1] + $tabs), ['table' => 'event'])) ?>">Download CSV</a></div>
    <?php endif ?>
  </section>
</div>

<p class="wyn-foot">
  Times are in <?= $v->e($site['timezone']) ?>. A visitor who returns on another day counts again: visitor IDs change daily, so nobody can be followed over time.
  <a href="<?= $v->e($v->link($base . '/export.csv', array_diff_key($query, ['metric' => 1] + $tabs), ['table' => 'visitors'])) ?>">Download daily CSV</a>
</p>
<?php endif ?>
