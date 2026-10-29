<?php /** @var WynAnalytics\View $v @var string $content @var string $title */
$bodyClass ??= 'wyn-public-message'; ?><!doctype html>
<html lang="en" data-wyn-theme="<?= $v->e($settings->get('theme')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $v->e($title) ?></title>
<link rel="icon" href="<?= $v->asset('wyn-favicon.svg') ?>" type="image/svg+xml">
<link rel="preload" href="<?= $v->url('assets/fonts/wyn-inter-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $v->asset('wyn-tokens.css') ?>">
<link rel="stylesheet" href="<?= $v->asset('wyn-admin.css') ?>">
<?php if (!empty($withScript)): ?><script src="<?= $v->asset('wyn-admin.js') ?>" defer></script><?php endif ?>
</head>
<body class="wyn-public <?= $v->e($bodyClass) ?>">
<main id="wyn-main"><?= $content ?></main>
<footer><?= $footer ?? 'Privacy-friendly stats by <a href="https://github.com/wayancc/wyn-analytics">Wyn Analytics</a>. No cookies, no personal data.' ?></footer>
</body>
</html>
