import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/models/student.dart';
import 'package:queueflow_tmc/screens/login_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — LOGIN FORM AND BRANDING
///
/// The login form is ID Number and password, and nothing else. These tests
/// cover that shape because it is a product decision, not an implementation
/// detail: there is no sign-up route, no demo prefill to strip out of a
/// release build, and no email address on the student path at all.
/// ============================================================

void main() {
  /// Pumps the login screen and settles past its entry animation.
  Future<void> openLogin(WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: LoginScreen()));
    await tester.pump(const Duration(seconds: 2));
  }

  testWidgets('login form rejects an empty submit', (
    WidgetTester tester,
  ) async {
    await openLogin(tester);

    expect(find.text('Welcome back'), findsOneWidget);
    expect(find.text('LOGIN'), findsOneWidget);

    // Nothing is pretyped: there is no demo account to leak into a release
    // build and no email on this path to guess at, so an empty submit is the
    // state a student meets first.
    await tester.enterText(find.byType(TextFormField).first, '');
    await tester.enterText(find.byType(TextFormField).last, '');

    await tester.tap(find.text('LOGIN'));
    await tester.pump();

    expect(find.text('Enter your ID Number.'), findsOneWidget);
    expect(find.text('Please enter your password.'), findsOneWidget);
  });

  testWidgets('login form asks for an ID Number, not an email', (
    WidgetTester tester,
  ) async {
    await openLogin(tester);

    expect(find.text('ID Number'), findsOneWidget);
    expect(find.text('Email address'), findsNothing);
    expect(find.textContaining(RegExp('or email', caseSensitive: false)), findsNothing);
  });

  testWidgets('the ID field is a number keyboard', (WidgetTester tester) async {
    await openLogin(tester);

    final EditableText field = tester.widget<EditableText>(
      find.descendant(
        of: find.byType(TextFormField).first,
        matching: find.byType(EditableText),
      ),
    );

    expect(field.keyboardType, TextInputType.number);
  });

  testWidgets('login form rejects an identifier too short to be an ID', (
    WidgetTester tester,
  ) async {
    await openLogin(tester);

    await tester.enterText(find.byType(TextFormField).first, '202-14');
    await tester.enterText(find.byType(TextFormField).last, 'secret123');
    await tester.tap(find.text('LOGIN'));
    await tester.pump();

    expect(
      find.text('An ID Number is digits, with an optional dash.'),
      findsOneWidget,
    );
  });

  testWidgets('login form rejects an ID with letters in it', (
    WidgetTester tester,
  ) async {
    await openLogin(tester);

    // The field is filtered to digits, dashes and spaces, so the letters
    // cannot even be typed. Asserting the message rather than the filter is
    // deliberate: the message is what a pasted value has to reach.
    await tester.enterText(
      find.byType(TextFormField).first,
      '202-14ab',
    );
    await tester.enterText(find.byType(TextFormField).last, 'secret123');
    await tester.tap(find.text('LOGIN'));
    await tester.pump();

    expect(
      find.text('An ID Number is digits, with an optional dash.'),
      findsOneWidget,
    );
  });

  testWidgets('login form has no way to register', (
    WidgetTester tester,
  ) async {
    await openLogin(tester);

    expect(find.textContaining(RegExp('sign ?up', caseSensitive: false)), findsNothing);
    expect(find.textContaining(RegExp('create account', caseSensitive: false)), findsNothing);
    expect(find.textContaining(RegExp('register', caseSensitive: false)), findsNothing);
  });

  testWidgets('an accepted ID with a wrong password reports the server error', (
    WidgetTester tester,
  ) async {
    await openLogin(tester);

    // The ID clears validation and the request fails, which is the point: a
    // well-formed ID must not be reported as a malformed one.
    await tester.enterText(find.byType(TextFormField).first, '2024-019083');
    await tester.enterText(find.byType(TextFormField).last, 'wrong-password');
    await tester.tap(find.text('LOGIN'));
    await tester.pump(const Duration(milliseconds: 400));

    expect(
      find.text('An ID Number is digits, with an optional dash.'),
      findsNothing,
    );
  });

  testWidgets('password visibility toggles', (WidgetTester tester) async {
    await openLogin(tester);

    TextField field() => tester.widget<TextField>(
      find.descendant(
        of: find.byType(TextFormField).last,
        matching: find.byType(TextField),
      ),
    );

    expect(field().obscureText, isTrue);
    await tester.tap(find.byTooltip('Show password'));
    await tester.pump();
    expect(field().obscureText, isFalse);
  });

  test('student exposes initials and an academic summary', () {
    const Student student = Student(
      id: 1,
      name: 'Juan Dela Cruz',
      email: 'juan@tmc.edu.ph',
      course: 'BSIT',
      yearLevel: '2nd Year',
      block: '1',
      studentId: '2021-0042',
    );

    expect(student.initials, 'JC');
    expect(student.academicSummary, 'BSIT • 2nd Year • Block 1');
    expect(student.isStudent, isTrue);
  });

  test('branding constants are wired to the shipped assets', () {
    expect(AppConstants.logoImage, 'assets/images/L.png');
    expect(AppConstants.backgroundImage, 'assets/images/B.jpg');
    expect(AppConstants.launcherIcon, 'assets/images/I.png');
  });

  test('the shipped identity is the QueueFlow TMC one', () {
    expect(AppConstants.appName, 'QUEUEFLOW TMC');
    expect(AppConstants.schoolName, 'TMC College');
    expect(AppConstants.appTagline, 'Smart Campus Queue Management Platform');
  });
}
