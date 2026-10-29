<?php
/**
 * Add a site / site settings.
 * @var WynAnalytics\View $v @var array $site @var array $errors @var array $goals @var array $excluded
 */
$isNew = empty($site['id']);
$v->layout('layout/admin', $isNew
    ? ['heading' => 'Add a site', 'crumb' => '<a href="' . $v->url('admin') . '">Sites</a> /', 'intro' => 'One site per domain. Subdomains can be counted with it.']
    : ['heading' => $site['name'], 'crumb' => '<a href="' . $v->url('admin') . '">Sites</a> / ' . $v->e($site['domain']), 'tabsFor' => $site]);
$field = fn (string $k) => isset($errors[$k]) ? ' has-error' : '';
$err = fn (string $k) => isset($errors[$k]) ? '<small class="wyn-error">' . $v->e($errors[$k]) . '</small>' : '';
?>
<form method="post" action="<?= $v->url($isNew ? 'admin/sites' : 'admin/sites/' . $site['id'] . '/settings') ?>" class="wyn-form" novalidate>
  <?= $v->csrf() ?>
  <section class="wyn-section">
    <h2>Website</h2>
    <div class="wyn-two">
      <label class="wyn-field<?= $field('domain') ?>">
        <span>Domain</span>
        <input name="domain" value="<?= $v->e($site['domain']) ?>" placeholder="example.com" required autocomplete="off" spellcheck="false" <?= $isNew ? 'autofocus' : '' ?>>
        <?= $err('domain') ?: '<small>Without https:// or www. This is also what the script tag says.</small>' ?>
      </label>
      <label class="wyn-field">
        <span>Name</span>
        <input name="name" value="<?= $v->e($site['name']) ?>" placeholder="Same as the domain" maxlength="60">
      </label>
    </div>
    <label class="wyn-field<?= $field('timezone') ?>" style="max-width:420px">
      <span>Time zone</span>
      <select name="timezone" data-wyn-tz="<?= $isNew ? 'guess' : 'keep' ?>">
        <?php foreach ($timezones as $tz): ?><option<?= $tz === $site['timezone'] ? ' selected' : '' ?>><?= $v->e($tz) ?></option><?php endforeach ?>
      </select>
      <?= $err('timezone') ?: '<small>“Today” and every daily number follow this time zone.</small>' ?>
    </label>
    <label class="wyn-check">
      <input type="checkbox" name="include_subdomains" value="1"<?= $site['include_subdomains'] ? ' checked' : '' ?>>
      <span>Count subdomains as this site<small>Visits to blog.<?= $v->e($site['domain'] ?: 'example.com') ?> are added here too.</small></span>
    </label>
  </section>

  <section class="wyn-section" id="privacy">
    <h2>Privacy</h2>
    <label class="wyn-check">
      <input type="checkbox" name="respect_gpc" value="1"<?= $site['respect_gpc'] ? ' checked' : '' ?>>
      <span>Respect Global Privacy Control<small>Visitors whose browser sends the GPC signal aren’t counted. Recommended. Turning it off counts everyone, which is still cookieless, but ignores a visitor’s stated choice.</small></span>
    </label>
  </section>

  <section class="wyn-section" id="public">
    <h2>Public stats page</h2>
    <p>Share a read-only page with big, clean numbers. Visitors see totals and top lists, never filters or raw data.</p>
    <label class="wyn-check">
      <input type="checkbox" name="public" value="1"<?= $site['public'] ? ' checked' : '' ?>>
      <span>Make the stats page public<?php if (!$isNew): ?><small><a href="<?= $v->e($publicUrl) ?>"><?= $v->e($publicUrl) ?></a></small><?php endif ?></span>
    </label>
    <div class="wyn-two">
      <label class="wyn-field<?= $field('public_password') ?>">
        <span>Password (optional)</span>
        <input type="password" name="public_password" autocomplete="new-password" placeholder="<?= ($site['public_password'] ?? '') !== '' ? '•••••••• saved' : 'No password' ?>">
        <?= $err('public_password') ?: '<small>Leave empty to keep the current setting.</small>' ?>
      </label>
    </div>
    <?php if (($site['public_password'] ?? '') !== ''): ?>
    <label class="wyn-check"><input type="checkbox" name="remove_password" value="1"><span>Remove the password</span></label>
    <?php endif ?>
  </section>

  <div class="wyn-submit">
    <button class="wyn-btn" type="submit"><?= $isNew ? 'Add site' : 'Save settings' ?></button>
    <?php if ($isNew): ?><a class="wyn-btn wyn-btn-line" href="<?= $v->url('admin') ?>">Cancel</a><?php endif ?>
  </div>
</form>

