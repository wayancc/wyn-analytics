<?php $v->layout('layout/public', ['title' => $site['name']]); ?>
<div class="wyn-center">
  <p class="wyn-logo"><?= $v->e($site['domain']) ?><span class="wyn-dot">.</span></p>
  <h1 class="wyn-display">Stats<span class="wyn-dot">.</span></h1>
  <p>These stats are protected with a password.</p>
  <?php if ($error): ?><div class="wyn-flash wyn-flash-error" role="alert"><?= $v->e($error) ?></div><?php endif ?>
  <form method="post">
    <?= $v->csrf() ?>
    <label class="wyn-field">
      <span>Password</span>
      <input type="password" name="password" required autofocus autocomplete="current-password">
    </label>
    <button class="wyn-btn" type="submit">View stats</button>
  </form>
</div>
