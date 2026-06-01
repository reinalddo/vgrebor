<?php
$hash = '$2y$10$wH8QwQwQwQwQwQwQwQwOQwQwQwQwQwQwQwQwQwQwQwQwQwQwQw';
var_dump(password_verify('admin123', $hash));
