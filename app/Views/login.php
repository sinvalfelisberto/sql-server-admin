<?php
/** @var array $data */
['e' => $e, 'url' => $url, 'appName' => $appName, 'version' => $version,
 'csrf' => $csrf, 'error' => $error, 'server' => $server, 'user' => $user, 'trust' => $trust] = $data;
?>
<form class="login-card" method="post" action="<?= $e($url('login')) ?>" autocomplete="off">
    <h1><?= $e($appName) ?> <small>v<?= $e($version) ?></small></h1>
    <p class="muted">Conecte-se a uma instância do SQL Server</p>

    <?php if ($error !== ''): ?>
        <div class="alert" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

    <label>Servidor
        <input name="server" value="<?= $e($server) ?>" placeholder="host, host\instancia ou host:porta" required autofocus>
    </label>
    <label>Usuário
        <input name="user" value="<?= $e($user) ?>" required>
    </label>
    <label>Senha
        <input name="pass" type="password">
    </label>
    <label class="check">
        <input type="checkbox" name="trust" <?= $trust ? 'checked' : '' ?>>
        Confiar no certificado do servidor
    </label>

    <button class="btn primary" type="submit">Conectar</button>
</form>
