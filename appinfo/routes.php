<?php
return [
    'routes' => [
        ['name' => 'oauth#receiveToken', 'url' => '/oauth', 'verb' => 'POST'],
        ['name' => 'oauth#callback', 'url' => '/callback', 'verb' => 'GET'],
    ]
];
