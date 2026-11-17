<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

// https://localhost/shoe-inventory/ used to show "Forbidden" (no start page). Send people to the right place.
if (current_user() === null) {
    redirect('login.php');
}
require_login();                 // re-checks the account and loads its permissions
redirect(home_page());
