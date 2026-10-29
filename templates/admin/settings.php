<?php
/** @var WynAnalytics\View $v @var array $s @var string $cronUrl @var int $lastRun @var string $geo */
$v->layout('layout/admin', ['heading' => 'Settings']);
?>
<form method="post" action="<?= $v->url('admin/settings') ?>" class="wyn-form">
  <?= $v->csrf() ?>
  <section class="wyn-section">
    <h2>Dashboard</h2>
    <div class="wyn-two">
      <label class="wyn-field"><span>Name</span><input name="site_name" value="<?= $v->e($s['site_name']) ?>" maxlength="60"></label>
      <label class="wyn-field"><span>Keep detailed visits for</span>
        <select name="retention_days"><?php foreach ([7, 30, 90, 180, 365] as $d): ?><option value="<?= $d ?>"<?= (int) $s['retention_days'] === $d ? ' selected' : '' ?>><?= $d ?> days</option><?php endforeach ?></select>
        <small>Daily totals and top lists are kept forever. Detailed visits power filters and are deleted after this.</small>
      </label>
    </div>
  </section>

  <section class="wyn-section" id="theme">
    <h2>Look</h2>
    <p>Applies to the dashboard and to public stats pages.</p>
    <div class="wyn-two">
      <label class="wyn-check">
        <input type="radio" name="theme" value="wayan"<?= $s['theme'] !== 'neutral' ? ' checked' : '' ?>>
        <span>Bold<small>Black and white, heavy uppercase headlines, square corners.</small></span>
      </label>
      <label class="wyn-check">
        <input type="radio" name="theme" value="neutral"<?= $s['theme'] === 'neutral' ? ' checked' : '' ?>>
        <span>Neutral<small>Quieter type and rounded corners. Fits alongside any brand.</small></span>
      </label>
    </div>
  </section>
  <div class="wyn-submit"><button class="wyn-btn" type="submit">Save settings</button></div>
</form>

<div class="wyn-form">
  <section class="wyn-section" id="rollups">
    <h2>Daily rollups</h2>
    <p>Each finished day is summed up once, and old detailed visits are removed. The dashboard also does this when you open it, so a scheduled job is optional, but it keeps the dashboard fast.</p>
    <p class="wyn-small"><strong>Last run:</strong> <?= $lastRun ? $v->e($v->ago($lastRun)) : 'never (the dashboard has been catching up on its own)' ?></p>
    <p><strong>Cron</strong> (every hour):</p>
    <div class="wyn-code"><button class="wyn-copy" type="button" data-wyn-copy-next>Copy</button><pre><code>0 * * * * php <?= $v->e(WYN_ROOT) ?>/bin/wyn-analytics run</code></pre></div>
    <p><strong>Web cron</strong>, for hosts without cron (any free cron service can call it hourly):</p>
    <div class="wyn-code"><button class="wyn-copy" type="button" data-wyn-copy-next>Copy</button><pre><code><?= $v->e($cronUrl) ?></code></pre></div>
    <form method="post" action="<?= $v->url('admin/settings') ?>" data-wyn-confirm="The current web-cron URL will stop working. Continue?">
      <?= $v->csrf() ?><input type="hidden" name="regenerate_cron" value="1">
      <button class="wyn-btn wyn-btn-small wyn-btn-line" type="submit">New web-cron URL</button>
    </form>
  </section>

  <section class="wyn-section" id="geo">
    <h2>Countries</h2>
    <?php if ($geo !== ''): ?>
      <p>Using <?= $v->e($geo) ?>. IP geolocation by <a href="https://db-ip.com">DB-IP</a> (CC BY 4.0). Only the country is kept, never the address.</p>
    <?php else: ?>
      <p>Behind Cloudflare with <code>trust_proxy</code> on, countries come from Cloudflare. Otherwise download the free country database once (a few MB) and refresh it monthly:</p>
    <?php endif ?>
    <div class="wyn-code"><button class="wyn-copy" type="button" data-wyn-copy-next>Copy</button><pre><code>php <?= $v->e(WYN_ROOT) ?>/bin/wyn-analytics geo:update</code></pre></div>
  </section>
</div>

<form method="post" action="<?= $v->url('admin/settings/password') ?>" class="wyn-form" id="account">
  <?= $v->csrf() ?>
  <section class="wyn-section">
    <h2>Your account</h2>
    <p>Signed in as <?= $v->e($user['email'] ?? '') ?></p>
    <div class="wyn-two">
      <label class="wyn-field"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>
      <label class="wyn-field"><span>New password</span><input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
    </div>
    <button class="wyn-btn wyn-btn-line" type="submit">Change password</button>
  </section>
</form>
