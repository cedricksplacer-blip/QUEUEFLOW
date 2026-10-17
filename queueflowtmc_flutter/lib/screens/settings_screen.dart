import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/glass.dart';
import '../models/announcement.dart';
import '../repositories/auth_repository.dart';
import '../services/notification_service.dart';
import '../widgets/qf_page.dart';

/// ============================================================
/// QUEUEFLOW TMC — SYSTEM SETTINGS
///
/// Read-only mirror of the server's public settings. These values are
/// maintained by staff in the admin panel, so the page deliberately
/// offers no editing: a student toggling "maintenance mode" locally
/// would only be fooling themselves.
///
/// Notification switches are shown as policy, not preference — they
/// describe whether the *system* will notify, which the student cannot
/// change from here.
/// ============================================================

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  final AuthRepository _auth = AuthRepository.instance;

  /// The in-app sound toggle. Null while loading: no switch is shown rather
  /// than a switch showing a guess.
  bool? _soundOn;
  bool _testingSound = false;

  @override
  void initState() {
    super.initState();
    _auth.addListener(_onAuthChanged);
    NotificationService.instance.soundEnabled().then((bool enabled) {
      if (mounted) {
        setState(() => _soundOn = enabled);
      }
    });
  }

  @override
  void dispose() {
    _auth.removeListener(_onAuthChanged);
    super.dispose();
  }

  void _onAuthChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _setSound(bool enabled) async {
    setState(() => _soundOn = enabled);
    await NotificationService.instance.setSoundEnabled(enabled);
  }

  Future<void> _playTestSound() async {
    if (_testingSound) {
      return;
    }

    setState(() => _testingSound = true);
    try {
      await NotificationService.instance.testSound();
    } finally {
      if (mounted) {
        setState(() => _testingSound = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final SystemSettings s = _auth.settings;

    return QfPage(
      title: 'Settings',
      subtitle: s.siteName,
      onBack: () => Navigator.of(context).maybePop(),
      actions: <Widget>[
        QfIconButton(
          icon: Icons.refresh_rounded,
          tooltip: 'Refresh',
          onTap: () => _auth.refresh(),
        ),
      ],
      children: <Widget>[
        if (s.inMaintenanceMode) ...<Widget>[
          QfBanner(
            message: s.maintenanceMessage.isEmpty
                ? 'The system is under maintenance. Queue numbers may be '
                      'unavailable for a while.'
                : s.maintenanceMessage,
            icon: Icons.build_circle_outlined,
            tone: QfBannerTone.info,
          ),
          const SizedBox(height: 18),
        ],

        const SectionTitle(text: 'Institution'),
        GlassCard(
          radius: 22,
          child: Column(
            children: <Widget>[
              QfInfoRow(
                label: 'System name',
                value: s.siteName,
                icon: Icons.badge_outlined,
              ),
              QfInfoRow(
                label: 'College',
                value: s.collegeName,
                icon: Icons.apartment_rounded,
              ),
              if (s.systemDescription.isNotEmpty)
                QfInfoRow(
                  label: 'About',
                  value: s.systemDescription,
                  icon: Icons.info_outline_rounded,
                ),
            ],
          ),
        ),

        const SizedBox(height: 22),
        const SectionTitle(text: 'Operating hours'),
        GlassCard(
          radius: 22,
          child: Column(
            children: <Widget>[
              QfInfoRow(
                label: 'Queue hours',
                value: s.operatingWindow,
                icon: Icons.schedule_rounded,
              ),
              QfInfoRow(
                label: 'Default hours',
                value: '${s.defaultOpeningTime} - ${s.defaultClosingTime}',
                icon: Icons.alarm_rounded,
              ),
              QfInfoRow(
                label: 'Estimated wait',
                value: '${s.defaultWaitingTime} min per person',
                icon: Icons.hourglass_bottom_rounded,
              ),
            ],
          ),
        ),

        const SizedBox(height: 22),
        const SectionTitle(text: 'Queue rules'),
        GlassCard(
          radius: 22,
          child: Column(
            children: <Widget>[
              QfInfoRow(
                label: 'Active tickets per student',
                value: '${s.maxActiveTicketsPerStudent}',
                icon: Icons.confirmation_number_outlined,
              ),
              QfInfoRow(
                label: 'Slots per station',
                value: '${s.maxSlotsPerStation}',
                icon: Icons.grid_4x4_rounded,
              ),
              QfInfoRow(
                label: 'Min students per station',
                value: '${s.minimumStudentsPerStation}',
                icon: Icons.groups_outlined,
              ),
              QfInfoRow(
                label: 'Target students per station',
                value: '${s.targetStudentsPerStation}',
                icon: Icons.trending_up_rounded,
              ),
            ],
          ),
        ),

        if (s.supportedCourses.isNotEmpty) ...<Widget>[
          const SizedBox(height: 22),
          const SectionTitle(text: 'Supported courses'),
          Wrap(
            spacing: 7,
            runSpacing: 7,
            children: <Widget>[
              for (final String course in s.supportedCourses)
                QfPill(text: course, color: AppColors.violet),
            ],
          ),
        ],

        if (s.supportedYearLevels.isNotEmpty) ...<Widget>[
          const SizedBox(height: 22),
          const SectionTitle(text: 'Year levels'),
          Wrap(
            spacing: 7,
            runSpacing: 7,
            children: <Widget>[
              for (final String year in s.supportedYearLevels)
                QfPill(text: year, color: AppColors.info),
            ],
          ),
        ],

        const SizedBox(height: 22),
        const SectionTitle(text: 'On this device'),
        GlassCard(
          radius: 22,
          child: Column(
            children: <Widget>[
              _DeviceSoundRow(
                soundOn: _soundOn,
                onChanged: _setSound,
              ),
              _TestSoundRow(
                busy: _testingSound,
                onTap: _playTestSound,
                last: true,
              ),
            ],
          ),
        ),

        const SizedBox(height: 22),
        const SectionTitle(text: 'Notifications sent to you'),
        GlassCard(
          radius: 22,
          child: Column(
            children: <Widget>[
              _Policy(
                label: 'When your ticket is issued',
                on: s.ticketCreatedNotification,
              ),
              _Policy(
                label: 'When your turn is close',
                on: s.queueAlmostReadyNotification,
              ),
              _Policy(
                label: 'When your number is called',
                on: s.nowServingNotification,
              ),
              _Policy(
                label: 'When your ticket is completed',
                on: s.ticketCompletedNotification,
              ),
              _Policy(
                label: 'When your ticket is cancelled',
                on: s.ticketCancelledNotification,
              ),
              _Policy(
                label: 'New campus announcements',
                on: s.announcementNotification,
                last: true,
              ),
            ],
          ),
        ),

        const SizedBox(height: 22),
        const SectionTitle(text: 'Contact'),
        GlassCard(
          radius: 22,
          child: Column(
            children: <Widget>[
              QfInfoRow(
                label: 'Email',
                value: s.contactEmail.isEmpty
                    ? 'Not published'
                    : s.contactEmail,
                icon: Icons.alternate_email_rounded,
              ),
              QfInfoRow(
                label: 'Phone',
                value: s.contactNumber.isEmpty
                    ? 'Not published'
                    : s.contactNumber,
                icon: Icons.phone_outlined,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// A notification the system will or will not send. Read-only by design.
class _Policy extends StatelessWidget {
  const _Policy({required this.label, required this.on, this.last = false});

  final String label;
  final bool on;
  final bool last;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: BoxDecoration(
        border: last
            ? null
            : Border(
                bottom: BorderSide(color: Colors.white.withValues(alpha: 0.07)),
              ),
      ),
      child: Row(
        children: <Widget>[
          Icon(
            on
                ? Icons.check_circle_rounded
                : Icons.remove_circle_outline_rounded,
            size: 17,
            color: on ? AppColors.success : Colors.white24,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              label,
              style: TextStyle(
                color: Colors.white.withValues(alpha: on ? 0.72 : 0.32),
                fontSize: 13,
              ),
            ),
          ),
          Text(
            on ? 'On' : 'Off',
            style: TextStyle(
              color: Colors.white.withValues(alpha: on ? 0.60 : 0.28),
              fontSize: 11,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

/// The in-app sound toggle: whether event notifications on this device play
/// a sound. Null while the preference loads, so no switch renders rather
/// than one showing a guess. System silent mode and Do Not Disturb apply on
/// top of this regardless.
class _DeviceSoundRow extends StatelessWidget {
  const _DeviceSoundRow({required this.soundOn, required this.onChanged});

  final bool? soundOn;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: Colors.white.withValues(alpha: 0.07)),
        ),
      ),
      child: Row(
        children: <Widget>[
          const Icon(
            Icons.volume_up_rounded,
            size: 17,
            color: AppColors.accent,
          ),
          const SizedBox(width: 12),
          const Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  'Queue alert sounds',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                SizedBox(height: 2),
                Text(
                  'Silent mode and Do Not Disturb still apply.',
                  style: TextStyle(color: Colors.white38, fontSize: 11),
                ),
              ],
            ),
          ),
          if (soundOn == null)
            const SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          else
            Switch.adaptive(
              value: soundOn!,
              activeThumbColor: AppColors.accent,
              onChanged: onChanged,
            ),
        ],
      ),
    );
  }
}

/// Plays a sample notification so the student hears what queue alerts sound
/// like. Always sounds — demonstrating it is the point, even with sounds off.
class _TestSoundRow extends StatelessWidget {
  const _TestSoundRow({
    required this.busy,
    required this.onTap,
    this.last = false,
  });

  final bool busy;
  final VoidCallback onTap;
  final bool last;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: busy ? null : onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12),
        decoration: BoxDecoration(
          border: last
              ? null
              : Border(
                  bottom: BorderSide(
                    color: Colors.white.withValues(alpha: 0.07),
                  ),
                ),
        ),
        child: Row(
          children: <Widget>[
            Icon(
              Icons.play_circle_outline_rounded,
              size: 17,
              color: busy ? Colors.white24 : AppColors.accent,
            ),
            const SizedBox(width: 12),
            const Expanded(
              child: Text(
                'Play test sound',
                style: TextStyle(color: Colors.white, fontSize: 13),
              ),
            ),
            if (busy)
              const SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            else
              const Icon(
                Icons.chevron_right_rounded,
                size: 18,
                color: Colors.white38,
              ),
          ],
        ),
      ),
    );
  }
}
