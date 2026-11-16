<?php
session_start();
session_unset();
session_destroy();

// Supprimer le cookie de connexion persistante
if (isset($_COOKIE['mandel_remember'])) {
    setcookie('mandel_remember', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

header("Location: login.php");
exit;
