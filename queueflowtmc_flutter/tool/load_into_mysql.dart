// Throwaway: emits a complete MySQL load for the seeded campus.
//
// Every row comes from buildDemoCampus(), so the script cannot drift from
// what the app actually ships. Passwords are written as bcrypt hashes
// rather than the plaintext the in-app seed uses, because this one lands in
// a database rather than on a device.
import 'dart:io';

import 'package:queueflow_tmc/services/demo_campus.dart';

/// bcrypt hashes, generated with PHP `password_hash($p, PASSWORD_BCRYPT)`.
const Map<String, String> _bcrypt = <String, String>{
  'admin123': r'$2y$10$QIDSf14N1G5.kDmgqYsaFuJbIzKooVrhpvJ7sLmtPUeu8P4Biejh6',
  'staff123': r'$2y$10$NnTkeSiC1BgRKDjnSCtRbeER4UZ9O8ATyBKbiXko24VF0fQxoqXs.',
  'student123': r'$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy',
};

final StringBuffer _out = StringBuffer();

void w(String line) => _out.writeln(line);

/// Single-quoted SQL literal, with the escapes MySQL expects. Numbers and
/// booleans are emitted bare so integer columns never rely on coercion.
String q(Object? value) {
  if (value == null) {
    return 'NULL';
  }
  if (value is bool) {
    return value ? '1' : '0';
  }
  if (value is int || value is double) {
    return '$value';
  }
  return "'${'$value'.replaceAll('\\', r'\\').replaceAll("'", r"''")}'";
}

/// `NULL` for an empty/absent datetime so MySQL keeps it as NULL. Fractional
/// seconds are dropped to match the DATETIME columns, which have no fsp.
String? dq(Object? value) {
  if (value == null || '$value'.isEmpty) {
    return null;
  }
  final String s = '$value'.replaceFirst('T', ' ');
  final int dot = s.indexOf('.');
  return dot == -1 ? s : s.substring(0, dot);
}

List<Map<String, dynamic>> rowsOf(dynamic list) =>
    (list as List<dynamic>).cast<Map<String, dynamic>>();

void insert(String table, List<String> cols, List<List<Object?>> values) {
  if (values.isEmpty) {
    return;
  }
  w('INSERT INTO `$table` (${cols.map((String c) => '`$c`').join(', ')}) VALUES');
  final List<String> rows = values
      .map((List<Object?> v) => '  (${v.map(q).join(', ')})')
      .toList();
  w('${rows.join(',\n')};');
  w('');
}

