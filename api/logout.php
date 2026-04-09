<?php
require_once __DIR__ . '/../auth.php';
logout();
header('Location: ../index.php?page=login');
exit;
