<?php $v->layout('layout/admin'); ?>
<p class="wyn-logo"><?= $v->e($settings->get('site_name')) ?><span class="wyn-dot">.</span></p>
<h1 class="wyn-display">Sign in<span class="wyn-dot">.</span></h1>
<?php if ($error): ?><div class="wyn-flash wyn-flash-error" role="alert"><?= $v->e($error) ?></div><?php endif ?>
<form method="post">
  <?= $v->csrf() ?>
  <label class="wyn-field">
    <span>Email</span>
    <input type="email" name="email" value="<?= $v->e($email) ?>" required autofocus autocomplete="username">
  </label>
  <label class="wyn-field">
    <span>Password</span>
    <input type="password" name="password" required autocomplete="current-password">
  </label>
  <button class="wyn-btn" type="submit">Sign in</button>
</form>
<p class="wyn-small wyn-muted" style="margin-top:22px">Forgot your password? On the server, run <code>php bin/wyn-analytics user:password --email=you@example.com</code></p>
