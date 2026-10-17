import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/app_constants.dart';
import '../core/glass.dart';
import '../core/motion.dart';
import '../models/queue_ticket.dart';
import '../models/student.dart';
import '../repositories/auth_repository.dart';
import '../services/notification_router.dart';
import '../widgets/cancelled_ticket_dialog.dart';
import '../widgets/connection_status.dart';
import '../widgets/generate_ticket_sheet.dart';
import '../widgets/qf_page.dart';
import 'about_screen.dart';
import 'announcements_screen.dart';
import 'dashboard_screen.dart';
import 'history_screen.dart';
import 'my_ticket_screen.dart';
import 'new_ticket_screen.dart';
import 'notifications_screen.dart';
import 'profile_screen.dart';
import 'queue_stats_screen.dart';
import 'settings_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — HOME SHELL
///
/// Holds the four pages a student moves between constantly (dashboard,
/// statistics, history, profile) in an [IndexedStack] so the bottom bar and the
/// drawer both reach all of them and none of them lose their scroll position or
/// their polling timers on a tab switch.
///
/// The bottom bar carries those four because they are the ones a student comes
/// back to; the drawer carries everything, grouped, because the drawer is also
/// where "sign out" lives and a destructive action does not belong on a bar
/// four taps wide.
///
/// Everything outside that set — taking a ticket, viewing it, notifications,
/// announcements, settings, about — is a one-shot task, so it is pushed as a
/// normal route and shows a back button instead of a menu button.
///
/// The distinction matters for the polling screens: an IndexedStack child stays
/// alive while hidden, which is why only the screens that poll on a timer are
/// tabbed, and even those pause their timers when the app is backgrounded.
///
/// A [ConnectionBanner] sits above the tabs for the whole shell rather than on
/// each page, so the connection state is stated once and consistently no matter
/// which tab is open - and so a student who is already looking at a cached
/// ticket while offline is told why, without any page having to remember to say
/// so.
/// ============================================================