void main() {
  final Map<String, dynamic> campus = buildDemoCampus();

  final List<Map<String, dynamic>> courses = rowsOf(campus['courses']);
  final List<Map<String, dynamic>> offices = rowsOf(campus['offices']);
  final List<Map<String, dynamic>> accounts = rowsOf(campus['accounts']);
  final List<Map<String, dynamic>> batches = rowsOf(campus['batches']);
  final List<Map<String, dynamic>> tickets = rowsOf(campus['tickets']);
  final List<Map<String, dynamic>> announcements =
      rowsOf(campus['announcements']);
  final Map<String, dynamic> settings =
      campus['settings'] as Map<String, dynamic>;
  final Map<String, dynamic> sequences =
      campus['sequences'] as Map<String, dynamic>;

  // services and stations are nested inside each office row
  final List<Map<String, dynamic>> services = <Map<String, dynamic>>[];
  final List<Map<String, dynamic>> stations = <Map<String, dynamic>>[];
  for (final Map<String, dynamic> office in offices) {
    services.addAll(rowsOf(office['services']));
    stations.addAll(rowsOf(office['stations']));
  }

  w('-- ============================================================');
  w('-- QUEUEFLOW TMC - campus load');
  w('--');
  w('-- Generated from lib/services/demo_campus.dart by');
  w('-- tool/load_into_mysql.dart, so it matches what the app ships.');
  w('--');
  w('-- Differences from the app that are deliberate:');
  w('--   * passwords are bcrypt, not the plaintext the app compares');
  w('--   * accounts.student_id is renamed college_id, because staff have');
  w('--     one too and "student_id" on a staff row is misleading');
  w('--   * tickets.student_id is renamed account_id, because there it is');
  w('--     an integer user id, not a college ID');
  w('--');
  w('-- Load with:  mysql -u root -p < database/queueflow_tmc_seed.sql');
  w('-- ============================================================');
  w('');
  w('CREATE DATABASE IF NOT EXISTS `queueflow_tmc`');
  w('  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');
  w('USE `queueflow_tmc`;');
  w('');
  w('SET FOREIGN_KEY_CHECKS = 0;');
  w('DROP TABLE IF EXISTS `tickets`, `batches`, `announcements`;');
  w('DROP TABLE IF EXISTS `password_reset_codes`, `office_sequences`;');
  w('DROP TABLE IF EXISTS `accounts`, `services`, `stations`, `courses`;');
  w('DROP TABLE IF EXISTS `offices`, `settings`;');
  w('SET FOREIGN_KEY_CHECKS = 1;');
  w('');

  // ---------------- schema ----------------
  w('CREATE TABLE `settings` (');
  w('  `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,');
  w('  `site_name` VARCHAR(120) NOT NULL,');
  w('  `college_name` VARCHAR(160) NOT NULL,');
  w('  `system_description` TEXT NULL,');
  w('  `contact_email` VARCHAR(160) NULL,');
  w('  `contact_number` VARCHAR(40) NULL,');
  w('  `default_waiting_time` SMALLINT UNSIGNED NOT NULL DEFAULT 5,');
  w('  `default_opening_time` TIME NULL,');
  w('  `default_closing_time` TIME NULL,');
  w('  `operational_start_time` TIME NULL,');
  w('  `operational_end_time` TIME NULL,');
  w('  `max_active_tickets_per_student` TINYINT UNSIGNED NOT NULL DEFAULT 1,');
  w('  `max_slots_per_station` SMALLINT UNSIGNED NOT NULL DEFAULT 100,');
  w('  `minimum_students_per_station` SMALLINT UNSIGNED NOT NULL DEFAULT 40,');
  w('  `target_students_per_station` SMALLINT UNSIGNED NOT NULL DEFAULT 60,');
  w('  `supported_courses` JSON NULL,');
  w('  `supported_year_levels` JSON NULL,');
  w('  `maintenance_mode` TINYINT(1) NOT NULL DEFAULT 0,');
  w('  `maintenance_message` TEXT NULL,');
  w('  `ticket_created_notification` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `queue_almost_ready_notification` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `now_serving_notification` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `ticket_completed_notification` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `ticket_cancelled_notification` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `announcement_notification` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `show_system_logo` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `primary_color` CHAR(7) NULL,');
  w('  `secondary_color` CHAR(7) NULL,');
  w('  `accent_color` CHAR(7) NULL,');
  w('  PRIMARY KEY (`id`)');
  w(') ENGINE=InnoDB;');
  w('');

  w('CREATE TABLE `offices` (');
  w('  `id` INT UNSIGNED NOT NULL,');
  w('  `name` VARCHAR(120) NOT NULL,');
  w('  `code` VARCHAR(20) NOT NULL,');
  w('  `description` TEXT NULL,');
  w('  `is_active` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `opening_time` TIME NULL,');
  w('  `closing_time` TIME NULL,');
  w('  `max_slots` SMALLINT UNSIGNED NOT NULL DEFAULT 100,');
  w('  `course` VARCHAR(80) NULL,');
  w('  `course_code` VARCHAR(20) NULL,');
  w('  `window_open` TINYINT(1) NOT NULL DEFAULT 0,');
  w('  `accepting` TINYINT(1) NOT NULL DEFAULT 0,');
  w('  `open_batch` INT UNSIGNED NULL,');
  w('  `active_batch` INT UNSIGNED NULL,');
  w('  `waiting_count` INT UNSIGNED NOT NULL DEFAULT 0,');
  w('  `serving_count` INT UNSIGNED NOT NULL DEFAULT 0,');
  w('  PRIMARY KEY (`id`),');
  w('  UNIQUE KEY `offices_code_unique` (`code`)');
  w(') ENGINE=InnoDB;');
  w('');

  w('CREATE TABLE `courses` (');
  w('  `id` INT UNSIGNED NOT NULL,');
  w('  `code` VARCHAR(20) NOT NULL,');
  w('  `name` VARCHAR(160) NOT NULL,');
  w('  `office_id` INT UNSIGNED NULL,');
  w('  `is_active` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  PRIMARY KEY (`id`),');
  w('  UNIQUE KEY `courses_code_unique` (`code`),');
  w('  KEY `courses_office_id_foreign` (`office_id`),');
  w('  CONSTRAINT `courses_office_id_foreign` FOREIGN KEY (`office_id`)');
  w('    REFERENCES `offices` (`id`) ON DELETE SET NULL');
  w(') ENGINE=InnoDB;');
  w('');

  w('CREATE TABLE `stations` (');
  w('  `id` INT UNSIGNED NOT NULL,');
  w('  `office_id` INT UNSIGNED NOT NULL,');
  w('  `name` VARCHAR(80) NOT NULL,');
  w('  `location` VARCHAR(160) NULL,');
  w('  `course_codes` JSON NULL,');
  w('  `min_daily_students` SMALLINT UNSIGNED NOT NULL DEFAULT 40,');
  w('  `target_daily_students` SMALLINT UNSIGNED NOT NULL DEFAULT 60,');
  w('  `max_daily_slots` SMALLINT UNSIGNED NOT NULL DEFAULT 100,');
  w('  `opening_time` TIME NULL,');
  w('  `closing_time` TIME NULL,');
  w('  `is_active` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `accepts_course` TINYINT(1) NULL,');
  w('  PRIMARY KEY (`id`),');
  w('  KEY `stations_office_id_foreign` (`office_id`),');
  w('  CONSTRAINT `stations_office_id_foreign` FOREIGN KEY (`office_id`)');
  w('    REFERENCES `offices` (`id`) ON DELETE CASCADE');
  w(') ENGINE=InnoDB;');
  w('');

  w('CREATE TABLE `services` (');
  w('  `id` INT UNSIGNED NOT NULL,');
  w('  `office_id` INT UNSIGNED NOT NULL,');
  w('  `station_id` INT UNSIGNED NULL,');
  w('  `name` VARCHAR(120) NOT NULL,');
  w('  `description` TEXT NULL,');
  w('  `requires_course` TINYINT(1) NOT NULL DEFAULT 0,');
  w('  `is_active` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  PRIMARY KEY (`id`),');
  w('  KEY `services_office_id_foreign` (`office_id`),');
  w('  KEY `services_station_id_foreign` (`station_id`),');
  w('  CONSTRAINT `services_office_id_foreign` FOREIGN KEY (`office_id`)');
  w('    REFERENCES `offices` (`id`) ON DELETE CASCADE,');
  w('  CONSTRAINT `services_station_id_foreign` FOREIGN KEY (`station_id`)');
  w('    REFERENCES `stations` (`id`) ON DELETE SET NULL');
  w(') ENGINE=InnoDB;');
  w('');

  w('CREATE TABLE `accounts` (');
  w('  `id` INT UNSIGNED NOT NULL,');
  w('  `name` VARCHAR(160) NOT NULL,');
  w('  `email` VARCHAR(160) NOT NULL,');
  w('  `role` ENUM(\'admin\',\'staff\',\'student\') NOT NULL,');
  w('  -- bcrypt, not the plaintext the app compares against');
  w('  `password` VARCHAR(255) NOT NULL,');
  w('  -- the college ID; the app calls this student_id');
  w('  `college_id` VARCHAR(20) NOT NULL,');
  w('  `course` VARCHAR(80) NULL,');
  w('  `block` VARCHAR(20) NULL,');
  w('  `year_level` VARCHAR(20) NULL,');
  w('  `phone` VARCHAR(30) NULL,');
  w('  `is_active` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `created_at` DATETIME NULL,');
  w('  PRIMARY KEY (`id`),');
  w('  UNIQUE KEY `accounts_email_unique` (`email`),');
  w('  UNIQUE KEY `accounts_college_id_unique` (`college_id`),');
  w('  KEY `accounts_role_index` (`role`)');
  w(') ENGINE=InnoDB;');
  w('');

  w('CREATE TABLE `batches` (');
  w('  `id` INT UNSIGNED NOT NULL,');
  w('  `office_id` INT UNSIGNED NOT NULL,');
  w('  `batch_no` SMALLINT UNSIGNED NOT NULL,');
  w('  -- \'full\' is a real app state: set when the batch hits max_slots');
  w('  `status` ENUM(\'open\',\'full\',\'closed\',\'completed\') NOT NULL DEFAULT \'open\',');
  w('  `max_slots` SMALLINT UNSIGNED NOT NULL DEFAULT 100,');
  w('  `submitted_count` INT UNSIGNED NOT NULL DEFAULT 0,');
  w('  `served_count` INT UNSIGNED NOT NULL DEFAULT 0,');
  w('  `opened_at` DATETIME NULL,');
  w('  `closed_at` DATETIME NULL,');
  w('  `completed_at` DATETIME NULL,');
  w('  PRIMARY KEY (`id`),');
  w('  KEY `batches_office_id_foreign` (`office_id`),');
  w('  CONSTRAINT `batches_office_id_foreign` FOREIGN KEY (`office_id`)');
  w('    REFERENCES `offices` (`id`) ON DELETE CASCADE');
  w(') ENGINE=InnoDB;');
  w('');

  w('CREATE TABLE `tickets` (');
  w('  `id` INT UNSIGNED NOT NULL,');
  w('  `queue_number` VARCHAR(20) NOT NULL,');
  w('  `status` ENUM(\'waiting\',\'serving\',\'served\',\'cancelled\') NOT NULL,');
  w('  -- the app only defines regular and pwd (AppConstants lines 31-32),');
  w('  -- so no speculative members are added here');
  w('  `priority` ENUM(\'regular\',\'pwd\') NOT NULL DEFAULT \'regular\',');
  w('  `purpose` VARCHAR(160) NULL,');
  w('  `year_level` VARCHAR(20) NULL,');
  w('  `has_evidence` TINYINT(1) NOT NULL DEFAULT 0,');
  w('  -- the owning user; the app calls this student_id but it is a user id');
  w('  `account_id` INT UNSIGNED NOT NULL,');
  w('  `office_id` INT UNSIGNED NOT NULL,');
  w('  `service_id` INT UNSIGNED NULL,');
  w('  `station_id` INT UNSIGNED NULL,');
  w('  `course_id` INT UNSIGNED NULL,');
  w('  `batch_id` INT UNSIGNED NULL,');
  w('  `served_by` INT UNSIGNED NULL,');
  w('  `called_at` DATETIME NULL,');
  w('  `served_at` DATETIME NULL,');
  w('  `created_at` DATETIME NULL,');
  w('  PRIMARY KEY (`id`),');
  w('  UNIQUE KEY `tickets_queue_number_unique` (`queue_number`),');
  w('  KEY `tickets_account_id_foreign` (`account_id`),');
  w('  KEY `tickets_office_id_foreign` (`office_id`),');
  w('  KEY `tickets_status_index` (`status`),');
  w('  CONSTRAINT `tickets_account_id_foreign` FOREIGN KEY (`account_id`)');
  w('    REFERENCES `accounts` (`id`) ON DELETE CASCADE,');
  w('  CONSTRAINT `tickets_office_id_foreign` FOREIGN KEY (`office_id`)');
  w('    REFERENCES `offices` (`id`) ON DELETE CASCADE,');
  w('  CONSTRAINT `tickets_served_by_foreign` FOREIGN KEY (`served_by`)');
  w('    REFERENCES `accounts` (`id`) ON DELETE SET NULL');
  w(') ENGINE=InnoDB;');
  w('');

  w('CREATE TABLE `announcements` (');
  w('  `id` INT UNSIGNED NOT NULL,');
  w('  `title` VARCHAR(200) NOT NULL,');
  w('  `body` TEXT NOT NULL,');
  w('  -- Announcement.status defaults to \'published\' and nothing writes');
  w('  -- another value yet, so this is the only member the app produces');
  w('  `status` ENUM(\'published\') NOT NULL DEFAULT \'published\',');
  w('  `is_active` TINYINT(1) NOT NULL DEFAULT 1,');
  w('  `author` VARCHAR(160) NULL,');
  w('  `office` VARCHAR(120) NULL,');
  w('  `created_at` DATETIME NULL,');
  w('  PRIMARY KEY (`id`)');
  w(') ENGINE=InnoDB;');
  w('');

  w('CREATE TABLE `office_sequences` (');
  w('  `code` VARCHAR(20) NOT NULL,');
  w('  `last_number` INT UNSIGNED NOT NULL DEFAULT 0,');
  w('  PRIMARY KEY (`code`)');
  w(') ENGINE=InnoDB;');
  w('');

  w('CREATE TABLE `password_reset_codes` (');
  w('  `college_id` VARCHAR(20) NOT NULL,');
  w('  `code` CHAR(6) NOT NULL,');
  w('  `issued_at` DATETIME NOT NULL,');
  w('  `expires_at` DATETIME NOT NULL,');
  w('  PRIMARY KEY (`college_id`),');
  w('  KEY `password_reset_codes_expires_at_index` (`expires_at`)');
  w(') ENGINE=InnoDB;');
  w('');

  // ---------------- data ----------------
  w('-- ---------------- data ----------------');
  w('');

  insert('settings', <String>[
    'id', 'site_name', 'college_name', 'system_description', 'contact_email',
    'contact_number', 'default_waiting_time', 'default_opening_time',
    'default_closing_time', 'operational_start_time', 'operational_end_time',
    'max_active_tickets_per_station', 'max_slots_per_station',
    'minimum_students_per_station', 'target_students_per_station',
    'supported_courses', 'supported_year_levels', 'maintenance_mode',
    'maintenance_message', 'ticket_created_notification',
    'queue_almost_ready_notification', 'now_serving_notification',
    'ticket_completed_notification', 'ticket_cancelled_notification',
    'announcement_notification', 'show_system_logo', 'primary_color',
    'secondary_color', 'accent_color',
  ], <List<Object?>>[
    <Object?>[
      1,
      settings['site_name'],
      settings['college_name'],
      settings['system_description'],
      settings['contact_email'],
      settings['contact_number'],
      settings['default_waiting_time'],
      settings['default_opening_time'],
      settings['default_closing_time'],
      settings['operational_start_time'],
      settings['operational_end_time'],
      settings['max_active_tickets_per_student'],
      settings['max_slots_per_station'],
      settings['minimum_students_per_station'],
      settings['target_students_per_station'],
      settings['supported_courses'] == null
          ? null
          : "[${(settings['supported_courses'] as List<dynamic>).map((Object? c) => '"$c"').join(',')}]",
      settings['supported_year_levels'] == null
          ? null
          : "[${(settings['supported_year_levels'] as List<dynamic>).map((Object? c) => '"$c"').join(',')}]",
      settings['maintenance_mode'],
      settings['maintenance_message'],
      settings['ticket_created_notification'],
      settings['queue_almost_ready_notification'],
      settings['now_serving_notification'],
      settings['ticket_completed_notification'],
      settings['ticket_cancelled_notification'],
      settings['announcement_notification'],
      settings['show_system_logo'],
      settings['primary_color'],
      settings['secondary_color'],
      settings['accent_color'],
    ],
  ]);

  insert('offices', <String>[
    'id', 'name', 'code', 'description', 'is_active', 'opening_time',
    'closing_time', 'max_slots', 'course', 'course_code', 'window_open',
    'accepting', 'open_batch', 'active_batch', 'waiting_count', 'serving_count',
  ], offices
      .map((Map<String, dynamic> o) => <Object?>[
            o['id'], o['name'], o['code'], o['description'], o['is_active'],
            o['opening_time'], o['closing_time'], o['max_slots'], o['course'],
            o['course_code'], o['window_open'], o['accepting'],
            o['open_batch'], o['active_batch'], o['waiting_count'],
            o['serving_count'],
          ])
      .toList());

  insert('courses', <String>['id', 'code', 'name', 'office_id', 'is_active'],
      courses
          .map((Map<String, dynamic> c) => <Object?>[
                c['id'], c['code'], c['name'], c['office_id'], c['is_active'],
              ])
          .toList());

  insert(
    'stations',
    <String>[
      'id', 'office_id', 'name', 'location', 'course_codes',
      'min_daily_students', 'target_daily_students', 'max_daily_slots',
      'opening_time', 'closing_time', 'is_active', 'accepts_course',
    ],
    stations.map((Map<String, dynamic> s) {
      final List<dynamic> codes = s['course_codes'] as List<dynamic>? ?? <dynamic>[];
      return <Object?>[
        s['id'],
        s['office_id'],
        s['name'],
        s['location'],
        codes.isEmpty ? null : '[${codes.map((Object? c) => '"$c"').join(',')}]',
        s['min_daily_students'],
        s['target_daily_students'],
        s['max_daily_slots'],
        s['opening_time'],
        s['closing_time'],
        s['is_active'],
        s['accepts_course'],
      ];
    }).toList(),
  );

  insert('services', <String>[
    'id', 'office_id', 'station_id', 'name', 'description', 'requires_course',
    'is_active',
  ], services
      .map((Map<String, dynamic> s) => <Object?>[
            s['id'], s['office_id'], s['station_id'], s['name'],
            s['description'], s['requires_course'], s['is_active'],
          ])
      .toList());

  insert('accounts', <String>[
    'id', 'name', 'email', 'role', 'password', 'college_id', 'course', 'block',
    'year_level', 'phone', 'is_active', 'created_at',
  ], accounts
      .map((Map<String, dynamic> a) => <Object?>[
            a['id'],
            a['name'],
            a['email'],
            a['role'],
            _bcrypt['${a['password']}'] ?? '${a['password']}',
            a['student_id'],
            a['course'],
            a['block'],
            a['year_level'],
            a['phone'],
            a['is_active'],
            dq(a['created_at']),
          ])
      .toList());

  insert('batches', <String>[
    'id', 'office_id', 'batch_no', 'status', 'max_slots', 'submitted_count',
    'served_count', 'opened_at', 'closed_at', 'completed_at',
  ], batches
      .map((Map<String, dynamic> b) => <Object?>[
            b['id'], b['office_id'], b['batch_no'], b['status'], b['max_slots'],
            b['submitted_count'], b['served_count'], dq(b['opened_at']),
            dq(b['closed_at']), dq(b['completed_at']),
          ])
      .toList());

  insert('tickets', <String>[
    'id', 'queue_number', 'status', 'priority', 'purpose', 'year_level',
    'has_evidence', 'account_id', 'office_id', 'service_id', 'station_id',
    'course_id', 'batch_id', 'served_by', 'called_at', 'served_at', 'created_at',
  ], tickets
      .map((Map<String, dynamic> t) => <Object?>[
            t['id'], t['queue_number'], t['status'], t['priority'],
            t['purpose'], t['year_level'], t['has_evidence'], t['student_id'],
            t['office_id'], t['service_id'], t['station_id'], t['course_id'],
            t['batch_id'], t['served_by'], dq(t['called_at']), dq(t['served_at']),
            dq(t['created_at']),
          ])
      .toList());

  insert('announcements',
      <String>['id', 'title', 'body', 'status', 'is_active', 'author', 'office', 'created_at'],
      announcements
          .map((Map<String, dynamic> a) => <Object?>[
                a['id'], a['title'], a['body'], a['status'], a['is_active'],
                a['author'], a['office'], dq(a['created_at']),
              ])
          .toList());

  insert('office_sequences', <String>['code', 'last_number'], sequences.entries
      .map((MapEntry<String, dynamic> e) => <Object?>[e.key, e.value])
      .toList());

  w('-- reset codes are intentionally empty: they are short lived and the');
  w('-- app mints them on the device, so none are worth carrying over.');
  w('');

  w('-- ---------------- summary ----------------');
  w('SELECT \'courses\' AS t, COUNT(*) AS n FROM courses');
  w('UNION ALL SELECT \'offices\', COUNT(*) FROM offices');
  w('UNION ALL SELECT \'services\', COUNT(*) FROM services');
  w('UNION ALL SELECT \'stations\', COUNT(*) FROM stations');
  w('UNION ALL SELECT \'accounts\', COUNT(*) FROM accounts');
  w('UNION ALL SELECT \'batches\', COUNT(*) FROM batches');
  w('UNION ALL SELECT \'tickets\', COUNT(*) FROM tickets');
  w('UNION ALL SELECT \'announcements\', COUNT(*) FROM announcements;');
  w('');

  final File out = File('database/queueflow_tmc_seed.sql');
  out.parent.createSync(recursive: true);
  out.writeAsStringSync(_out.toString());

  stdout.writeln('wrote ${out.path}');
  stdout.writeln(
    '  courses ${courses.length}, offices ${offices.length}, '
    'services ${services.length}, stations ${stations.length}',
  );
  stdout.writeln(
    '  accounts ${accounts.length}, batches ${batches.length}, '
    'tickets ${tickets.length}, announcements ${announcements.length}',
  );
}
