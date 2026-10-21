<?php
/** @var array $data */
['e' => $e, 'url' => $url, 'appName' => $appName,
 'csrf' => $csrf, 'error' => $error, 'server' => $server, 'user' => $user, 'trust' => $trust] = $data;
?>
<form class="login-card" method="post" action="<?= $e($url('login')) ?>" autocomplete="off">
    <h1><?= $e($appName) ?></h1>
    <p class="muted">Connect to a SQL Server instance</p>

    <?php if ($error !== ''): ?>
        <div class="alert" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

    <label>Server
        <input name="server" value="<?= $e($server) ?>" placeholder="host, host\instance or host:port" required autofocus>
    </label>
    <label>User
        <input name="user" value="<?= $e($user) ?>" required>
    </label>
    <label>Password
        <span class="pass-wrap">
            <input id="pass" name="pass" type="password">
            <button type="button" class="icon-btn pass-toggle" id="pass-toggle" hidden
                    title="Show password" aria-label="Show password" aria-pressed="false">
                <svg class="eye" viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 5.1A10.7 10.7 0 0 1 12 5c6 0 10 7 10 7a17 17 0 0 1-3.2 3.9M6.6 6.6A17 17 0 0 0 2 12s4 7 10 7a10 10 0 0 0 4.4-1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
            </button>
        </span>
    </label>
    <label class="check">
        <input type="checkbox" name="trust" <?= $trust ? 'checked' : '' ?>>
        Trust server certificate
    </label>

    <button class="btn primary" type="submit">Connect</button>
</form>
