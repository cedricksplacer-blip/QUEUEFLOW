import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/glass.dart';
import '../models/announcement.dart';
import '../repositories/auth_repository.dart';
import '../widgets/qf_page.dart';

/// ============================================================
/// QUEUEFLOW TMC — ANNOUNCEMENTS
///
/// Read-only feed of published notices, newest first. Announcements
/// arrive inside the bootstrap payload, so this page is instant and
/// costs no extra request — it only needs a refresh when the student
/// pulls it down.
/// ============================================================

class AnnouncementsScreen extends StatefulWidget {
  const AnnouncementsScreen({super.key});

  @override
  State<AnnouncementsScreen> createState() => _AnnouncementsScreenState();
}

class _AnnouncementsScreenState extends State<AnnouncementsScreen> {
  final AuthRepository _auth = AuthRepository.instance;

  @override
  void initState() {
    super.initState();
    _auth.addListener(_onAuthChanged);
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

  @override
  Widget build(BuildContext context) {
    final List<Announcement> all = _auth.announcements;
    final String role = _auth.role.wire.isEmpty ? 'student' : _auth.role.wire;

    // Unpublished or deactivated entries are filtered out here as well as
    // on the server, so a stale cache cannot show a retracted notice.
    // Audience is enforced too: staff-only notices never appear in the
    // user app even if a cached payload still carries them.
    final List<Announcement> live = all
        .where((Announcement a) => a.isLiveForRole(role))
        .toList(growable: false);

    return QfPage(
      title: 'Announcements',
      subtitle: live.isEmpty
          ? 'Campus notices'
          : '${live.length} notice${live.length == 1 ? '' : 's'}',
      onBack: () => Navigator.of(context).maybePop(),
      actions: <Widget>[
        QfIconButton(
          icon: Icons.refresh_rounded,
          tooltip: 'Refresh',
          onTap: () => _auth.refresh(),
        ),
      ],
      children: <Widget>[
        if (live.isEmpty)
          const QfEmptyState(
            icon: Icons.campaign_outlined,
            title: 'No announcements',
            message:
                'Campus notices from the registrar and office staff will '
                'appear here.',
          )
        else
          for (final Announcement notice in live)
            _AnnouncementCard(notice: notice),
      ],
    );
  }
}

class _AnnouncementCard extends StatelessWidget {
  const _AnnouncementCard({required this.notice});

  final Announcement notice;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: GlassCard(
        radius: 22,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Container(
                  width: 34,
                  height: 34,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(11),
                    color: AppColors.info.withValues(alpha: 0.14),
                  ),
                  child: const Icon(
                    Icons.campaign_rounded,
                    size: 17,
                    color: AppColors.info,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    notice.title,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 14.5,
                      fontWeight: FontWeight.w700,
                      height: 1.3,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              notice.body,
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.62),
                fontSize: 12.5,
                height: 1.55,
              ),
            ),
            if (notice.office != null || notice.createdAt != null) ...<Widget>[
              const SizedBox(height: 14),
              Row(
                children: <Widget>[
                  if (notice.office != null) ...<Widget>[
                    Icon(
                      Icons.apartment_rounded,
                      size: 12,
                      color: Colors.white.withValues(alpha: 0.35),
                    ),
                    const SizedBox(width: 5),
                    Text(
                      notice.office!,
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.38),
                        fontSize: 11,
                      ),
                    ),
                  ],
                  if (notice.office != null && notice.createdAt != null)
                    const SizedBox(width: 12),
                  if (notice.createdAt != null) ...<Widget>[
                    Icon(
                      Icons.schedule_rounded,
                      size: 12,
                      color: Colors.white.withValues(alpha: 0.35),
                    ),
                    const SizedBox(width: 5),
                    Text(
                      _stamp(notice.createdAt!),
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.38),
                        fontSize: 11,
                      ),
                    ),
                  ],
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

String _stamp(DateTime when) {
  final String month = when.month.toString().padLeft(2, '0');
  final String day = when.day.toString().padLeft(2, '0');

  return '${when.year}-$month-$day';
}
