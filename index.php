<?php
require_once __DIR__ . '/helpers.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

redirect('login.php');
