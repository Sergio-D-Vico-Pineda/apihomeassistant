<?php
$url = 'http://' . $_SERVER['HTTP_HOST'];
define('CLIENT_ID',  $url);
define('REDIRECT_URI', $url . '/auth/callback.php');
define('DEFAULT_HA_INSTANCE', 'http://homeassistant.local:8123');
