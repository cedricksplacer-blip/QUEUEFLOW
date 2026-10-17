import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/models/app_user.dart';
import 'package:queueflow_tmc/models/operations.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/models/student.dart';
import 'package:queueflow_tmc/repositories/auth_repository.dart';

/// ============================================================
/// QUEUEFLOW TMC — ROLE AND CATALOGUE CONTRACT
///
/// This build serves students only. Two things follow from that, and both are
/// asserted here rather than left to the absence of the screens:
///
///   1. A session the server accepted for a staff member or an administrator
///      is still refused, because landing on a student dashboard that 403s on
///      every action is a worse outcome than being told to use another console.
///
///   2. The office list is the app's contract, not the database's. The server
///      is free to publish other offices; none of them reach a student.
///
/// The `AppRole` and `AppUser` groups survive from the old routing test on
/// purpose: the roles still arrive over the wire and still have to parse
/// correctly, because the refusal above depends on parsing them correctly.
/// ============================================================

void main() {
  final AuthRepository auth = AuthRepository.instance;

  tearDown(auth.debugReset);

  group('AppRole', () {
    test('resolves every wire value the users table stores', () {
      expect(AppRole.fromWire('admin'), AppRole.admin);
      expect(AppRole.fromWire('staff'), AppRole.staff);
      expect(AppRole.fromWire('student'), AppRole.student);
    });

    test('tolerates casing and stray whitespace from the API', () {
      expect(AppRole.fromWire('  Admin '), AppRole.admin);
      expect(AppRole.fromWire('STAFF'), AppRole.staff);
    });

    test('falls back to unknown rather than guessing a role', () {
      // Guessing here would hand an unrecognised account a shell it is not
      // entitled to, so an unknown value has to stay unknown.
      expect(AppRole.fromWire('superuser'), AppRole.unknown);
      expect(AppRole.fromWire(null), AppRole.unknown);
      expect(AppRole.fromWire(''), AppRole.unknown);
    });

    test('knows which roles this app serves', () {
      expect(AppRole.student.isStudent, isTrue);
      expect(AppRole.staff.isStudent, isFalse);
      expect(AppRole.admin.isStudent, isFalse);
      expect(AppRole.unknown.isStudent, isFalse);
    });

    test(
      'still marks who may work a counter, even though no console exists',
      () {
        // The flag is not used to render anything in this build. It is kept so a
        // staff token is refused on the grounds of role rather than on the
        // grounds of a missing screen.
        expect(AppRole.admin.canOperateQueue, isTrue);
        expect(AppRole.staff.canOperateQueue, isTrue);
        expect(AppRole.student.canOperateQueue, isFalse);
        expect(AppRole.unknown.canOperateQueue, isFalse);
      },
    );
  });

  group('AppUser', () {
    test('keeps the academic summary for a student', () {
      final AppUser user = AppUser.fromMap(<String, dynamic>{
        'id': 3,
        'name': 'Juan Dela Cruz',
        'email': 'juan@tmc.edu.ph',
        'role': 'student',
        'course': 'BSIT',
        'year_level': '2nd Year',
        'block': '1',
      });

      expect(user.identitySummary, 'BSIT • 2nd Year • Block 1');
    });

    test('projects onto the student model without losing the role', () {
      const AppUser staff = AppUser(
        id: 9,
        name: 'Ramon Cruz',
        email: 'ramon@tmc.edu.ph',
        role: AppRole.staff,
      );
      final Student projected = staff.asStudent;

      expect(projected.name, 'Ramon Cruz');
      // Carried through deliberately: if this is ever inspected through
      // student code, it must not look like a student account.
      expect(projected.role, 'staff');
      expect(projected.isStudent, isFalse);
    });

    test('round-trips through toMap for the session cache', () {
      const AppUser original = AppUser(
        id: 11,
        name: 'Ana Reyes',
        email: 'ana@tmc.edu.ph',
        role: AppRole.student,
        phone: '09171234567',
      );

      final AppUser decoded = AppUser.fromMap(original.toMap());

      expect(decoded.role, AppRole.student);
      expect(decoded.name, 'Ana Reyes');
      expect(decoded.phone, '09171234567');
    });
  });

  group('AuthRepository role gating', () {
    test('is not signed in for a role this build does not serve', () {
      auth.debugSeedOperator(role: AppRole.unknown, name: 'Mystery Account');

      expect(auth.isSignedIn, isFalse);
    });

    test('refuses a staff session even though the server accepted it', () {
      auth.debugSeedOperator(role: AppRole.staff, name: 'Ramon Cruz');

      // debugSeedOperator sets the status to signedIn on purpose, so this can
      // only pass because the role is what refuses it.
      expect(auth.status, AuthStatus.signedIn);
      expect(auth.role, AppRole.staff);
      expect(auth.isSignedIn, isFalse);
    });

    test('refuses an administrator session for the same reason', () {
      auth.debugSeedOperator(role: AppRole.admin, name: 'Maria Santos');

      expect(auth.status, AuthStatus.signedIn);
      expect(auth.isAdmin, isTrue);
      expect(auth.isSignedIn, isFalse);
    });

    test('signs a student in and shows them their own record', () {
      const Student student = Student(
        id: 1,
        name: 'Juan Dela Cruz',
        email: 'juan@tmc.edu.ph',
      );

      auth.debugSeed(student: student);

      expect(auth.isSignedIn, isTrue);
      expect(auth.role, AppRole.student);
      expect(auth.isStudent, isTrue);
      expect(auth.student, isNotNull);
      expect(auth.student!.name, 'Juan Dela Cruz');
    });

    test(
      'has a sentence for an account that signed in but is not a student',
      () {
        // The message is what the login screen shows; an empty string here would
        // mean a silent rejection.
        expect(AuthRepository.notAStudent, isNotEmpty);
        expect(AuthRepository.notAStudent.toLowerCase(), contains('students'));
      },
    );
  });

  group('office allow-list', () {
    test('names exactly the six approved offices', () {
      expect(AppConstants.queueOffices, <String>[
        'Registrar',
        'Cashier',
        'Admissions',
        'SASO',
        'Scholarship Office',
        'School Clinic',
      ]);
    });

    test('accepts an approved name regardless of casing or padding', () {
      expect(AppConstants.isQueueOffice('  registrar '), isTrue);
      expect(AppConstants.isQueueOffice('SCHOLARSHIP OFFICE'), isTrue);
    });

    test('rejects every office that is not on the list', () {
      expect(AppConstants.isQueueOffice('Guidance Office'), isFalse);
      expect(AppConstants.isQueueOffice('Library'), isFalse);
      // A Registrar per-course row is still the Registrar, which is one of
      // the six windows; it is not a reason to reject the office.
      expect(AppConstants.isQueueOffice('Registrar - BSIT'), isTrue);
      expect(AppConstants.isQueueOffice(''), isFalse);
    });

    test('ranks offices in the order they should be offered', () {
      expect(
        AppConstants.queueOfficeRank('Registrar'),
        lessThan(AppConstants.queueOfficeRank('Cashier')),
      );
      expect(
        AppConstants.queueOfficeRank('Cashier'),
        lessThan(AppConstants.queueOfficeRank('Admissions')),
      );
      expect(
        AppConstants.queueOfficeRank('School Clinic'),
        lessThan(AppConstants.queueOfficeRank('Nonexistent Office')),
      );
    });
  });

  group('OfficeQueue', () {
    test(
      'derives the waiting count from the list when the server omits it',
      () {
        final OfficeQueue queue = OfficeQueue.fromMap(<String, dynamic>{
          'office': <String, dynamic>{'id': 2, 'name': 'Registrar'},
          'waiting': <Map<String, dynamic>>[
            <String, dynamic>{'id': 1, 'queue_number': 'A-001'},
            <String, dynamic>{'id': 2, 'queue_number': 'A-002'},
          ],
        });

        // A banner that disagrees with the rows under it is worse than no
        // banner, so the list wins.
        expect(queue.waitingCount, 2);
        expect(queue.officeName, 'Registrar');
        expect(queue.isIdle, isTrue);
      },
    );

    test('calls PWD tickets ahead of the regular line', () {
      final OfficeQueue queue = OfficeQueue.fromMap(<String, dynamic>{
        'office_id': 1,
        'waiting': <Map<String, dynamic>>[
          <String, dynamic>{
            'id': 1,
            'queue_number': 'A-001',
            'created_at': '2026-01-01 08:00:00',
          },
          <String, dynamic>{
            'id': 2,
            'queue_number': 'A-002',
            'priority': 'pwd',
            'created_at': '2026-01-01 09:00:00',
          },
        ],
      });

      final List<QueueTicket> order = queue.callOrder;

      expect(order.first.queueNumber, 'A-002');
      expect(order.last.queueNumber, 'A-001');
    });

    test('reports the next waiting ticket, or nothing when drained', () {
      final OfficeQueue empty = OfficeQueue.fromMap(<String, dynamic>{
        'office_id': 1,
      });
      expect(empty.nextWaiting, isNull);

      final OfficeQueue busy = OfficeQueue.fromMap(<String, dynamic>{
        'office_id': 1,
        'waiting': <Map<String, dynamic>>[
          <String, dynamic>{'id': 5, 'queue_number': 'B-007'},
        ],
      });
      expect(busy.nextWaiting?.queueNumber, 'B-007');
    });
  });
}
