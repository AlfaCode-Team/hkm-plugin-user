<?php
/**
 * User sample view.
 *
 * Published to resources/views/user.php on enable. Render it from a
 * controller via the View plugin: $this->view('user', [...]).
 * A project file of the same name overrides this (project-first cascade).
 *
 * @var array<string, mixed> $data
 */
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(lang_locale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars(trans('user::user.feedback.user'), ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
    <h1><?= htmlspecialchars(trans('user::user.placeholder.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars(trans('user::user.placeholder.edit_at'), ENT_QUOTES, 'UTF-8') ?><code>resources/views/user.php</code>.</p>
</body>
</html>
