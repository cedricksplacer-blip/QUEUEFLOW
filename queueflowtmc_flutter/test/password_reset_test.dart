import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/app_theme.dart';
import 'package:queueflow_tmc/repositories/password_reset_controller.dart';
import 'package:queueflow_tmc/screens/confirm_code_screen.dart';
import 'package:queueflow_tmc/screens/forgot_password_screen.dart';
import 'package:queueflow_tmc/screens/new_password_screen.dart';
import 'package:queueflow_tmc/services/campus_backend_factory.dart';
import 'package:queueflow_tmc/services/local_backend.dart';
import 'package:queueflow_tmc/services/remote_backend.dart';

void main() {
  final PasswordResetController reset = PasswordResetController.instance;

  setUp(() {
    // The screens read the transport to decide what to promise about the
    // code, so the campus has to be pinned rather than inherited from the
    // build's dart-defines.
    CampusBackendFactory.instance.overrideForTesting(LocalBackend.instance);
  });

  tearDown(() {
    CampusBackendFactory.instance.overrideForTesting(null);
    reset.debugReset();
  });

  Widget emailHarness() =>
      MaterialApp(theme: AppTheme.dark(), home: const ForgotPasswordScreen());

  Widget codeHarness() =>
      MaterialApp(theme: AppTheme.dark(), home: const ConfirmCodeScreen());

  Widget passwordHarness() =>
      MaterialApp(theme: AppTheme.dark(), home: const NewPasswordScreen());

  Finder field(int index) => find.byType(TextField).at(index);

  group('step 1 — Student ID or email address', () {
    testWidgets('the screen asks for a Student ID or email address', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(emailHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(tester.takeException(), isNull);
      expect(find.text('Continue'), findsOneWidget);
      expect(find.textContaining('Step 1 of 3'), findsOneWidget);
      expect(find.text('Confirm code'), findsNothing);
      expect(find.text('New password'), findsNothing);
    });

    testWidgets('the live API is told the code is emailed', (
      WidgetTester tester,
    ) async {
      CampusBackendFactory.instance.overrideForTesting(RemoteBackend());

      await tester.pumpWidget(emailHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(tester.takeException(), isNull);
      expect(find.text('Continue'), findsOneWidget);

      // And it must not claim the code is written to the device, which is a
      // thing only the demo campus does.
      expect(find.textContaining('writes a 6-digit code'), findsNothing);
      expect(
        find.textContaining('We will email a 6-digit code'),
        findsOneWidget,
      );
    });

    testWidgets('an empty identifier is rejected before any request', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(emailHarness());
      await tester.pump(const Duration(seconds: 2));

      await tester.tap(find.text('Continue'));
      await tester.pump();

      expect(
        find.text('Enter your Student ID or email address.'),
        findsOneWidget,
      );
      expect(reset.codeSent, isFalse);
    });

    testWidgets('an email address can be typed into the field', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(emailHarness());
      await tester.pump(const Duration(seconds: 2));

      await tester.enterText(field(0), 'juan@tmc.edu.ph');
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        tester.widget<TextField>(field(0)).controller?.text,
        'juan@tmc.edu.ph',
      );
    });

    testWidgets('a malformed identifier is rejected before any request', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(emailHarness());
      await tester.pump(const Duration(seconds: 2));

      await tester.enterText(field(0), 'not-an-email@');
      await tester.pump();
      await tester.tap(find.text('Continue'));
      await tester.pump();

      expect(
        find.text('Enter a valid Student ID or email address.'),
        findsOneWidget,
      );
      expect(reset.codeSent, isFalse);
    });

    testWidgets('a Student ID is accepted as an identifier', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(emailHarness());
      await tester.pump(const Duration(seconds: 2));

      await tester.enterText(field(0), '24-019083');
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        tester.widget<TextField>(field(0)).controller?.text,
        '24-019083',
      );
    });

    testWidgets('the field stays editable for corrections', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(emailHarness());
      await tester.pump(const Duration(seconds: 2));

      await tester.enterText(field(0), 'wrong@example.com');
      await tester.pump();
      await tester.enterText(field(0), 'juan@tmc.edu.ph');
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        tester.widget<TextField>(field(0)).controller?.text,
        'juan@tmc.edu.ph',
      );
      expect(reset.codeSent, isFalse);
    });
  });

  group('step 2 — confirm code', () {
    testWidgets('the masked address names the inbox to open', (
      WidgetTester tester,
    ) async {
      reset.debugSeed(identifier: 'juan@tmc.edu.ph');

      await tester.pumpWidget(codeHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(tester.takeException(), isNull);
      expect(find.textContaining('j********@tmc.edu.ph'), findsOneWidget);
      expect(find.text('Verify code'), findsOneWidget);
      expect(find.text('Resend code'), findsOneWidget);
    });

    testWidgets('a Student ID shows the registered inbox label', (
      WidgetTester tester,
    ) async {
      reset.debugSeed(identifier: '24-019083');

      await tester.pumpWidget(codeHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(tester.takeException(), isNull);
      expect(
        find.textContaining('your registered email address'),
        findsOneWidget,
      );
      // The ID itself must not be presented as the destination.
      expect(find.textContaining('24-019083'), findsNothing);
    });

    testWidgets('masking keeps the domain but hides the local part', (
      WidgetTester tester,
    ) async {
      expect(maskEmail('c********@gmail.com'), 'c********@gmail.com');
      expect(maskEmail('juan@tmc.edu.ph'), 'j********@tmc.edu.ph');
      expect(maskEmail('not-an-address'), 'not-an-address');
      expect(maskEmail(null), 'your email address');
      // A Student ID never names the inbox: the code went to the registered
      // address on file, which the server never returns.
      expect(maskIdentifier('24-019083'), 'your registered email address');
      expect(maskIdentifier('juan@tmc.edu.ph'), 'j********@tmc.edu.ph');
      expect(maskIdentifier(null), 'your registered email address');
    });

    testWidgets('the code step never shows a code it was not given', (
      WidgetTester tester,
    ) async {
      // What the remote answer looks like: the address is known, so the
      // step is reachable, but no code was handed back.
      reset.debugSeed(identifier: 'juan@tmc.edu.ph');

      await tester.pumpWidget(codeHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(tester.takeException(), isNull);
      expect(reset.issuedCode, isNull);
      expect(find.text('Tap to fill it in'), findsNothing);

      // Instead it says where to look, so an absent code reads as an emailed
      // one rather than as a broken screen.
      expect(find.textContaining('emailed to it'), findsOneWidget);
    });

    testWidgets('the issued code is shown and tapping it fills the field', (
      WidgetTester tester,
    ) async {
      reset.debugSeed(identifier: 'juan@tmc.edu.ph', code: '483920');

      await tester.pumpWidget(codeHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(tester.takeException(), isNull);

      // The demo campus hands the code back, so it has to be on screen to be
      // usable at all.
      expect(find.text('483920'), findsOneWidget);
      expect(find.text('Tap to fill it in'), findsOneWidget);

      await tester.tap(find.text('483920'));
      await tester.pump();

      // Filled from the same string that was displayed, so the two can never
      // disagree.
      expect(
        tester.widget<TextField>(field(0)).controller?.text,
        '483920',
      );
    });

    testWidgets('no code is shown when none has been issued', (
      WidgetTester tester,
    ) async {
      reset.debugSeed(identifier: 'juan@tmc.edu.ph');

      await tester.pumpWidget(codeHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(find.text('Tap to fill it in'), findsNothing);
    });

    testWidgets('leaving the step keeps the address for the next visit', (
      WidgetTester tester,
    ) async {
      reset.debugSeed(identifier: 'juan@tmc.edu.ph');

      await tester.pumpWidget(codeHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(find.text('Verify code'), findsOneWidget);

      // Replace the screen, which disposes it mid-flow.
      await tester.pumpWidget(const MaterialApp(home: SizedBox.shrink()));
      await tester.pump(const Duration(seconds: 2));

      // The round is forgotten on dispose only when the flow is abandoned
      // from the first screen; here the screens pop, so reopening the code
      // step still names the address.
      expect(reset.identifier, 'juan@tmc.edu.ph');
    });
  });

  group('step 3 — new password', () {
    testWidgets('the screen stays locked without a verified code', (
      WidgetTester tester,
    ) async {
      reset.debugSeed(identifier: 'juan@tmc.edu.ph');

      await tester.pumpWidget(passwordHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(tester.takeException(), isNull);
      expect(find.textContaining('Verify your code first'), findsOneWidget);
      expect(reset.isVerified, isFalse);
    });

    testWidgets('a verified round unlocks the form', (
      WidgetTester tester,
    ) async {
      reset.debugSeed(identifier: 'juan@tmc.edu.ph', token: 'tok-123');

      await tester.pumpWidget(passwordHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(tester.takeException(), isNull);
      expect(find.textContaining('Verify your code first'), findsNothing);
      expect(find.text('Reset password'), findsOneWidget);
      expect(reset.isVerified, isTrue);
    });

    testWidgets('mismatched passwords are caught before any request', (
      WidgetTester tester,
    ) async {
      reset.debugSeed(identifier: 'juan@tmc.edu.ph', token: 'tok-123');

      await tester.pumpWidget(passwordHarness());
      await tester.pump(const Duration(seconds: 2));

      await tester.enterText(field(0), 'newpassword');
      await tester.enterText(field(1), 'newpassword2');
      await tester.tap(find.text('Reset password'));
      await tester.pump();

      expect(find.text('The two passwords do not match.'), findsOneWidget);
      expect(reset.isVerified, isTrue);
    });

    testWidgets('a short password is caught before any request', (
      WidgetTester tester,
    ) async {
      reset.debugSeed(identifier: 'juan@tmc.edu.ph', token: 'tok-123');

      await tester.pumpWidget(passwordHarness());
      await tester.pump(const Duration(seconds: 2));

      await tester.enterText(field(0), 'short');
      await tester.enterText(field(1), 'short');
      await tester.tap(find.text('Reset password'));
      await tester.pump();

      expect(
        find.text('Choose a password of at least 8 characters.'),
        findsOneWidget,
      );
    });

    testWidgets('submitting without verification is refused', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(passwordHarness());
      await tester.pump(const Duration(seconds: 2));

      // Driven directly the way a deep-linked screen would.
      final String? failure = await reset.submitNewPassword(
        password: 'newpassword',
        passwordConfirmation: 'newpassword',
      );

      expect(failure, 'Request a reset code first.');
    });

    testWidgets('submitting with an unverified round is refused', (
      WidgetTester tester,
    ) async {
      reset.debugSeed(identifier: 'juan@tmc.edu.ph');

      final String? failure = await reset.submitNewPassword(
        password: 'newpassword',
        passwordConfirmation: 'newpassword',
      );

      expect(failure, 'Verify your code first.');
    });

    testWidgets('both steps fit a small phone', (WidgetTester tester) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(passwordHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(tester.takeException(), isNull);

      await tester.pumpWidget(codeHarness());
      await tester.pump(const Duration(seconds: 2));

      expect(tester.takeException(), isNull);
    });
  });
}
