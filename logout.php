<?php
require_once __DIR__ . '/helpers.php';
session_destroy();
redirect('login.php');
