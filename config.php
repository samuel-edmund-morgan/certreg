<?php
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'enter-db-name',
    'db_user' => 'enter-db-username',
    'db_pass' => 'enter-db-username-pass',
    'site_domain' => 'certs.domain.name',
    'hash_salt' => 'here-enter-random-salt',
    'template_path' => __DIR__.'/files/cert_template.jpg',
    'output_dir'    => __DIR__.'/files/certs',
    'font_path'     => __DIR__.'/fonts/Montserrat-Light.ttf',
    'coords' => [
        'name'   => ['x'=>600, 'y'=>420, 'size'=>28, 'angle'=>0],
        'score'  => ['x'=>600, 'y'=>470, 'size'=>24, 'angle'=>0],
        'course' => ['x'=>600, 'y'=>520, 'size'=>24, 'angle'=>0],
        'date'   => ['x'=>600, 'y'=>570, 'size'=>24, 'angle'=>0],
        'qr'     => ['x'=>150, 'y'=>420, 'size'=>220],
    ],
    'site_name' => "Glorious Site Name",
    'logo_path' => '/assets/logo.png',
];
