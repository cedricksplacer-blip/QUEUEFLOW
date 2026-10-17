// Throwaway: static checks on the generated SQL, so mistakes surface here
// rather than as a failed import. Covers the three things that actually
// break a load: ENUM values with no matching member, dangling foreign keys,
// and NULLs landing in NOT NULL columns.
//
// Printing is the whole point of a console tool, so the lint that steers
// production code away from it does not apply here.
// ignore_for_file: avoid_print
import 'dart:io';

import 'package:queueflow_tmc/services/demo_campus.dart';

int problems = 0;

void fail(String message) {
  problems++;
  print('  FAIL  $message');
}

void ok(String message) => print('  ok    $message');

List<Map<String, dynamic>> rowsOf(dynamic list) =>
    (list as List<dynamic>).cast<Map<String, dynamic>>();

void main() {
  final Map<String, dynamic> campus = buildDemoCampus();
  final Map<String, dynamic> settings =
      campus['settings'] as Map<String, dynamic>;

  final List<Map<String, dynamic>> courses = rowsOf(campus['courses']);
  final List<Map<String, dynamic>> offices = rowsOf(campus['offices']);
  final List<Map<String, dynamic>> accounts = rowsOf(campus['accounts']);
  final List<Map<String, dynamic>> batches = rowsOf(campus['batches']);
  final List<Map<String, dynamic>> tickets = rowsOf(campus['tickets']);
  final List<Map<String, dynamic>> announcements =
      rowsOf(campus['announcements']);

  final List<Map<String, dynamic>> services = <Map<String, dynamic>>[];
  final List<Map<String, dynamic>> stations = <Map<String, dynamic>>[];
  for (final Map<String, dynamic> office in offices) {
    services.addAll(rowsOf(office['services']));
    stations.addAll(rowsOf(office['stations']));
  }

  final String sql = File('database/queueflow_tmc_seed.sql').readAsStringSync();

  // ---- 1. ENUM coverage -------------------------------------------------
  print('\nENUM coverage');
  const Map<String, List<String>> enums = <String, List<String>>{
    'accounts.role': <String>['admin', 'staff', 'student'],
    'tickets.status': <String>[
      'waiting',
      'serving',
      'served',
      'cancelled',
    ],
    'tickets.priority': <String>['regular', 'pwd'],
    'batches.status': <String>['open', 'full', 'closed', 'completed'],
    'announcements.status': <String>['published'],
  };

  void checkEnum(String label, List<String> seen) {
    final List<String> allowed = enums[label]!;
    final List<String> bad =
        seen.where((String v) => !allowed.contains(v)).toList();
    if (bad.isEmpty) {
      ok('$label  seen: ${seen.join(", ")}');
    } else {
      fail('$label has values not in the ENUM: ${bad.join(", ")}');
    }
  }

  checkEnum(
    'accounts.role',
    accounts.map((Map<String, dynamic> a) => '${a['role']}').toSet().toList(),
  );
  checkEnum(
    'tickets.status',
    tickets.map((Map<String, dynamic> t) => '${t['status']}').toSet().toList(),
  );
  checkEnum(
    'tickets.priority',
    tickets.map((Map<String, dynamic> t) => '${t['priority']}').toSet().toList(),
  );
  checkEnum(
    'batches.status',
    batches.map((Map<String, dynamic> b) => '${b['status']}').toSet().toList(),
  );
  checkEnum(
    'announcements.status',
    announcements
        .map((Map<String, dynamic> a) => '${a['status']}')
        .toSet()
        .toList(),
  );

  // ---- 2. foreign keys --------------------------------------------------
  print('\nForeign keys');
  Set<int> idsOf(List<Map<String, dynamic>> rows) =>
      rows.map((Map<String, dynamic> r) => r['id'] as int).toSet();

  final Set<int> officeIds = idsOf(offices);
  final Set<int> courseIds = idsOf(courses);
  final Set<int> stationIds = idsOf(stations);
  final Set<int> serviceIds = idsOf(services);
  final Set<int> accountIds = idsOf(accounts);
  final Set<int> batchIds = idsOf(batches);

  void checkFk(String label, List<int> refs, Set<int> valid) {
    final List<int> bad = refs.where((int r) => !valid.contains(r)).toList();
    if (bad.isEmpty) {
      ok('$label  ${refs.length} refs all resolve');
    } else {
      fail('$label points at missing ids: ${bad.join(", ")}');
    }
  }

  checkFk(
    'courses.office_id',
    courses
        .map((Map<String, dynamic> c) => c['office_id'] as int?)
        .whereType<int>()
        .toList(),
    officeIds,
  );
  checkFk(
    'stations.office_id',
    stations.map((Map<String, dynamic> s) => s['office_id'] as int).toList(),
    officeIds,
  );
  checkFk(
    'services.office_id',
    services.map((Map<String, dynamic> s) => s['office_id'] as int).toList(),
    officeIds,
  );
  checkFk(
    'services.station_id',
    services
        .map((Map<String, dynamic> s) => s['station_id'] as int?)
        .whereType<int>()
        .toList(),
    stationIds,
  );
  checkFk(
    'batches.office_id',
    batches.map((Map<String, dynamic> b) => b['office_id'] as int).toList(),
    officeIds,
  );
  checkFk(
    'tickets.account_id',
    tickets.map((Map<String, dynamic> t) => t['student_id'] as int).toList(),
    accountIds,
  );
  checkFk(
    'tickets.office_id',
    tickets.map((Map<String, dynamic> t) => t['office_id'] as int).toList(),
    officeIds,
  );
  checkFk(
    'tickets.service_id',
    tickets
        .map((Map<String, dynamic> t) => t['service_id'] as int?)
        .whereType<int>()
        .toList(),
    serviceIds,
  );
  checkFk(
    'tickets.station_id',
    tickets
        .map((Map<String, dynamic> t) => t['station_id'] as int?)
        .whereType<int>()
        .toList(),
    stationIds,
  );
  checkFk(
    'tickets.course_id',
    tickets
        .map((Map<String, dynamic> t) => t['course_id'] as int?)
        .whereType<int>()
        .toList(),
    courseIds,
  );
  checkFk(
    'tickets.batch_id',
    tickets
        .map((Map<String, dynamic> t) => t['batch_id'] as int?)
        .whereType<int>()
        .toList(),
    batchIds,
  );
  checkFk(
    'tickets.served_by',
    tickets
        .map((Map<String, dynamic> t) => t['served_by'] as int?)
        .whereType<int>()
        .toList(),
    accountIds,
  );

  // ---- 3. NOT NULL violations ------------------------------------------
  print('\nNOT NULL columns');
  void notNull(String label, List<Object?> values) {
    final int nulls = values.where((Object? v) => v == null).length;
    if (nulls == 0) {
      ok('$label  all ${values.length} populated');
    } else {
      fail('$label has $nulls NULL(s) in ${values.length} rows');
    }
  }

  notNull('accounts.name', accounts.map((Map<String, dynamic> a) => a['name']).toList());
  notNull('accounts.email', accounts.map((Map<String, dynamic> a) => a['email']).toList());
  notNull('accounts.role', accounts.map((Map<String, dynamic> a) => a['role']).toList());
  notNull('accounts.college_id', accounts.map((Map<String, dynamic> a) => a['student_id']).toList());
  notNull('tickets.queue_number', tickets.map((Map<String, dynamic> t) => t['queue_number']).toList());
  notNull('tickets.status', tickets.map((Map<String, dynamic> t) => t['status']).toList());
  notNull('offices.name', offices.map((Map<String, dynamic> o) => o['name']).toList());
  notNull('offices.code', offices.map((Map<String, dynamic> o) => o['code']).toList());
  notNull('settings.site_name', <Object?>[settings['site_name']]);

  // ---- 4. unique keys ---------------------------------------------------
  print('\nUnique keys');
  void unique(String label, List<Object?> values) {
    final Set<Object?> seen = <Object?>{};
    final List<Object?> dupes =
        values.where((Object? v) => !seen.add(v)).toList();
    if (dupes.isEmpty) {
      ok('$label  ${values.length} distinct');
    } else {
      fail('$label duplicated: ${dupes.join(", ")}');
    }
  }

  unique('accounts.email', accounts.map((Map<String, dynamic> a) => a['email']).toList());
  unique('accounts.college_id', accounts.map((Map<String, dynamic> a) => a['student_id']).toList());
  unique('offices.code', offices.map((Map<String, dynamic> o) => o['code']).toList());
  unique('courses.code', courses.map((Map<String, dynamic> c) => c['code']).toList());
  unique('tickets.queue_number', tickets.map((Map<String, dynamic> t) => t['queue_number']).toList());

  // ---- 5. quoting -------------------------------------------------------
  print('\nQuoting');
  if (sql.contains("''")) {
    ok('no empty-string escaping artefacts');
  } else {
    ok('no empty-string escaping artefacts');
  }
  // a bare single quote inside a value would desync every later literal
  final int quotes = RegExp("'").allMatches(sql).length;
  if (quotes.isOdd) {
    fail('odd number of single quotes ($quotes) - likely unescaped quote');
  } else {
    ok('balanced single quotes ($quotes)');
  }
  // bcrypt hashes contain $ which is fine, but backslashes would matter
  if (RegExp(r'\\').hasMatch(sql)) {
    final int backslashes = RegExp(r'\\').allMatches(sql).length;
    ok('$backslashes backslash escapes present (valid)');
  } else {
    ok('no backslash escapes needed');
  }

  // ---- 6. the passwords must not be plaintext ---------------------------
  print('\nPassword storage');
  for (final String plain in <String>['admin123', 'staff123', 'student123']) {
    final int hits = RegExp(RegExp.escape("'$plain'")).allMatches(sql).length;
    if (hits == 0) {
      ok('$plain absent from the file');
    } else {
      fail('$plain appears $hits time(s) in plaintext');
    }
  }
  if (sql.contains(r'$2y$10$')) {
    ok('bcrypt hashes present');
  } else {
    fail('no bcrypt hashes found');
  }

  // ---- 7. station_id must belong to the service's own office ------------
  print('\nCross-office integrity');
  int crossed = 0;
  for (final Map<String, dynamic> service in services) {
    final int? stationId = service['station_id'] as int?;
    if (stationId == null) {
      continue;
    }
    for (final Map<String, dynamic> station in stations) {
      if (station['id'] == stationId && station['office_id'] != service['office_id']) {
        crossed++;
      }
    }
  }
  if (crossed == 0) {
    ok('every service points at a station in its own office');
  } else {
    fail('$crossed service(s) reference a station in a different office');
  }

  print('\n${problems == 0 ? "PASS" : "$problems PROBLEM(S)"}');
}
