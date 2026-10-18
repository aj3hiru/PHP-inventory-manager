<?php
// The store panel lives in /admin. Send visitors to the dashboard (or to the login page when signed out).
header('Location: /admin/dashboard.php', true, 302);
exit;
