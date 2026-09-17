<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\ConsultationStatus;
use App\Enums\Gender;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\DoctorSchedule;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Arabic demo data for showing the platform to a doctor.
 *
 * Safe to run on an existing database and safe to run twice: every row is
 * matched by a natural key first, and nothing already in the catalog is
 * renamed or deleted. It adds one Arabic doctor with her own schedule,
 * services and patients, then fills her week so every screen has something
 * real to show — including one receipt left unverified so the doctor can
 * confirm a payment live during the demo.
 *
 * Run:  php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private const DOCTOR_EMAIL = 'layla@nadara.test';

    private const PASSWORD = 'nadara2026';

    public function run(): void
    {
        $doctor = $this->doctor();
        $services = $this->services($doctor);
        $patients = $this->patients();

        $this->schedule($doctor);
        $this->appointments($doctor, $services, $patients);
        $this->consultations($doctor, $services, $patients);

        $this->command?->info('بيانات العرض جاهزة.');
        $this->command?->info('الطبيبة: '.self::DOCTOR_EMAIL.' · كلمة السر: '.self::PASSWORD);
        $this->command?->info('السكرتيرة: sana@nadara.test · نفس كلمة السر');
    }

    /**
     * The demo doctor and her secretary, both with Arabic names.
     */
    private function doctor(): User
    {
        $doctor = User::firstOrCreate(
            ['email' => self::DOCTOR_EMAIL],
            [
                'name' => 'د. ليلى حدّاد',
                'phone' => '0599100200',
                'password' => Hash::make(self::PASSWORD),
                'role' => UserRole::Doctor,
                'birth_date' => '1986-04-11',
                'gender' => Gender::Female,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $doctor->doctorProfile()->updateOrCreate(
            ['user_id' => $doctor->id],
            [
                'specialty' => 'أمراض جلدية وتجميل',
                'accepts_appointments' => true,
                'accepts_online_consultations' => true,
                'subscription_status' => SubscriptionStatus::Active,
                'subscription_ends_at' => now()->addYear(),
            ]
        );

        User::firstOrCreate(
            ['email' => 'sana@nadara.test'],
            [
                'name' => 'سناء أبو ندى',
                'phone' => '0599100201',
                'password' => Hash::make(self::PASSWORD),
                'role' => UserRole::Secretary,
                'doctor_id' => $doctor->id,
                'birth_date' => '1995-09-02',
                'gender' => Gender::Female,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        return $doctor;
    }

    /**
     * Arabic services priced on this doctor, so her screens read in Arabic.
     *
     * @return array<string, Service>
     */
    private function services(User $doctor): array
    {
        $general = ServiceCategory::firstOrCreate(['name' => 'الجلدية العامة']);
        $cosmetic = ServiceCategory::firstOrCreate(['name' => 'التجميل والليزر']);

        $definitions = [
            'exam' => ['فحص جلدي شامل', $general, 60, 30, 'تقييم كامل لحالة الجلد والشعر والأظافر مع خطة عناية.'],
            'acne' => ['علاج حب الشباب', $general, 75, 45, 'بروتوكول متدرّج لحب الشباب وآثاره مع متابعة دورية.'],
            'peel' => ['تقشير كيميائي طبي', $cosmetic, 150, 45, 'علاج التصبغات وآثار الحبوب بأحماض طبية آمنة.'],
            'laser' => ['جلسة ليزر - منطقة صغيرة', $cosmetic, 120, 30, 'جلسة ليزر لإزالة الشعر لمنطقة صغيرة.'],
            'online' => ['استشارة جلدية عن بُعد', $general, 50, 20, 'وصف الحالة مع صور، ورد مكتوب من الطبيبة خلال 24 ساعة.'],
        ];

        $services = [];

        foreach ($definitions as $key => [$name, $category, $price, $minutes, $description]) {
            $service = Service::firstOrCreate(
                ['name' => $name],
                [
                    'category_id' => $category->id,
                    'price' => $price,
                    'description' => $description,
                    'is_active' => true,
                ]
            );

            $doctor->offeredServices()->syncWithoutDetaching([
                $service->id => ['price' => $price, 'duration_minutes' => $minutes, 'is_active' => true],
            ]);

            $services[$key] = $service;
        }

        return $services;
    }

    /**
     * Sunday to Thursday, 09:00 to 17:00, in half hour slots.
     */
    private function schedule(User $doctor): void
    {
        foreach (range(0, 4) as $weekday) {
            DoctorSchedule::updateOrCreate(
                ['doctor_id' => $doctor->id, 'weekday' => $weekday],
                ['starts_at' => '09:00:00', 'ends_at' => '17:00:00', 'slot_minutes' => 30, 'is_active' => true]
            );
        }
    }

    /**
     * @return array<int, User>
     */
    private function patients(): array
    {
        $people = [
            ['سارة أحمد المصري', 'sara.demo@nadara.test', '0599200301', '1996-03-12', Gender::Female],
            ['نور الدين شاهين', 'nour.demo@nadara.test', '0599200302', '1990-11-05', Gender::Male],
            ['رهف زكارنة', 'rahaf.demo@nadara.test', '0599200303', '2001-07-21', Gender::Female],
            ['محمد أبو سيف', 'mohammad.demo@nadara.test', '0599200304', '1984-01-30', Gender::Male],
            ['لمى الحلبي', 'lama.demo@nadara.test', '0599200305', '1999-05-17', Gender::Female],
            ['يوسف قديح', 'yousef.demo@nadara.test', '0599200306', '1993-08-09', Gender::Male],
        ];

        return array_map(fn (array $person): User => User::firstOrCreate(
            ['email' => $person[1]],
            [
                'name' => $person[0],
                'phone' => $person[2],
                'password' => Hash::make(self::PASSWORD),
                'role' => UserRole::Patient,
                'birth_date' => $person[3],
                'gender' => $person[4],
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        ), $people);
    }

    /**
     * A believable week: past visits completed, today busy, tomorrow booked,
     * one receipt still waiting for the doctor to confirm, and one hold that
     * has not been paid yet.
     *
     * @param  array<string, Service>  $services
     * @param  array<int, User>  $patients
     */
    private function appointments(User $doctor, array $services, array $patients): void
    {
        $today = CarbonImmutable::now(config('nadara.clinic_timezone'))->startOfDay();

        $plan = [
            // [يوم الإزاحة, الساعة, الدقيقة, المريض, الخدمة, الحالة, حالة الدفع]
            [-6, 10, 0, 0, 'exam', AppointmentStatus::Completed, PaymentStatus::Verified],
            [-3, 11, 30, 1, 'acne', AppointmentStatus::Completed, PaymentStatus::Verified],
            [-1, 9, 30, 2, 'peel', AppointmentStatus::Completed, PaymentStatus::Verified],
            [0, 9, 0, 3, 'exam', AppointmentStatus::Approved, PaymentStatus::Verified],
            [0, 10, 0, 4, 'acne', AppointmentStatus::Approved, PaymentStatus::Verified],
            [0, 11, 30, 5, 'laser', AppointmentStatus::AwaitingReview, PaymentStatus::Submitted],
            [0, 13, 0, 0, 'exam', AppointmentStatus::PendingPayment, null],
            [1, 9, 30, 1, 'peel', AppointmentStatus::Approved, PaymentStatus::Verified],
            [1, 12, 0, 2, 'laser', AppointmentStatus::Approved, PaymentStatus::Verified],
            [2, 10, 30, 3, 'acne', AppointmentStatus::Approved, PaymentStatus::Verified],
            [3, 11, 0, 4, 'exam', AppointmentStatus::Approved, PaymentStatus::Verified],
        ];

        foreach ($plan as [$dayOffset, $hour, $minute, $patientIndex, $serviceKey, $status, $paymentStatus]) {
            $service = $services[$serviceKey];
            $patient = $patients[$patientIndex];
            $minutes = (int) $doctor->offeredServices()->wherePivot('service_id', $service->id)->first()->pivot->duration_minutes;
            $startAt = $today->addDays($dayOffset)->setTime($hour, $minute);

            $appointment = Appointment::firstOrCreate(
                ['doctor_id' => $doctor->id, 'start_at' => $startAt->utc()],
                [
                    'patient_id' => $patient->id,
                    'service_id' => $service->id,
                    'end_at' => $startAt->addMinutes($minutes)->utc(),
                    'price' => $service->price,
                    'status' => $status,
                    'approved_at' => in_array($status, [AppointmentStatus::Approved, AppointmentStatus::Completed], true) ? now() : null,
                    'hold_expires_at' => $status === AppointmentStatus::PendingPayment ? now()->addMinutes(22) : null,
                ]
            );

            if ($paymentStatus !== null) {
                $this->payment($appointment->patient_id, $paymentStatus, ['appointment_id' => $appointment->id], $appointment->price);
            }
        }
    }

    /**
     * Two consultations the doctor can act on, and one already answered.
     *
     * @param  array<string, Service>  $services
     * @param  array<int, User>  $patients
     */
    private function consultations(User $doctor, array $services, array $patients): void
    {
        $price = $services['online']->price;

        $waiting = Consultation::firstOrCreate(
            ['doctor_id' => $doctor->id, 'patient_id' => $patients[2]->id],
            [
                'symptoms' => 'ظهرت بقع حمراء على الخدين والجبين منذ عشرة أيام مع حكة خفيفة، تزيد بعد التعرض للشمس. استخدمت مرطباً عادياً دون تحسن، ولا أستخدم أي دواء آخر.',
                'price' => $price,
                'status' => ConsultationStatus::AwaitingReply,
                'submitted_at' => now()->subDay(),
            ]
        );
        $this->payment($waiting->patient_id, PaymentStatus::Verified, ['consultation_id' => $waiting->id], $price);
        $this->photos($waiting);

        $unpaid = Consultation::firstOrCreate(
            ['doctor_id' => $doctor->id, 'patient_id' => $patients[4]->id],
            [
                'symptoms' => 'تساقط شعر ملحوظ من مقدمة الرأس منذ ثلاثة أشهر بعد الولادة، مع قشرة خفيفة في فروة الرأس. أرفقت صورتين توضحان المنطقة.',
                'price' => $price,
                'status' => ConsultationStatus::PendingPayment,
                'submitted_at' => now()->subHours(3),
            ]
        );
        $this->payment($unpaid->patient_id, PaymentStatus::Submitted, ['consultation_id' => $unpaid->id], $price);
        $this->photos($unpaid);

        $answered = Consultation::firstOrCreate(
            ['doctor_id' => $doctor->id, 'patient_id' => $patients[1]->id],
            [
                'symptoms' => 'حبوب متكررة على الظهر والكتفين منذ شهرين، تترك آثاراً داكنة بعد أن تختفي. جربت غسولاً طبياً لأسبوعين دون نتيجة واضحة.',
                'price' => $price,
                'status' => ConsultationStatus::Replied,
                'submitted_at' => now()->subDays(4),
                'replied_at' => now()->subDays(3),
            ]
        );
        $this->payment($answered->patient_id, PaymentStatus::Verified, ['consultation_id' => $answered->id], $price);

        if ($answered->messages()->count() === 0) {
            ConsultationMessage::create([
                'consultation_id' => $answered->id,
                'sender_id' => $doctor->id,
                'message' => 'ما يظهر في الصور هو حب شباب على الظهر. ابدأ بغسول يحتوي على حمض الساليسيليك مرة يومياً مساءً، مع كريم موضعي بالأدابالين قبل النوم لمدة ستة أسابيع، وتجنّب حمل الحقيبة على الظهر لفترات طويلة. راجعني بصورة جديدة بعد شهر.',
            ]);

            ConsultationMessage::create([
                'consultation_id' => $answered->id,
                'sender_id' => $answered->patient_id,
                'message' => 'شكراً دكتورة. هل أستمر على نفس الغسول إذا صار في جفاف؟',
            ]);
        }
    }

    /**
     * A payment row with a real file on the private disk, so opening the
     * receipt in the portal shows a document rather than a broken link.
     *
     * @param  array<string, int>  $owner
     */
    private function payment(int $patientId, PaymentStatus $status, array $owner, string $amount): void
    {
        if (Payment::where($owner)->exists()) {
            return;
        }

        $path = 'receipts/demo-'.uniqid().'.pdf';
        Storage::disk(config('nadara.uploads.private_disk'))->put($path, $this->receiptPdf($amount));

        Payment::create([
            ...$owner,
            'patient_id' => $patientId,
            'amount' => $amount,
            'receipt_number' => $this->nextReceiptNumber(),
            'receipt_path' => $path,
            'method' => 'bank_of_palestine',
            'reference' => 'TRX-'.random_int(100000, 999999),
            'status' => $status,
            'paid_at' => $status === PaymentStatus::Verified ? now()->subDay() : null,
            'verified_at' => $status === PaymentStatus::Verified ? now()->subDay() : null,
        ]);
    }

    /**
     * Two photographs on a consultation, drawn rather than shipped as binary
     * blobs so the seeder stays readable.
     */
    private function photos(Consultation $consultation): void
    {
        if ($consultation->attachments()->count() > 0 || ! function_exists('imagecreatetruecolor')) {
            return;
        }

        foreach ([['#E8B9A6', 'الخد الأيمن'], ['#D9A08B', 'الجبين']] as [$hex, $label]) {
            $path = 'consultations/demo-'.uniqid().'.jpg';
            $binary = $this->photo($hex, $label);

            Storage::disk(config('nadara.uploads.private_disk'))->put($path, $binary);

            $consultation->attachments()->create([
                'path' => $path,
                'mime' => 'image/jpeg',
                'size' => strlen($binary),
            ]);
        }
    }

    /**
     * A plain JPEG stand-in for a clinical photograph.
     */
    private function photo(string $hex, string $label): string
    {
        $image = imagecreatetruecolor(640, 480);
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        imagefilledrectangle($image, 0, 0, 640, 480, imagecolorallocate($image, $r, $g, $b));
        imagestring($image, 5, 20, 20, 'DEMO PHOTO - '.strtoupper(substr(md5($label), 0, 6)), imagecolorallocate($image, 255, 255, 255));

        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * A one page PDF written by hand: no extension required, and it opens in
     * every browser the way a scanned receipt would.
     */
    private function receiptPdf(string $amount): string
    {
        $text = 'NADARA - PAYMENT RECEIPT (DEMO) - AMOUNT: '.$amount.' ILS';
        $stream = 'BT /F1 16 Tf 40 700 Td ('.$text.') Tj ET';

        $objects = [
            "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n",
            "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n",
            "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>endobj\n",
            "4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n",
            '5 0 obj<</Length '.strlen($stream).">>stream\n".$stream."\nendstream endobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefPosition = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer<</Size '.(count($objects) + 1)."/Root 1 0 R>>\nstartxref\n".$xrefPosition."\n%%EOF";
    }

    /**
     * Keeps the NDR-{year}-{sequence} format the application generates.
     */
    private function nextReceiptNumber(): string
    {
        $prefix = config('nadara.receipt_number_prefix').'-'.now()->year.'-';
        $latest = Payment::where('receipt_number', 'like', $prefix.'%')->orderByDesc('receipt_number')->value('receipt_number');
        $sequence = $latest === null ? 0 : (int) substr($latest, strlen($prefix));

        return $prefix.str_pad((string) ($sequence + 1), 6, '0', STR_PAD_LEFT);
    }
}
