<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\Office;
use App\Models\OfficeService;
use App\Models\OfficeStation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $hours = ['opening_time' => '08:00:00', 'closing_time' => '15:00:00'];

        /*
         * QueueFlow TMC â€” official service offices.
         * The system uses exactly these offices:
         * one Registrar office per course, plus Cashier, Admissions, SASO,
         * Scholarship Office and School Clinic.
         */
        $courses = [
            'BSIT' => 'Bachelor of Science in Information Technology',
            'BSOA' => 'Bachelor of Science in Office Administration',
            'BSCRIM' => 'Bachelor of Science in Criminology',
            'BEED' => 'Bachelor of Elementary Education',
            'BSED' => 'Bachelor of Secondary Education major in English',
            'BACOMM' => 'Bachelor of Arts in Communication',
            'BAPOLSCI' => 'Bachelor of Arts in Political Science',
        ];

        $registrarDescription = 'Enrollment, scholastic records, certifications, TOR, CAV, and student academic records.';

        $offices = [
            ['name' => 'Cashier', 'code' => 'CASH', 'description' => 'Tuition and school-fee payments, official receipts, payment verification, and cashier concerns.', ...$hours],
            ['name' => 'Admissions', 'code' => 'ADM', 'description' => 'Admission inquiries, applications, assessment, new-student processing, and admission requirements.', ...$hours],
            ['name' => 'SASO', 'code' => 'SASO', 'description' => 'Student Affairs and Services Office for IDs, clearances, student support, organizations, and student activities.', ...$hours],
            ['name' => 'Scholarship Office', 'code' => 'SCH', 'description' => 'Scholarship applications, verification, renewal, records, and scholarship assistance.', ...$hours],
            ['name' => 'School Clinic', 'code' => 'CLINIC', 'description' => 'Medical consultation, health clearance, medical certificates, and enrollment health requirements.', ...$hours],
        ];

        foreach ($courses as $code => $name) {
            $offices[] = [
                'name' => "Registrar - {$code}",
                'code' => "REG-{$code}",
                'description' => $registrarDescription,
                ...$hours,
            ];
        }

        foreach ($offices as $office) {
            Office::updateOrCreate(
                ['code' => $office['code']],
                $office + ['is_active' => true, 'max_slots' => 100]
            );
        }

        // Retire all offices that are not part of the official TMC structure.
        Office::whereNotIn('code', Office::OPERATIONAL_CODES)
            ->update(['is_active' => false]);

        $officeByCode = fn (string $code) => Office::where('code', $code)->firstOrFail();

        // Each course is owned by its dedicated Registrar office.
        foreach ($courses as $code => $name) {
            Course::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'office_id' => $officeByCode("REG-{$code}")->id,
                    'is_active' => true,
                ]
            );
        }

        // Create-or-update a station without clobbering a location an admin has
        // since edited in the UI. The campus address is only a seed default.
        $upsertStation = function (int $officeId, string $name, array $attributes) {
            $station = OfficeStation::firstOrNew([
                'office_id' => $officeId,
                'name' => $name,
            ]);

            if (! $station->exists || blank($station->location)) {
                $station->location = OfficeStation::CAMPUS_LOCATION;
            }

            $station->fill($attributes)->save();

            return $station;
        };

        $stations = [
            ['CASH', 'Cashier Main Station'],
            ['ADM', 'Admissions Main Station'],
            ['SASO', 'SASO Main Station'],
            ['SCH', 'Scholarship Main Station'],
            ['CLINIC', 'School Clinic Main Station'],
        ];

        $stationModels = [];
        foreach ($stations as [$officeCode, $name]) {
            $stationModels[$name] = $upsertStation(
                $officeByCode($officeCode)->id,
                $name,
                [
                    'course_codes' => array_keys($courses),
                    'min_daily_students' => 40,
                    'target_daily_students' => 60,
                    'max_daily_slots' => 100,
                    'opening_time' => '08:00:00',
                    'closing_time' => '15:00:00',
                    'is_active' => true,
                ]
            );
        }

        // One station per Registrar course office, scoped to its own course.
        foreach ($courses as $code => $name) {
            $stationModels["REG-{$code}"] = $upsertStation(
                $officeByCode("REG-{$code}")->id,
                "Registrar {$code} Station",
                [
                    'course_codes' => [$code],
                    'min_daily_students' => 40,
                    'target_daily_students' => 60,
                    'max_daily_slots' => 100,
                    'opening_time' => '08:00:00',
                    'closing_time' => '15:00:00',
                    'is_active' => true,
                ]
            );
        }

        $services = [];
        $add = function (string $office, string $name, string $description, string $station, bool $requiresCourse = false) use (&$services) {
            $services[] = [$office, $name, $description, $station, $requiresCourse];
        };

        // Registrar services â€” cloned to every Registrar course office.
        // Kept in sync with migration 000005 so fresh installs and migrated
        // databases expose the exact same set of services.
        $registrarServices = [
            ['Enrollment for Incoming Students', 'Process enrollment and registration requirements.', false],
            ['Submit Physical Enrollment Requirements', 'Submit physical enrollment documents and requirements.', true],
            ['Issuance of Scholastic Records / Certifications', 'Request official certifications and scholastic records.', true],
            ['Claim Transcript of Records (TOR)', 'Request or claim an official Transcript of Records.', true],
            ['CAV â€“ Certification, Authentication and Verification', 'Request certification, authentication and verification of records.', true],
            ['Academic Record / Grade Validation', 'Validate grades and academic records.', true],
            ['Request Add/Drop/Change (ADC) Form', 'Request an ADC form for enrollment changes.', true],
            ['Graduation / Transfer Credentials', 'Process graduation and transfer credentials.', true],
            ['Final Enrollment Form / Class Booklet Releasing', 'Release the final enrollment form / class booklet.', true],
        ];
        foreach ($courses as $code => $name) {
            foreach ($registrarServices as [$svcName, $svcDesc, $requiresCourse]) {
                $add("REG-{$code}", $svcName, $svcDesc, "REG-{$code}", $requiresCourse);
            }
        }

        // Cashier
        $add('CASH', 'Tuition / School Fee Payment', 'Pay tuition and other approved school fees.', 'Cashier Main Station', false);
        $add('CASH', 'Official Receipt / Payment Verification', 'Request receipt copies and verify posted payments.', 'Cashier Main Station', false);
        $add('CASH', 'Account / Balance Concern', 'Resolve payment, balance, and cashier account concerns.', 'Cashier Main Station', true);
        $add('CASH', 'Other Cashier Transaction', 'Queue for other cashier-related transactions.', 'Cashier Main Station', false);

        // Admissions
        $add('ADM', 'Admission Inquiry', 'Ask about admission requirements, schedules, and procedures.', 'Admissions Main Station', false);
        $add('ADM', 'New Student Application', 'Submit and process new-student admission applications.', 'Admissions Main Station', false);
        $add('ADM', 'Admission Requirements Submission', 'Submit required admission documents.', 'Admissions Main Station', false);
        $add('ADM', 'Assessment / Admission Processing', 'Process admission assessment and related transactions.', 'Admissions Main Station', false);
        $add('ADM', 'Transfer Student Admission', 'Process admission requirements for transferees.', 'Admissions Main Station', false);

        // SASO
        $add('SASO', 'Student ID Concern', 'Process lost, damaged, incorrect, or replacement student IDs.', 'SASO Main Station', true);
        $add('SASO', 'Student Clearance', 'Process student-affairs clearance requirements.', 'SASO Main Station', true);
        $add('SASO', 'Student Information / Support', 'Process student information and general support concerns.', 'SASO Main Station', true);
        $add('SASO', 'Student Organization / Activities', 'Process student organization compliance and activity requests.', 'SASO Main Station', true);
        $add('SASO', 'Gate Pass / Student Permission', 'Process gate-pass and student permission requests.', 'SASO Main Station', true);

        // Scholarship Office
        $add('SCH', 'Scholarship Application', 'Submit scholarship application requirements.', 'Scholarship Main Station', true);
        $add('SCH', 'Scholarship Verification / Renewal', 'Process scholarship verification and renewal concerns.', 'Scholarship Main Station', true);
        $add('SCH', 'Scholarship Records / Certification', 'Request scholarship records and certifications.', 'Scholarship Main Station', true);
        $add('SCH', 'Scholarship Inquiry', 'Ask about available scholarships and scholarship procedures.', 'Scholarship Main Station', false);

        // School Clinic
        $add('CLINIC', 'Medical Consultation', 'Queue for student medical consultation.', 'School Clinic Main Station', true);
        $add('CLINIC', 'Medical / Health Clearance', 'Secure health clearance required for enrollment and school activities.', 'School Clinic Main Station', true);
        $add('CLINIC', 'Medical Certificate Request', 'Request a medical certificate or related documentation.', 'School Clinic Main Station', true);
        $add('CLINIC', 'Clinic Health Concern', 'Queue for other approved school clinic services.', 'School Clinic Main Station', true);

        foreach ($services as [$officeCode, $name, $description, $stationName, $requiresCourse]) {
            $stationKey = str_starts_with($officeCode, 'REG-') ? $officeCode : $stationName;
            OfficeService::updateOrCreate(
                ['office_id' => $officeByCode($officeCode)->id, 'name' => $name],
                [
                    'station_id' => $stationModels[$stationKey]->id,
                    'description' => $description,
                    'requires_course' => $requiresCourse,
                    'is_active' => true,
                ]
            );
        }

        // Hide services belonging to retired offices.
        OfficeService::whereHas('office', function ($q) {
            $q->whereNotIn('code', Office::OPERATIONAL_CODES);
        })->update(['is_active' => false]);

        // Retire staff accounts assigned to removed offices.
        User::where('role', User::ROLE_STAFF)
            ->whereHas('office', function ($q) {
                $q->whereNotIn('code', Office::OPERATIONAL_CODES);
            })
            ->update(['is_active' => false]);

        $admin = User::updateOrCreate(
            ['email' => 'admin@tmc.edu'],
            [
                'name' => 'System Administrator',
                'password' => 'password',
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        // One staff account per Registrar course office, so every one of the
        // seven course queues has an assigned staff member out of the box.
        // The BSIT entry keeps its historical email; the rest follow the
        // same registrar.<course>@tmc.edu pattern.
        $registrarStaff = [
            ['registrar.staff@tmc.edu', 'Registrar Staff (BSIT)', 'REG-BSIT'],
            ['registrar.bsoa@tmc.edu', 'Registrar Staff (BSOA)', 'REG-BSOA'],
            ['registrar.bscrim@tmc.edu', 'Registrar Staff (BSCRIM)', 'REG-BSCRIM'],
            ['registrar.beed@tmc.edu', 'Registrar Staff (BEED)', 'REG-BEED'],
            ['registrar.bsed@tmc.edu', 'Registrar Staff (BSED)', 'REG-BSED'],
            ['registrar.bacomm@tmc.edu', 'Registrar Staff (BACOMM)', 'REG-BACOMM'],
            ['registrar.bapolsci@tmc.edu', 'Registrar Staff (BAPOLSCI)', 'REG-BAPOLSCI'],
        ];

        foreach ([
            ['cashier.staff@tmc.edu', 'Cashier Staff', 'CASH'],
            ['admissions.staff@tmc.edu', 'Admissions Staff', 'ADM'],
            ['saso.staff@tmc.edu', 'SASO Staff', 'SASO'],
            ['scholarship.staff@tmc.edu', 'Scholarship Staff', 'SCH'],
            ['clinic.staff@tmc.edu', 'School Clinic Staff', 'CLINIC'],
            ...$registrarStaff,
        ] as [$email, $name, $code]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => 'password',
                    'role' => User::ROLE_STAFF,
                    'office_id' => $officeByCode($code)->id,
                    'is_active' => true,
                ]
            );
        }

        // Link each Registrar course row to the staff member sitting on its
        // office, so Courses "Assigned Staff" and Staff "Assigned Courses"
        // agree from a fresh install.
        foreach ($courses as $code => $name) {
            $member = User::where('role', User::ROLE_STAFF)
                ->where('office_id', $officeByCode("REG-{$code}")->id)
                ->orderBy('id')
                ->first();

            if ($member) {
                Course::where('code', $code)->update(['staff_id' => $member->id]);
            }
        }

        User::updateOrCreate(
            ['email' => 'student@tmc.edu'],
            [
                'name' => 'Juan Dela Cruz',
                'password' => 'password',
                'role' => User::ROLE_STUDENT,
                'student_id' => '2024-00123',
                'course' => 'BSIT',
                'block' => '1',
                'year_level' => '2nd Year',
                'is_active' => true,
            ]
        );

        Announcement::updateOrCreate(
            ['title' => 'QueueFlow TMC Enrollment Operations'],
            [
                'body' => 'QueueFlow TMC services are available from 8:00 AM to 3:00 PM. Each office can accept up to 100 queue slots per deployment. Select the office and service that matches your transaction.',
                'is_active' => true,
                'posted_by' => $admin->id,
            ]
        );

        Setting::set('site_name', 'QueueFlow TMC');
        Setting::set('college_name', 'Trinidad Municipal College');
        Setting::set('daily_reset_time', '00:00');
        Setting::set('operational_start_time', '08:00');
        Setting::set('operational_end_time', '15:00');
        Setting::set('minimum_students_per_station', '40');
        Setting::set('target_students_per_station', '60');
        Setting::set('max_slots_per_station', '100');
        Setting::set('supported_year_levels', json_encode(['1st Year', '2nd Year', '3rd Year', '4th Year']));
        Setting::set('supported_courses', json_encode(array_keys($courses)));
        Setting::set('max_active_tickets_per_student', '1');
    }
}
