<?php $v->layout('layout/public', ['title' => rtrim($heading, '.')]); ?>
<div class="wyn-center">
  <h1 class="wyn-display"><?= $v->e(rtrim($heading, '.')) ?><span class="wyn-dot">.</span></h1>
  <p class="wyn-muted"><?= $v->e($text) ?></p>
</div>
