<?php

$channels = array_values(array_filter(array_map(
    'trim',
    explode(',', env('ADMIN_NOTIFICATION_CHANNELS', 'database,mail,broadcast'))
)));

return [
    'admin_channels' => $channels,
];
