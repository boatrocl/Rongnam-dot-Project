<?php
session_start();
session_destroy();
header('Location: /water_management/index.php');
exit;
?>