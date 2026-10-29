<?php
$total = array_sum(array_map(fn ($r) => $r['week']['visitors'], $rows));
$v->layout('layout/admin', [
    'heading'     => 'Sites',
    'outline'     => $rows === [] ? 'Ready when you are' : $v->compact($total) . ' ' . ($total === 1 ? 'visitor' : 'visitors') . ' in 7 days',
    'heroActions' => '<a class="wyn-btn" href="' . $v->url('admin/sites/new') . '">Add a site</a>',
]);
?>
<?php if ($rows === []): ?>
<section class="wyn-empty">
  <h2>No sites yet<span class="wyn-dot">.</span></h2>
  <p>Add a website, paste one script tag into its HTML, and visits start showing up here. No cookies and no banner needed.</p>
  <a class="wyn-btn" href="<?= $v->url('admin/sites/new') ?>">Add your first site</a>
</section>
<?php else: ?>
<ul class="wyn-list">
  <?php foreach ($rows as $row): $s = $row['site']; $c = $row['change']; ?>
  <li>
    <a class="wyn-site-row" href="<?= $v->url('admin/sites/' . $s['id']) ?>">
      <div>
        <h2><?= $v->e($s['name']) ?></h2>
        <small>
          <?php if ($s['first_event_at'] === null): ?>
            <span class="wyn-pip" aria-hidden="true"></span>Waiting for the first visit · <?= $v->e($s['domain']) ?>
          <?php else: ?>
            <span class="wyn-pip<?= $row['now'] > 0 ? ' is-live' : '' ?>" aria-hidden="true"></span><?= $row['now'] ?> now · <?= $v->e($s['domain']) ?>
          <?php endif ?>
        </small>
      </div>
      <div class="wyn-spark-cell"><?= WynAnalytics\Charts::sparkline($row['spark']) ?></div>
      <div class="wyn-stat">
        <strong><?= $v->compact($row['week']['visitors']) ?></strong>
        <span>visitors, 7 days</span>
      </div>
      <div class="wyn-stat">
        <?php if ($c === null): ?>
          <strong class="wyn-muted">—</strong><span>new</span>
        <?php else: ?>
          <strong class="wyn-change <?= round($c) > 0 ? 'is-good' : (round($c) < 0 ? 'is-bad' : '') ?>"><?= round($c) > 0 ? '▲ ' : (round($c) < 0 ? '▼ ' : '') ?><?= abs(round($c)) ?>%</strong>
          <span><?= round($c) > 0 ? 'up' : (round($c) < 0 ? 'down' : 'same as') ?> vs last week</span>
        <?php endif ?>
      </div>
    </a>
  </li>
  <?php endforeach ?>
</ul>
<?php endif ?>