class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  final GlobalKey<ScaffoldState> _scaffold = GlobalKey<ScaffoldState>();
  final AuthRepository _auth = AuthRepository.instance;

  /// Index into [_homeTabs].
  int _index = 0;

  @override
  void initState() {
    super.initState();
    _auth.addListener(_onAuthChanged);
    NotificationRouter.instance.addListener(_onNotificationTap);
    // A tap that launched a terminated app was queued before this shell
    // existed; open it once the first frame is down.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _openPendingNotification();
    });
  }

  @override
  void dispose() {
    _auth.removeListener(_onAuthChanged);
    NotificationRouter.instance.removeListener(_onNotificationTap);
    super.dispose();
  }

  void _onAuthChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  void _openDrawer() {
    // The dashboard is what a student lands on after signing in, and it is
    // also where the session is most likely to have just died.
    if (!_auth.isSignedIn) {
      _index = 0;
    }

    _scaffold.currentState?.openDrawer();
  }

  Future<void> _confirmSignOut() async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        backgroundColor: AppColors.primary,
        title: const Text(
          'Sign out?',
          style: TextStyle(color: Colors.white, fontSize: 17),
        ),
        content: Text(
          'You will need your ID Number and password to sign back in.',
          style: TextStyle(
            color: Colors.white.withValues(alpha: 0.70),
            fontSize: 13,
            height: 1.5,
          ),
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: Text(
              'Stay signed in',
              style: TextStyle(color: Colors.white.withValues(alpha: 0.75)),
            ),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Sign out'),
          ),
        ],
      ),
    );

    if (confirmed ?? false) {
      await _auth.signOut();
    }
  }

  void _selectTab(int index) {
    setState(() => _index = index);
    Navigator.of(context).pop();
  }

  /// Switches tab from inside a page, without the drawer being open.
  void _goToTab(int index) {
    if (index < 0 || index >= _homeTabs.length) {
      return;
    }

    setState(() => _index = index);
  }

  /// Opens the screen for a tapped system notification, if any is pending.
  ///
  /// Ticket lifecycle events open My Ticket; announcements open the
  /// announcements screen. Anything else (or no signed-in session) consumes
  /// the tap silently: the app is already open, which is all the tap
  /// promised. Consumed exactly once — a single tap never pushes twice,
  /// even if the router notifies while a post-frame check is also due.
  void _onNotificationTap() {
    _openPendingNotification();
  }

  void _openPendingNotification() {
    if (!mounted || !_auth.isSignedIn) {
      NotificationRouter.instance.consume();
      return;
    }

    final String? payload = NotificationRouter.instance.consume();
    if (payload == null) {
      return;
    }

    switch (destinationForPayload(payload)) {
      case NotificationDestination.ticket:
        Navigator.of(context).push<void>(
          QfPageRoute<void>(page: const MyTicketScreen()),
        );
      case NotificationDestination.announcements:
        Navigator.of(context).push<void>(
          QfPageRoute<void>(page: const AnnouncementsScreen()),
        );
      case NotificationDestination.none:
        break;
    }
  }

  Future<void> _push(WidgetBuilder page) async {
    // Close the drawer first so the pushed route is not built behind it.
    Navigator.of(context).pop();

    await Navigator.of(
      context,
    ).push<void>(QfPageRoute<void>(page: page(context)));

    // A push may have changed the ticket or the profile, so the tabs are
    // showing stale data until the repository is re-read.
    if (mounted) {
      await _auth.refresh();
    }
  }

  /// Opens the "Generate ticket" popup from the drawer.
  Future<void> _takeTicket() async {
    // Close the drawer first so the sheet is not built behind it.
    Navigator.of(context).pop();

    await showGenerateTicketSheet(context);

    // Taking a ticket changes the dashboard, so the tabs are re-read.
    if (mounted) {
      await _auth.refresh();
    }
  }

  @override
  Widget build(BuildContext context) {
    return QfDrawerScope(
      open: _openDrawer,
      goToTab: _goToTab,
      currentTab: _index,
      tabLabels: <String>[
        for (final ({String label, IconData icon, Widget page}) tab
            in _homeTabs)
          tab.label,
      ],
      canSignOut: _auth.isSignedIn,
      onSignOut: _confirmSignOut,
      child: PopScope(
        // The shell is the app's root route, so a tab is popped before the
        // app is allowed to close. Back from any tab other than the
        // dashboard returns to the dashboard instead of leaving the app.
        canPop: _index == 0,
        onPopInvokedWithResult: (bool didPop, Object? result) {
          if (!didPop && _index != 0) {
            setState(() => _index = 0);
          }
        },
        child: Scaffold(
          key: _scaffold,
          backgroundColor: AppColors.primary,
          drawer: _HomeDrawer(
            index: _index,
            onSelectTab: _selectTab,
            onPush: _push,
            onTakeTicket: _takeTicket,
            onSignOut: _confirmSignOut,
          ),
          body: Column(
            children: <Widget>[
              // Watches for staff-cancelled tickets and pops the
              // cancellation dialog once per ticket, on any tab.
              const CancelledTicketWatcher(),
              const ConnectionBanner(),
              Expanded(
                child: IndexedStack(
                  index: _index,
                  children: <Widget>[
                    for (final ({String label, IconData icon, Widget page}) tab
                        in _homeTabs)
                      tab.page,
                  ],
                ),
              ),
            ],
          ),
          bottomNavigationBar: _HomeNavBar(
            index: _index,
            onSelect: (int index) => setState(() => _index = index),
          ),
        ),
      ),
    );
  }
}

/// The pages that live in the shell and are reachable from the drawer.
const List<({String label, IconData icon, Widget page})> _homeTabs =
    <({String label, IconData icon, Widget page})>[
      (
        label: 'Dashboard',
        icon: Icons.dashboard_rounded,
        page: DashboardScreen(),
      ),
      (
        label: 'Queue statistics',
        icon: Icons.insights_rounded,
        page: QueueStatsScreen(),
      ),
      (label: 'History', icon: Icons.history_rounded, page: HistoryScreen()),
      (label: 'Profile', icon: Icons.person_rounded, page: ProfileScreen()),
    ];

