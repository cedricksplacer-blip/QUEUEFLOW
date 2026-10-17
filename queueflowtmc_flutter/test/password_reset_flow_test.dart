import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/models/app_user.dart';
import 'package:queueflow_tmc/services/campus_backend.dart';
import 'package:queueflow_tmc/services/demo_campus.dart';
import 'package:queueflow_tmc/services/local_backend.dart';

/// ============================================================
/// QUEUEFLOW TMC — PASSWORD RESET, AGAINST THE REAL CAMPUS
///
/// The widget tests in `password_reset_test.dart` drive the form with
/// `debugSeed`, which skips the backend entirely. These do the opposite:
/// they run the whole request-code -> redeem-code -> sign-in path against a
/// freshly seeded campus in a mocked preference store, so a regression in
/// the local backend cannot hide behind a hand-seeded controller.
///
/// The identifier is a Student ID or an email address, resolved exactly the
/// way the API resolves it, because the login and recovery forms ask for one
/// field and both kinds of account have to be able to get through them.
/// ============================================================
void main() {
  final LocalBackend local = LocalBackend.instance;

  late String studentId;
  late String studentIdDigits;

  setUp(() async {
    // A clean store every time, so a password changed by one test cannot
    // leak into the next.
    SharedPreferences.setMockInitialValues(<String, Object>{});
    local.debugReset();

    await local.ready();

    // Take the ID from the seed rather than hard-coding it, so the test does
    // not break when the generated IDs change.
    final List<AppUser> students = await local.accounts(
      role: AppConstants.roleStudent,
    );

    studentId = students
        .map((AppUser u) => u.studentId)
        .firstWhere((String? id) => id != null && id.isNotEmpty)!;

    // The same ID with its dash stripped, which is how a student may well
    // type it.
    studentIdDigits = studentId.replaceAll('-', '');
  });

  tearDown(local.debugReset);

  test('a code is issued and handed back for the screen to show', () async {
    final LocalResetCode result = await local.requestResetCode(studentId);

    expect(result.code, matches(RegExp(r'^\d{6}$')));
    expect(result.isCoolingDown, isFalse);
    expect(result.retryAfterSeconds, isNull);
    expect(result.expiresInMinutes, 10);
  });

  test('a seeded student signs in with their ID', () async {
    // Guards the switch from email to ID: if the seed ever stops issuing an
    // ID, this fails rather than quietly locking the student out.
    final AppUser signedIn = await local.signIn(
      identifier: studentId,
      password: studentDemoPassword,
    );

    expect(signedIn.studentId, studentId);
    expect(signedIn.role, AppRole.student);
  });

  test('staff and admin can still sign in with their ID', () async {
    // The login form takes an ID and nothing else, so an account without one
    // could never reach the counter it is needed at. Staff are covered too.
    for (final String role in <String>[
      AppConstants.roleStaff,
      AppConstants.roleAdmin,
    ]) {
      final List<AppUser> people = await local.accounts(role: role);

      expect(
        people,
        isNotEmpty,
        reason: 'the seed should contain a $role account',
      );

      for (final AppUser person in people) {
        final String? id = person.studentId;

        expect(
          id,
          isNotNull,
          reason: '${person.name} is a $role and needs an ID to sign in',
        );

        // A deactivated account is refused on purpose, so only the active
        // ones are expected to get through.
        if (!person.isActive) {
          continue;
        }

        final AppUser signedIn = await local.signIn(
          identifier: id!,
          password: role == AppConstants.roleAdmin
              ? adminDemoPassword
              : staffDemoPassword,
        );

        expect(signedIn.role.wire, role);
      }
    }
  });

  test('a deactivated account is refused at sign-in', () async {
    final List<AppUser> staff = await local.accounts(
      role: AppConstants.roleStaff,
    );

    final AppUser inactive = staff.firstWhere((AppUser u) => !u.isActive);

    expect(
      () => local.signIn(
        identifier: inactive.studentId!,
        password: staffDemoPassword,
      ),
      throwsA(
        isA<LocalBackendException>().having(
          (LocalBackendException e) => e.message,
          'message',
          contains('deactivated'),
        ),
      ),
    );
  });

  test('an ID typed without its dash still matches', () async {
    final LocalResetCode result = await local.requestResetCode(studentIdDigits);

    expect(result.code, matches(RegExp(r'^\d{6}$')));

    // Redeeming with the undashed form must find the same code, so a student
    // who left the dash out is not locked out.
    await local.resetPassword(
      identifier: studentIdDigits,
      code: result.code!,
      password: 'brandnewpass',
    );
  });

  test('an unknown ID is refused', () async {
    expect(
      () => local.requestResetCode('2025-99999'),
      throwsA(
        isA<LocalBackendException>().having(
          (LocalBackendException e) => e.message,
          'message',
          contains('No account uses that ID'),
        ),
      ),
    );
  });

  test('an ID of the wrong length is refused', () async {
    expect(
      () => local.requestResetCode('12345'),
      throwsA(isA<LocalBackendException>()),
    );
  });

  test('an email address resolves the account that owns it', () async {
    // Staff and administrators sign in with an email address rather than a
    // Student ID, and the login form asks for one field. A campus that
    // resolved only IDs would leave them unable to sign in at all, so this
    // pins the email half of `findByIdentifier`.
    final List<AppUser> staff = await local.accounts(
      role: AppConstants.roleStaff,
    );

    final AppUser person = staff.firstWhere(
      (AppUser u) => u.isActive && u.email.contains('@'),
    );

    final AppUser signedIn = await local.signIn(
      identifier: person.email,
      password: staffDemoPassword,
    );

    expect(signedIn.id, person.id);

    // And it works on the reset path, because that form asks for the same
    // identifier a student would type into the login form.
    final ResetCodeIssued issued = await local.requestResetCode(person.email);

    expect(issued.code, isNotNull);
  });

  test('an email is matched however its case is typed', () async {
    final List<AppUser> students = await local.accounts(
      role: AppConstants.roleStudent,
    );

    final String email = students.first.email;

    final AppUser signedIn = await local.signIn(
      identifier: email.toUpperCase(),
      password: studentDemoPassword,
    );

    expect(signedIn.email, email);
  });

  test('an identifier that belongs to nobody is refused', () async {
    expect(
      () => local.signIn(
        identifier: 'nobody@tmc.edu.ph',
        password: studentDemoPassword,
      ),
      throwsA(
        isA<LocalBackendException>().having(
          (LocalBackendException e) => e.message,
          'message',
          contains('do not match'),
        ),
      ),
    );
  });

  test('the new password signs in and the old one stops working', () async {
    final LocalResetCode issued = await local.requestResetCode(studentId);

    await local.resetPassword(
      identifier: studentId,
      code: issued.code!,
      password: 'brandnewpass',
    );

    // The whole point of the flow: the new password opens the app.
    final AppUser signedIn = await local.signIn(
      identifier: studentId,
      password: 'brandnewpass',
    );

    expect(signedIn.studentId, studentId);

    // And the old one is dead, so the reset is not cosmetic.
    expect(
      () => local.signIn(identifier: studentId, password: studentDemoPassword),
      throwsA(isA<LocalBackendException>()),
    );
  });

  test('the new password survives a restart', () async {
    final LocalResetCode issued = await local.requestResetCode(studentId);

    await local.resetPassword(
      identifier: studentId,
      code: issued.code!,
      password: 'brandnewpass',
    );

    // Drop the in-memory document entirely: the next ready() has to read the
    // new password back off disk, not out of the cached map.
    local.debugReset();
    await local.ready();

    final AppUser signedIn = await local.signIn(
      identifier: studentId,
      password: 'brandnewpass',
    );

    expect(signedIn.studentId, studentId);
  });

  test('a code can only be used once', () async {
    final LocalResetCode issued = await local.requestResetCode(studentId);

    await local.resetPassword(
      identifier: studentId,
      code: issued.code!,
      password: 'brandnewpass',
    );

    expect(
      () => local.resetPassword(
        identifier: studentId,
        code: issued.code!,
        password: 'secondattempt',
      ),
      throwsA(
        isA<LocalBackendException>().having(
          (LocalBackendException e) => e.message,
          'message',
          contains('not valid or has expired'),
        ),
      ),
    );
  });

  test('a wrong code is refused and leaves the ID recoverable', () async {
    final LocalResetCode issued = await local.requestResetCode(studentId);

    expect(
      () => local.resetPassword(
        identifier: studentId,
        code: issued.code == '000000' ? '111111' : '000000',
        password: 'brandnewpass',
      ),
      throwsA(isA<LocalBackendException>()),
    );

    // A typo must not burn the code: the student can just retype it.
    await local.resetPassword(
      identifier: studentId,
      code: issued.code!,
      password: 'brandnewpass',
    );

    final AppUser signedIn = await local.signIn(
      identifier: studentId,
      password: 'brandnewpass',
    );

    expect(signedIn.studentId, studentId);
  });

  test('a short new password is refused and the code stays usable', () async {
    final LocalResetCode issued = await local.requestResetCode(studentId);

    expect(
      () => local.resetPassword(
        identifier: studentId,
        code: issued.code!,
        password: 'short',
      ),
      throwsA(
        isA<LocalBackendException>().having(
          (LocalBackendException e) => e.message,
          'message',
          contains('at least 8 characters'),
        ),
      ),
    );

    await local.resetPassword(
      identifier: studentId,
      code: issued.code!,
      password: 'brandnewpass',
    );
  });

  test('a second request inside the cool-down is held back', () async {
    final LocalResetCode first = await local.requestResetCode(studentId);
    final LocalResetCode second = await local.requestResetCode(studentId);

    expect(second.isCoolingDown, isTrue);
    expect(second.retryAfterSeconds, greaterThan(0));
    expect(second.retryAfterSeconds, lessThanOrEqualTo(60));

    // It must hand back the code already in flight rather than a new one, so
    // a student who taps twice is not left holding a dead code.
    expect(second.code, first.code);
  });
}
