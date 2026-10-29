<?php
/**
 * Breakdown list with proportional bars. Vars: dim, rows, total (visitors in period),
 * base + query (when set, values link to a filter), metricLabel.
 */
$max = max(1, ...array_values(array_map(fn ($m) => $m["visitors"], $rows ?: [["visitors" => 1]])));
$filterable = isset($base) && $base !== null && !in_array($dim, ['prop'], true);
$filterKey = 'f_' . $dim;
?>
<?php if ($rows === []): ?>
  <p class="wyn-none">Nothing in this period yet.</p>
<?php else: ?>
  <div class="wyn-rows-head wyn-label"><span><?= $v->e($columnLabel ?? 'Name') ?></span><span>Visitors</span></div>
  <ol class="wyn-rows">
    <?php foreach ($rows as $value => $m):
        $value = (string) $value;
        $text = $v->dimLabel($dim, $value);
        $share = $total > 0 ? round($m['visitors'] / $total * 100) : 0;
        $flag = $dim === 'country' ? WynAnalytics\Geo::flag($value) : '';
    ?>
    <li class="wyn-row" style="--w: <?= round($m['visitors'] / $max * 100, 1) ?>%">
      <?php if ($filterable && $value !== '(other)'): ?>
        <a href="<?= $v->e($v->link($base, $query, [$filterKey => $value])) ?>" title="<?= $v->e($text) ?>"><?php if ($flag): ?><span class="wyn-flag" aria-hidden="true"><?= $flag ?></span><?php endif ?><?= $v->e($text) ?></a>
      <?php else: ?>
        <span class="wyn-v" title="<?= $v->e($text) ?>"><?php if ($flag): ?><span class="wyn-flag" aria-hidden="true"><?= $flag ?></span><?php endif ?><?= $v->e($text) ?></span>
      <?php endif ?>
      <span class="wyn-p"><?= $share ?>%</span>
      <span class="wyn-n"><?= $v->e($v->compact($m['visitors'])) ?></span>
    </li>
    <?php endforeach ?>
  </ol>
<?php endif ?>