/// Pages pushed as routes, so they get a back button.
const List<({String label, IconData icon, WidgetBuilder page})> _homeRoutes =
    <({String label, IconData icon, WidgetBuilder page})>[
      (
        label: 'Take a ticket',
        icon: Icons.add_circle_outline_rounded,
        page: _newTicket,
      ),
      (
        label: 'My ticket',
        icon: Icons.confirmation_number_outlined,
        page: _myTicket,
      ),
      (
        label: 'Notifications',
        icon: Icons.notifications_none_rounded,
        page: _notifications,
      ),
      (
        label: 'Announcements',
        icon: Icons.campaign_outlined,
        page: _announcements,
      ),
      (label: 'Settings', icon: Icons.settings_outlined, page: _settings),
      (label: 'About', icon: Icons.info_outline_rounded, page: _about),
    ];

Widget _newTicket(BuildContext context) => const NewTicketScreen();
Widget _myTicket(BuildContext context) => const MyTicketScreen();
Widget _notifications(BuildContext context) => const NotificationsScreen();
Widget _announcements(BuildContext context) => const AnnouncementsScreen();
Widget _settings(BuildContext context) => const SettingsScreen();
Widget _about(BuildContext context) => const AboutScreen();

/// The frosted bar along the bottom of the shell.
///
/// Four destinations, matching the tabs, with the active one lifted and tinted.
/// Built here rather than themed through `NavigationBar` so it can use the
/// same glass as the rest of the app — the Material component insists on its
/// own opaque surface and its own indicator, which is the one thing on screen
/// that would not match.
class _HomeNavBar extends StatelessWidget {
  const _HomeNavBar({required this.index, required this.onSelect});

  final int index;
  final ValueChanged<int> onSelect;

