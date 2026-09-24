<?php

// Only what differs from Livewire's defaults (vendor/livewire/livewire/config/livewire.php).
// Laravel merges a package config per top-level key, so the whole temporary_file_upload block
// is restated here.
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // Invoice files up to 200 MB (the default is 12 MB) — keep in step with
        // config('uploads.max_kilobytes'), PHP's upload_max_filesize/post_max_size and nginx's
        // client_max_body_size. Each component still validates its own, smaller, limit.
        'rules' => ['required', 'file', 'max:204800'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        // A 200 MB file over a slow connection needs more than the default 5 minutes.
        'max_upload_time' => 15,
        'cleanup' => true,
    ],
];
