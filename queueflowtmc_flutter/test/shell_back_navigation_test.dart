import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/app_theme.dart';
import 'package:queueflow_tmc/widgets/qf_page.dart';

/// A stand-in for the three shell tabs that used to hand [QfPage] a
/// `Navigator.maybePop` callback. There is no route to pop from a shell
/// tab, so the header has to work out its own way back.
class _FakeTab extends StatelessWidget {
  const _FakeTab(this.title);

  final String title;

  @override
  Widget build(BuildContext context) {
    return QfPage(title: title, children: <Widget>[Text(title)]);
  }
}

void main() {
  testWidgets('a shell tab off the home tab goes back to the home tab', (
    WidgetTester tester,
  ) async {
    int index = 2;
    late StateSetter rebuild;

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark(),
        home: StatefulBuilder(
          builder: (BuildContext context, StateSetter setState) {
            rebuild = setState;
            return QfDrawerScope(
              open: () {},
              goToTab: (int target) => rebuild(() => index = target),
              currentTab: index,
              tabLabels: const <String>['Dashboard', 'Statistics', 'History'],
              canSignOut: true,
              onSignOut: () async {},
              child: _FakeTab('History'),
            );
          },
        ),
      ),
    );

    // The tab is not the home tab, so the header offers a way back.
    expect(find.byIcon(Icons.arrow_back_rounded), findsOneWidget);
    // The drawer stays reachable alongside it.
    expect(find.byIcon(Icons.menu_rounded), findsOneWidget);

    await tester.tap(find.byIcon(Icons.arrow_back_rounded));
    await tester.pump(const Duration(milliseconds: 600));

    expect(index, 0, reason: 'back should return to the home tab');
  });

  testWidgets('the home tab opens the drawer instead of going back', (
    WidgetTester tester,
  ) async {
    int opened = 0;
    int index = 0;

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark(),
        home: StatefulBuilder(
          builder: (BuildContext context, StateSetter setState) => QfDrawerScope(
            open: () => setState(() => opened++),
            goToTab: (int target) => setState(() => index = target),
            currentTab: index,
            tabLabels: const <String>['Dashboard', 'Statistics', 'History'],
            canSignOut: true,
            onSignOut: () async {},
            child: _FakeTab('Dashboard'),
          ),
        ),
      ),
    );

    expect(find.byIcon(Icons.arrow_back_rounded), findsNothing);
    expect(find.byIcon(Icons.menu_rounded), findsOneWidget);

    await tester.tap(find.byIcon(Icons.menu_rounded));
    await tester.pump(const Duration(milliseconds: 600));

    expect(opened, 1);
    expect(index, 0, reason: 'nothing to go back to from the home tab');
  });

  testWidgets('a pushed page keeps its own back callback', (
    WidgetTester tester,
  ) async {
    int popped = 0;

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark(),
        home: QfPage(
          title: 'About',
          onBack: () => popped++,
          children: const <Widget>[],
        ),
      ),
    );

    expect(find.byIcon(Icons.arrow_back_rounded), findsOneWidget);
    // No shell, so no drawer button to compete with the arrow.
    expect(find.byIcon(Icons.menu_rounded), findsNothing);

    await tester.tap(find.byIcon(Icons.arrow_back_rounded));
    await tester.pump(const Duration(milliseconds: 600));

    expect(popped, 1);
  });

  testWidgets('a pushed page keeps back even when a shell is above it', (
    WidgetTester tester,
  ) async {
    int popped = 0;

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark(),
        home: QfDrawerScope(
          open: () {},
          goToTab: (_) {},
          currentTab: 0,
          tabLabels: const <String>['Dashboard'],
          canSignOut: true,
          onSignOut: () async {},
          child: QfPage(
            title: 'Settings',
            onBack: () => popped++,
            children: const <Widget>[],
          ),
        ),
      ),
    );

    await tester.tap(find.byIcon(Icons.arrow_back_rounded));
    await tester.pump(const Duration(milliseconds: 600));

    expect(popped, 1, reason: 'an explicit callback wins over tab logic');
  });
}