<?php if (!$isNew): $sid = $site['id']; ?>
<div class="wyn-form">
  <section class="wyn-section" id="goals">
    <h2>Goals</h2>
    <p>A goal is something you want visitors to do. Pageview goals match a path (use * for “anything”, like /blog/*). Event goals match an event name.</p>
    <?php if ($goals !== []): ?>
    <ul class="wyn-items">
      <?php foreach ($goals as $g): ?>
      <li>
        <span><span class="wyn-label wyn-muted"><?= $g['kind'] === 'event' ? 'Event' : 'Pageview' ?></span>&nbsp; <?= $v->e($g['value']) ?></span>
        <form method="post" action="<?= $v->url("admin/sites/{$sid}/goal-delete") ?>"><?= $v->csrf() ?>
          <input type="hidden" name="goal" value="<?= (int) $g['id'] ?>">
          <button class="wyn-btn wyn-btn-small wyn-btn-line" type="submit">Remove<span class="wyn-sr"> goal <?= $v->e($g['value']) ?></span></button>
        </form>
      </li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>
    <form method="post" action="<?= $v->url("admin/sites/{$sid}/goal-add") ?>" class="wyn-two" style="align-items:end">
      <?= $v->csrf() ?>
      <label class="wyn-field"><span>Type</span>
        <select name="kind"><option value="pageview">Pageview: visited a page</option><option value="event">Event: like “Signup”</option></select>
      </label>
      <label class="wyn-field"><span>Path or event name</span>
        <span class="wyn-inline"><input name="value" placeholder="/thanks" required maxlength="200"><button class="wyn-btn" type="submit">Add goal</button></span>
      </label>
    </form>
  </section>

  <section class="wyn-section" id="exclude">
    <h2>Exclude visits</h2>
    <p>Visits from these addresses aren’t counted. Only a keyed hash of each address is stored, never the address itself. The easiest way to exclude only your own browser is to open <code>https://<?= $v->e($site['domain']) ?>/#wyn-ignore</code> once.</p>
    <?php if ($excluded !== []): ?>
    <ul class="wyn-items">
      <?php foreach ($excluded as $e): ?>
      <li>
        <span><?= $v->e($e['label']) ?> <span class="wyn-muted wyn-small">· added <?= $v->e($v->time((int) $e['added_at'], 'M j, Y')) ?></span></span>
        <form method="post" action="<?= $v->url("admin/sites/{$sid}/unexclude") ?>"><?= $v->csrf() ?>
          <input type="hidden" name="hash" value="<?= $v->e($e['hash']) ?>">
          <button class="wyn-btn wyn-btn-small wyn-btn-line" type="submit">Remove<span class="wyn-sr"> <?= $v->e($e['label']) ?></span></button>
        </form>
      </li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>
    <div class="wyn-actions" style="margin-bottom:16px">
      <form method="post" action="<?= $v->url("admin/sites/{$sid}/exclude-ip") ?>"><?= $v->csrf() ?>
        <input type="hidden" name="mine" value="1">
        <button class="wyn-btn wyn-btn-line" type="submit">Exclude my current connection</button>
      </form>
    </div>
    <form method="post" action="<?= $v->url("admin/sites/{$sid}/exclude-ip") ?>" class="wyn-two" style="align-items:end">
      <?= $v->csrf() ?>
      <label class="wyn-field"><span>Label</span><input name="label" placeholder="Office" maxlength="40"></label>
      <label class="wyn-field"><span>IP address</span>
        <span class="wyn-inline"><input name="ip" placeholder="203.0.113.7" required spellcheck="false"><button class="wyn-btn" type="submit">Exclude</button></span>
      </label>
    </form>
  </section>

  <section class="wyn-section" id="api">
    <h2>Stats API</h2>
    <p>Read this site’s numbers as JSON from another app, with a read-only token.</p>
    <?php if ($newToken): ?>
      <p class="wyn-token" id="wyn-token"><?= $v->e($newToken) ?></p>
      <p><button class="wyn-btn wyn-btn-small wyn-btn-line" type="button" data-wyn-copy="#wyn-token">Copy token</button></p>
    <?php elseif ($site['api_token_hash'] !== ''): ?>
      <p>A token ending in <code>…<?= $v->e($site['api_token_hint']) ?></code> is active.</p>
    <?php endif ?>
    <div class="wyn-code"><button class="wyn-copy" type="button" data-wyn-copy-next>Copy</button><pre><code>curl -H "Authorization: Bearer <?= $v->e($newToken ?: 'wyn_…') ?>" \
  "<?= $v->e($apiUrl) ?>?range=30d"</code></pre></div>
    <div class="wyn-actions">
      <form method="post" action="<?= $v->url("admin/sites/{$sid}/token") ?>"<?= $site['api_token_hash'] !== '' ? ' data-wyn-confirm="Apps using the current token will stop working. Create a new one?"' : '' ?>><?= $v->csrf() ?>
        <button class="wyn-btn wyn-btn-line" type="submit"><?= $site['api_token_hash'] !== '' ? 'Replace token' : 'Create token' ?></button>
      </form>
      <?php if ($site['api_token_hash'] !== ''): ?>
      <form method="post" action="<?= $v->url("admin/sites/{$sid}/token-revoke") ?>"><?= $v->csrf() ?>
        <button class="wyn-btn wyn-btn-line wyn-btn-danger" type="submit">Revoke</button>
      </form>
      <?php endif ?>
    </div>
  </section>

  <section class="wyn-section" id="danger">
    <h2>Delete site</h2>
    <p>Deletes <?= $v->e($site['domain']) ?> and all of its stats, including rollups. This can’t be undone.</p>
    <form method="post" action="<?= $v->url("admin/sites/{$sid}/delete") ?>" class="wyn-two" style="align-items:end" data-wyn-confirm="Delete <?= $v->e($site['domain']) ?> and all its stats?">
      <?= $v->csrf() ?>
      <label class="wyn-field"><span>Type <?= $v->e($site['domain']) ?> to confirm</span>
        <span class="wyn-inline"><input name="confirm_domain" required autocomplete="off" spellcheck="false"><button class="wyn-btn wyn-btn-danger" type="submit">Delete</button></span>
      </label>
    </form>
  </section>
</div>
<?php endif ?>
