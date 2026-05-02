<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: " . BASE_URL . "/modules/auth/login.php");
        exit();
    }
}

function require_role(string $role) {
    require_login();
    if ($_SESSION['role'] !== $role) {
        header("Location: " . BASE_URL . "/modules/auth/login.php");
        exit();
    }
}