<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['route'] = 'chat/sessions/list';
session_start();
// ONLY set the tower auth, mimicking the browser inside the tower
$_SESSION['suki_tower_auth'] = true;
// Make sure auth_user is unset to test the injection
unset($_SESSION['auth_user']);
require 'project/public/api.php';
