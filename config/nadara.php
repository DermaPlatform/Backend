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
    | Pagination
    |--------------------------------------------------------------------------
    */

    'per_page' => 15,

    'max_per_page' => 100,

];
