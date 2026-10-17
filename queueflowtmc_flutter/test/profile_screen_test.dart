import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/app_theme.dart';
import 'package:queueflow_tmc/models/student.dart';
import 'package:queueflow_tmc/repositories/auth_repository.dart';
import 'package:queueflow_tmc/screens/profile_screen.dart';
import 'package:queueflow_tmc/widgets/qf_page.dart';

/// ============================================================
/// QUEUEFLOW TMC — PROFILE IDENTITY LOCKDOWN
///
/// Name and student number are administrator-managed: the edit form
/// must show them read-only and offer no text field that could change
/// them, while the remaining fields stay editable.
/// ============================================================

const Size _phone = Size(390, 844);

const Student _student = Student(
  id: 7,
  name: 'Juan Dela Cruz',
  email: 'juan@tmc.edu.ph',
  course: 'BSIT',
  yearLevel: '2nd Year',
  block: '1',
  studentId: '2021-0042',
);

void main() {
  final AuthRepository auth = AuthRepository.instance;

  tearDown(auth.debugReset);

  Future<void> openProfile(WidgetTester tester) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(student: _student);

    await tester.pumpWidget(
      MaterialApp(theme: AppTheme.dark(), home: const ProfileScreen()),
    );
    await tester.pump(const Duration(seconds: 2));
    expect(tester.takeException(), isNull);
  }

  /// Labels of every editable field on screen.
  List<String> editableLabels(WidgetTester tester) => tester
      .widgetList<QfField>(find.byType(QfField))
      .map((QfField field) => field.label)
      .toList();

  testWidgets('identity rows are visible in read mode', (
    WidgetTester tester,
  ) async {
    await openProfile(tester);

    expect(find.text('Juan Dela Cruz'), findsWidgets);
    expect(find.text('2021-0042'), findsWidgets);
  });

  testWidgets('edit mode shows name and number read-only, never editable', (
    WidgetTester tester,
  ) async {
    await openProfile(tester);

    await tester.tap(find.byTooltip('Edit profile'));
    await tester.pump(const Duration(seconds: 1));

    // Still visible, so the student can verify them.
    expect(find.text('Juan Dela Cruz'), findsWidgets);
    expect(find.text('2021-0042'), findsWidgets);
    expect(find.textContaining('managed by the administrator'), findsOneWidget);

    // But no text field edits them.
    final List<String?> labels = editableLabels(tester);
    expect(labels, isNotEmpty, reason: 'other fields stay editable');
    expect(labels, isNot(contains('Full name')));
    expect(labels, isNot(contains('Student number')));

    // The permitted fields are still editable.
    expect(labels, contains('Email address'));
    expect(labels, contains('Contact number'));
  });
}
