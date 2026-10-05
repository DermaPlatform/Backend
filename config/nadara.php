<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clinic Timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC. This is the wall-clock timezone the clinic
    | actually works in, used whenever an appointment time is presented to or
    | accepted from a user.
    |
    */

    'clinic_timezone' => env('NADARA_CLINIC_TIMEZONE', 'Asia/Hebron'),

    /*
    |--------------------------------------------------------------------------
    | Booking Rules
    |--------------------------------------------------------------------------
    |
    | booking_hold_minutes: how long a slot stays reserved while the patient
    | uploads a payment receipt before it is released back to the calendar.
    |
    | cancellation_window_hours: how far ahead of the appointment a patient may
    | still cancel on their own.
    |
    */

    'booking_hold_minutes' => env('NADARA_BOOKING_HOLD_MINUTES', 30),

    'cancellation_window_hours' => env('NADARA_CANCELLATION_WINDOW_HOURS', 24),

    /*
     * How far ahead the availability endpoint will look. Without a ceiling a
     * caller could ask for a year of slots in one request.
     */
    'max_booking_days_ahead' => env('NADARA_MAX_BOOKING_DAYS_AHEAD', 90),

    /*
     * Receipt numbers read NDR-{year}-{sequence}. The sequence restarts each
     * year, which is what makes the number useful to read out loud.
     */
    'receipt_number_prefix' => 'NDR',

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | disk: filesystem disk that holds publicly reachable uploads such as
    | service images. It must be linked with `php artisan storage:link`.
    |
    | image_max_kb / image_mimes: validation limits applied to every uploaded
    | image.
    |
    */

    'uploads' => [
        'disk' => env('NADARA_UPLOAD_DISK', 'public'),
        'service_image_directory' => 'services',
        'user_image_directory' => 'users',
        'image_max_kb' => 2048,
        'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],

        /*
         * Payment receipts and clinical photographs are not public files. The
         * private disk points at storage/app/private, which no web request can
         * reach by guessing a path, so these are served through a route that
         * checks the policy first.
         */
        'private_disk' => env('NADARA_PRIVATE_DISK', 'local'),
        'receipt_directory' => 'receipts',
        'consultation_directory' => 'consultations',
        'receipt_max_kb' => 4096,
        'receipt_mimes' => ['jpg', 'jpeg', 'png', 'pdf'],
        'consultation_image_max_files' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Patients
    |--------------------------------------------------------------------------
    |
    | max_age: upper bound used to sanity check a submitted birth date, so a
    | typo such as 1089 is rejected instead of stored.
    |
    */

    'patients' => [
        'max_age' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Portals
    |--------------------------------------------------------------------------
    |
    | Each portal is a separate front end with its own sign-in. The URL is only
    | used to address password reset links, and falls back to app.frontend_url
    | while a front end has none of its own yet.
    |
    */

    'portals' => [
        'patient' => ['frontend_url' => env('PATIENT_FRONTEND_URL')],
        'clinic' => ['frontend_url' => env('CLINIC_FRONTEND_URL')],
        'admin' => ['frontend_url' => env('ADMIN_FRONTEND_URL')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    |
    | trial_days: how long a newly created doctor runs free before the first
    | payment is due.
    |
    | grace_days: how long a doctor keeps taking bookings after the end date
    | passes, so a late transfer does not take them out of the catalog.
    |
    */

    'subscriptions' => [
        'trial_days' => env('NADARA_TRIAL_DAYS', 14),
        'grace_days' => env('NADARA_GRACE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Push notifications
    |--------------------------------------------------------------------------
    |
    | The VAPID keys themselves live in config/webpush.php. Without them push
    | is off and nothing below is used.
    |
    | allowed_hosts: the push services of the browsers we support. The server
    | posts to whatever address a subscription names, so an address on any
    | other host is refused. A subdomain of a listed host is accepted.
    |
    | max_devices_per_user: how many devices one account may register. The
    | least recently used is dropped when a new one would exceed it.
    |
    */

    'push' => [
        'allowed_hosts' => [
            'fcm.googleapis.com',                 // Chrome, Edge on Android, Samsung Internet, Opera, Brave
            'updates.push.services.mozilla.com',  // Firefox
            'notify.windows.com',                 // Edge on Windows
            'push.apple.com',                     // Safari on iOS, iPadOS and macOS
        ],
        'max_devices_per_user' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Requests one user, or one address for a visitor, may make per minute
    | across the whole API. The sign in endpoints carry a tighter limit of
    | their own.
    |
    */

    'rate_limit_per_minute' => env('NADARA_RATE_LIMIT_PER_MINUTE', 120),

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'per_page' => 15,

    'max_per_page' => 100,

];
