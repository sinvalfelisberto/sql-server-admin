<?php
/** @var array $data */
[
    'e' => $e, 'url' => $url, 'appName' => $appName, 'version' => $version,
    'title' => $title, 'bodyClass' => $bodyClass, 'csrf' => $csrf, 'content' => $content,
] = $data;
$scripts = $data['scripts'] ?? [];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $e($csrf) ?>">
    <meta name="base-url" content="<?= $e(rtrim($url(), '/')) ?>">
    <title><?= $e($appName) ?><?= $title !== '' ? ' – ' . $e($title) : ' v' . $e($version) ?></title>
    <script>document.documentElement.dataset.theme = (JSON.parse(localStorage.getItem('sqlwm.theme') || '"dark"'));</script>
    <link rel="stylesheet" href="<?= $e($url('assets/css/app.css')) ?>">
</head>
<body class="<?= $e($bodyClass) ?>">
<?= $content ?>
<?php foreach ($scripts as $script): ?>
<script src="<?= $e($url($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
