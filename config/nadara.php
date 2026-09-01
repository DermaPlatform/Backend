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
    | Pagination
    |--------------------------------------------------------------------------
    */

    'per_page' => 15,

    'max_per_page' => 100,

];