  @override
  Widget build(BuildContext context) {
    final double bottomInset = MediaQuery.of(context).padding.bottom;

    return ClipRect(
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 20, sigmaY: 20),
        child: Container(
          padding: EdgeInsets.only(
            top: 9,
            bottom: 9 + (bottomInset > 0 ? bottomInset * 0.4 : 0),
          ),
          decoration: BoxDecoration(
            color: AppColors.navy.withValues(alpha: 0.72),
            border: const Border(
              top: BorderSide(color: Color(0x26FFFFFF), width: 1),
            ),
          ),
          child: Row(
            children: <Widget>[
              for (int i = 0; i < _homeTabs.length; i++)
                Expanded(
                  child: _NavBarItem(
                    label: _homeTabs[i].label,
                    icon: _homeTabs[i].icon,
                    selected: i == index,
                    onTap: () => onSelect(i),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NavBarItem extends StatelessWidget {
  const _NavBarItem({
    required this.label,
    required this.icon,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final Color tint = selected
        ? Colors.white
        : Colors.white.withValues(alpha: 0.55);

    return PressScale(
      pressedScale: 0.94,
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            AnimatedContainer(
              duration: const Duration(milliseconds: 220),
              curve: QfCurves.smooth,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 5),
              decoration: BoxDecoration(
                color: selected
                    ? AppColors.blue.withValues(alpha: 0.26)
                    : Colors.transparent,
                borderRadius: BorderRadius.circular(999),
                border: selected
                    ? Border.all(
                        color: AppColors.blue.withValues(alpha: 0.55),
                      )
                    : null,
              ),
              child: Icon(icon, size: 21, color: tint),
            ),
            const SizedBox(height: 4),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              style: TextStyle(
                color: tint,
                fontSize: 10.5,
                fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The navigation drawer: identity, the tabbed pages, the pushed pages,
/// then sign out.
class _HomeDrawer extends StatelessWidget {
  const _HomeDrawer({
    required this.index,
    required this.onSelectTab,
    required this.onPush,
    required this.onTakeTicket,
    required this.onSignOut,
  });

  final int index;
  final ValueChanged<int> onSelectTab;
  final Future<void> Function(WidgetBuilder page) onPush;

  /// Opens the "Generate ticket" popup rather than pushing a page.
  final Future<void> Function() onTakeTicket;

  final Future<void> Function() onSignOut;

  @override
  Widget build(BuildContext context) {
    final AuthRepository auth = AuthRepository.instance;
    final Student? student = auth.student;
    final QueueTicket? ticket = auth.activeTicket;

    return Drawer(
      backgroundColor: Colors.transparent,
      child: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: <Color>[AppColors.navy, AppColors.primary],
          ),
          border: Border(right: BorderSide(color: Color(0x33FFFFFF))),
        ),
        child: SafeArea(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              _DrawerHeader(student: student, ticket: ticket),
              const SizedBox(height: 8),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(14, 0, 14, 16),
                  children: <Widget>[
                    const _GroupLabel(text: 'Main'),
                    for (int i = 0; i < _homeTabs.length; i++)
                      _DrawerItem(
                        icon: _homeTabs[i].icon,
                        label: _homeTabs[i].label,
                        selected: i == index,
                        onTap: () => onSelectTab(i),
                      ),

                    const SizedBox(height: 14),
                    const _GroupLabel(text: 'Queue'),
                    for (final ({
                          String label,
                          IconData icon,
                          WidgetBuilder page,
                        })
                        entry
                        in _homeRoutes.take(2))
                      _DrawerItem(
                        icon: entry.icon,
                        label: entry.label,
                        // The live ticket earns a badge so it is findable
                        // from anywhere in the drawer.
                        badge: entry.label == 'My ticket' && ticket != null
                            ? '1'
                            : null,
                        // Taking a ticket opens the popup form instead of a
                        // page; the rest still push their screen.
                        onTap: entry.label == 'Take a ticket'
                            ? onTakeTicket
                            : () => onPush(entry.page),
                      ),

                    const SizedBox(height: 14),
                    const _GroupLabel(text: 'More'),
                    for (final ({
                          String label,
                          IconData icon,
                          WidgetBuilder page,
                        })
                        entry
                        in _homeRoutes.skip(2))
                      _DrawerItem(
                        icon: entry.icon,
                        label: entry.label,
                        onTap: () => onPush(entry.page),
                      ),

                    const SizedBox(height: 20),
                    const _GroupLabel(text: 'Session'),
                    _DrawerItem(
                      icon: Icons.logout_rounded,
                      label: 'Sign out',
                      danger: true,
                      onTap: onSignOut,
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(24, 0, 24, 14),
                child: Text(
                  '${AppConstants.appName}  •  v1.0.0',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.28),
                    fontSize: 10.5,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _DrawerHeader extends StatelessWidget {
  const _DrawerHeader({required this.student, required this.ticket});

  final Student? student;
  final QueueTicket? ticket;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 14),
      child: Row(
        children: <Widget>[
          GlassAvatar(initials: student?.initials ?? '?', radius: 26),
          const SizedBox(width: 13),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  student?.name ?? 'Signed in',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  ticket == null
                      ? (student?.academicSummary ?? '')
                      : 'Number ${ticket!.queueNumber} is active',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: ticket == null
                        ? Colors.white.withValues(alpha: 0.45)
                        : AppColors.success,
                    fontSize: 11.5,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _GroupLabel extends StatelessWidget {
  const _GroupLabel({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(10, 4, 10, 8),
      child: Text(
        text.toUpperCase(),
        style: TextStyle(
          color: Colors.white.withValues(alpha: 0.35),
          fontSize: 9.5,
          fontWeight: FontWeight.w800,
          letterSpacing: 1.3,
        ),
      ),
    );
  }
}

class _DrawerItem extends StatelessWidget {
  const _DrawerItem({
    required this.icon,
    required this.label,
    required this.onTap,
    this.selected = false,
    this.danger = false,
    this.badge,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool selected;
  final bool danger;
  final String? badge;

  @override
  Widget build(BuildContext context) {
    final Color tint = danger
        ? AppColors.danger
        : selected
        ? Colors.white
        : Colors.white.withValues(alpha: 0.72);

    return PressScale(
      pressedScale: 0.98,
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        margin: const EdgeInsets.only(bottom: 2),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        decoration: BoxDecoration(
          color: selected
              ? Colors.white.withValues(alpha: 0.13)
              : Colors.transparent,
          borderRadius: BorderRadius.circular(13),
          border: selected
              ? Border.all(color: Colors.white.withValues(alpha: 0.16))
              : null,
        ),
        child: Row(
          children: <Widget>[
            Icon(icon, size: 19, color: tint),
            const SizedBox(width: 13),
            Expanded(
              child: Text(
                label,
                style: TextStyle(
                  color: tint,
                  fontSize: 13.5,
                  fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                ),
              ),
            ),
            if (badge != null)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                decoration: BoxDecoration(
                  color: AppColors.success,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  badge!,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 10,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
