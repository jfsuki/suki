<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['route'] = 'chat/sessions/list';
session_start();
$_SESSION['auth_user'] = ['id' => 'master_tower', 'tenant_id' => 'default', 'project_id' => 'default', 'role' => 'creator'];
require 'project/public/api.php';
