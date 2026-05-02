<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';

require_login();

session_unset();
session_destroy();

header("Location: " . BASE_URL . "/modules/auth/login.php");
exit();