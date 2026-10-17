import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/app_colors.dart';
import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/core/app_theme.dart';
import 'package:queueflow_tmc/core/backdrop.dart';
import 'package:queueflow_tmc/core/glass.dart';
import 'package:queueflow_tmc/core/motion.dart';
import 'package:queueflow_tmc/screens/login_screen.dart';

/// A common Android phone viewport, so layout overflows surface here
/// rather than on a device.
const Size _phone = Size(390, 844);

/// Collapses the transform chain wrapping the logo mark into one matrix,
/// so we can assert on the composed result rather than one layer at a time.
Matrix4 _markTransform(WidgetTester tester) {
  Matrix4 result = Matrix4.identity();

  for (final Element image in find.byType(Image).evaluate()) {
    bool accumulate(Element element) {
      if (element.widget case final Transform transform) {
        result = result * transform.transform;
      }

      return true;
    }

    accumulate(image);
    image.visitAncestorElements(accumulate);
  }

  return result;
}

void main() {
  testWidgets('login screen lays out on a small phone without overflow', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(theme: AppTheme.dark(), home: const LoginScreen()),
    );
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    expect(find.text('QUEUEFLOW TMC'), findsOneWidget);
  });

  testWidgets('login screen survives a very short viewport', (
    WidgetTester tester,
  ) async {
    // Landscape-ish height forces the scroll view to do its job.
    tester.view.physicalSize = const Size(320, 480);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(theme: AppTheme.dark(), home: const LoginScreen()),
    );
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
  });

  testWidgets('logo renders bare, with no plate behind it', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: Center(child: CampusLogo(size: 120))),
      ),
    );
    await tester.pump(const Duration(seconds: 1));

    expect(tester.takeException(), isNull);

    // The mark itself is present and at the requested size.
    expect(
      find.byWidgetPredicate((Widget w) => w is Image && w.image is AssetImage),
      findsOneWidget,
    );
    final Image image = tester.widget<Image>(find.byType(Image));
    expect((image.image as AssetImage).assetName, AppConstants.logoImage);
    expect(image.width, 120);
    expect(image.height, 120);

    // No plate, no clipping, no border around the mark.
    expect(find.byType(ClipRRect), findsNothing);
    expect(find.byType(ClipOval), findsNothing);

    // The mark is a direct, undecorated leaf: nothing wraps it in a
    // background. The halo is a sibling behind it, not a parent of it.
    expect(
      find.ancestor(
        of: find.byType(Image),
        matching: find.byType(DecoratedBox),
      ),
      findsNothing,
    );
    expect(
      find.ancestor(of: find.byType(Image), matching: find.byType(Container)),
      findsNothing,
    );

    // The only decoration present is the circular halo behind the mark.
    final DecoratedBox halo = tester.widget<DecoratedBox>(
      find.byType(DecoratedBox),
    );
    final BoxDecoration decoration = halo.decoration as BoxDecoration;
    expect(decoration.shape, BoxShape.circle);
    expect(decoration.border, isNull);
    expect(decoration.gradient, isA<RadialGradient>());

    // The halo is a sibling of the mark, not a container for it.
    expect(
      find.descendant(
        of: find.byType(DecoratedBox),
        matching: find.byType(Image),
      ),
      findsNothing,
    );
  });

  testWidgets('the logo halo can be switched off', (WidgetTester tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: Center(child: CampusLogo(size: 100, showGlow: false)),
        ),
      ),
    );
    await tester.pump(const Duration(seconds: 1));

    expect(tester.takeException(), isNull);
    expect(find.byType(DecoratedBox), findsNothing);
    expect(find.byType(Image), findsOneWidget);
  });

  testWidgets('logo animates float, breathe and sway over time', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: Center(child: CampusLogo(size: 100))),
      ),
    );
    await tester.pump();

    final Matrix4 first = _markTransform(tester);
    await tester.pump(const Duration(milliseconds: 900));
    final Matrix4 second = _markTransform(tester);
    await tester.pump(const Duration(milliseconds: 900));
    final Matrix4 third = _markTransform(tester);

    // It is actually moving, and not just sitting still.
    expect(first.storage, isNot(second.storage));
    expect(second.storage, isNot(third.storage));

    // Purely decorative transform: nothing collapses to a degenerate matrix.
    for (final Matrix4 m in <Matrix4>[first, second, third]) {
      expect(m.determinant(), greaterThan(0.0));
    }

    // Float stays within its designed band, and never goes upside down.
    for (final Matrix4 m in <Matrix4>[first, second, third]) {
      final double dy = m.getTranslation().y;
      expect(dy.abs(), lessThanOrEqualTo(100 * 0.045 + 0.001));
    }
  });

  testWidgets('campus backdrop and glass card paint cleanly', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark(),
        home: const Scaffold(
          body: CampusBackdrop(
            child: Center(
              child: GlassCard(
                child: Text('glass', textDirection: TextDirection.ltr),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 300));

    expect(tester.takeException(), isNull);
    expect(find.text('glass'), findsOneWidget);
  });

  testWidgets('gradient text accepts two, three and four colours', (
    WidgetTester tester,
  ) async {
    // A stop/colour length mismatch is a paint-time crash, so cover
    // every gradient arity the screens actually use.
    for (final List<Color> colors in <List<Color>>[
      <Color>[Colors.white, AppColors.violet],
      <Color>[Colors.white, AppColors.blue, Colors.white],
      <Color>[Colors.white, AppColors.blue, AppColors.violet, Colors.white],
    ]) {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: GradientText(text: 'QUEUEFLOW TMC', colors: colors),
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 200));

      expect(tester.takeException(), isNull);
    }
  });

  testWidgets('animated counter lands on its target value', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: Center(child: AnimatedCounter(value: 7))),
      ),
    );
    await tester.pump(const Duration(seconds: 1));

    expect(find.text('7'), findsOneWidget);
  });
}
