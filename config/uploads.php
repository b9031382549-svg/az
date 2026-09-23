<?php

// Invoice file uploads (the Upload page). Small files are previewed and imported inside the web
// request; bigger ones are read and imported in the background — see BackgroundInvoiceUploads.
return [
    // Largest upload accepted, in KB. Livewire's temporary-upload rule (config/livewire.php),
    // PHP's upload_max_filesize/post_max_size and nginx's client_max_body_size allow the same.
    'max_kilobytes' => 204800, // 200 MB ≈ 700k lines of the line-level export

    // An .xlsx/.csv above this many bytes goes to the background path (a 20k-line export is
    // ≈ 5 MB). .xls cannot be streamed and always stays in the request (≤ 25 MB, ≤ 20k lines).
    'background_bytes' => (int) env('UPLOAD_BACKGROUND_BYTES', 5 * 1024 * 1024),

    // Where an upload's file waits between preview and import. Must be shared by the app
    // (receives it) and the worker (reads it) — a named volume in docker-compose.prod.yml.
    'directory' => storage_path('app/uploads'),

    // Safety cap on lines read from one file (the 200 MB cap already bounds it to ~700k).
    'max_lines' => 1000000,

    // Lines per import transaction, and how long one import job works before handing over
    // to its own continuation (below the queue's retry_after of 360 s).
    'slice_lines' => 5000,
    'job_seconds' => 240,

    // Classification of a big upload is fed to the (single) queue in portions: at most
    // 'feed_portion' items at a time, and only while the queue is shallower than
    // 'feed_high_water' jobs — Redis is capped (512 MB, noeviction) and every other
    // classification shares the queue, so it stays short and nobody waits long.
    'feed_portion' => 300,
    'feed_high_water' => 1000,
    'feed_delay_seconds' => 15,

    // Previews nobody imported, and failed ones, are removed (row + files) after this.
    'prune_after_hours' => 24,
];
