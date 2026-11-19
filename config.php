<?php
// KAVA Architect site settings.
// Email and website text are easier to change from the admin panel: Admin → Email settings / Site content.
return [
    'site_name'     => 'KAVA Architect',
    // Where new enquiries are emailed. Leave empty to only save them in the admin panel.
    'notify_email'  => '',
    // The "From" address for notification emails. Use an address on your own domain.
    'from_email'    => 'no-reply@yourdomain.com',
    // Database file. Keep it outside the public folder if your host allows it.
    'db_path'       => __DIR__ . '/data/kava.sqlite',
    // Maximum enquiries from one visitor per hour.
    'rate_limit'    => 5,
    // Times in the admin panel are shown in this zone.
    'timezone'      => 'Asia/Kolkata',
];
