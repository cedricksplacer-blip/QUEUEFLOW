import 'package:flutter/material.dart';
import '../core/api/api_config.dart';
import '../core/app_colors.dart';
import '../core/app_constants.dart';
import '../core/glass.dart';

import '../core/motion.dart';
import '../core/network/connectivity_service.dart';
import '../core/storage/endpoint_store.dart';
import '../models/announcement.dart';
import '../repositories/auth_repository.dart';
import '../widgets/qf_page.dart';

/// ============================================================
/// QUEUEFLOW TMC — ABOUT
///
/// Static reference: what the app is, who it is for, and how to get
/// help. The support addresses come from the server's settings so a
/// change in the admin panel reaches every install without an update.
/// ============================================================

class AboutScreen extends StatelessWidget {
  const AboutScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final AuthRepository auth = AuthRepository.instance;
    final SystemSettings s = auth.settings;

    return QfPage(
      title: 'About',
      subtitle: s.siteName,
      onBack: () => Navigator.of(context).maybePop(),
      children: <Widget>[
        const _Brand(),
        const SizedBox(height: 24),

        const SectionTitle(text: 'What this is'),
        GlassCard(
          radius: 22,
          child: Text(
            s.systemDescription.isEmpty
                ? 'QueueFlow TMC is the queue system of Trinidad Municipal '
                      'College. It lets you take a queue number at an office, '
                      'watch your position live, and see when you are called '
                      'without standing in line to ask.'
                : s.systemDescription,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.62),
              fontSize: 13,
              height: 1.6,
            ),
          ),
        ),

        const SizedBox(height: 22),
        const SectionTitle(text: 'How the queue works'),
        const _Steps(),

        const SizedBox(height: 22),
        const SectionTitle(text: 'Need help?'),
        GlassCard(
          radius: 22,
          child: Column(
            children: <Widget>[
              QfInfoRow(
                label: 'Institution',
                value: s.collegeName,
                icon: Icons.apartment_rounded,
              ),
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
        const SizedBox(height: 12),
        const QfBanner(
          message:
              'If a number is called while you were offline, tell the office '
              'staff immediately. They can restore your slot.',
          icon: Icons.info_outline_rounded,
          tone: QfBannerTone.info,
        ),

        const SizedBox(height: 22),
        const SectionTitle(text: 'Server connection'),
        const _ServerConnection(),

        const SizedBox(height: 22),
        const SectionTitle(text: 'Version'),
        GlassCard(
          radius: 22,
          child: Column(
            children: <Widget>[
              const QfInfoRow(
                label: 'App version',
                value: '1.0.0',
                icon: Icons.tag_rounded,
              ),
              QfInfoRow(
                label: 'Queue hours',
                value: s.operatingWindow,
                icon: Icons.schedule_rounded,
              ),
            ],
          ),
        ),

        const SizedBox(height: 26),
        Center(
          child: Text(
            'Made for students of ${s.collegeName}',
            textAlign: TextAlign.center,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.32),
              fontSize: 11.5,
            ),
          ),
        ),
      ],
    );
  }
}

/// App mark and name.
class _Brand extends StatelessWidget {
  const _Brand();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        children: <Widget>[
          AmbientLoop(
            period: const Duration(milliseconds: 5200),
            builder: (BuildContext context, double t, double phase) {
              return Container(
                width: 78,
                height: 78,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: AppColors.brandGradient,
                  boxShadow: <BoxShadow>[
                    BoxShadow(
                      color: AppColors.violet.withValues(
                        alpha: 0.30 + t * 0.20,
                      ),
                      blurRadius: 26 + t * 14,
                    ),
                  ],
                ),
                child: const Icon(
                  Icons.confirmation_number_rounded,
                  color: Colors.white,
                  size: 36,
                ),
              );
            },
          ),
          const SizedBox(height: 18),
          Text(
            AppConstants.appShortName,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 22,
              fontWeight: FontWeight.w800,
              letterSpacing: 0.4,
            ),
          ),
          const SizedBox(height: 5),
          Text(
            'Campus queue, without the line',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.45),
              fontSize: 12.5,
            ),
          ),
        ],
      ),
    );
  }
}

class _Steps extends StatelessWidget {
  const _Steps();

  static const List<(String, String, IconData)>
  _items = <(String, String, IconData)>[
    (
      'Take a number',
      'Choose the office and the service, then confirm. Your number appears '
          'straight away.',
      Icons.add_circle_outline_rounded,
    ),
    (
      'Watch your position',
      'The app shows how many people are ahead of you and roughly how long '
          'you will wait.',
      Icons.visibility_outlined,
    ),
    (
      'Get called',
      'Watch the board. When your number is called, go to your station.',
      Icons.campaign_outlined,
    ),
    (
      'Done',
      'Your ticket moves to your history. Take a new one when you need it '
          'again.',
      Icons.task_alt_rounded,
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      radius: 22,
      child: Column(
        children: <Widget>[
          for (int i = 0; i < _items.length; i++)
            Container(
              padding: const EdgeInsets.symmetric(vertical: 13),
              decoration: BoxDecoration(
                border: i == _items.length - 1
                    ? null
                    : Border(
                        bottom: BorderSide(
                          color: Colors.white.withValues(alpha: 0.07),
                        ),
                      ),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Container(
                    width: 26,
                    height: 26,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: AppColors.accent.withValues(alpha: 0.15),
                    ),
                    alignment: Alignment.center,
                    child: Text(
                      '${i + 1}',
                      style: const TextStyle(
                        color: AppColors.accent,
                        fontSize: 12,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  const SizedBox(width: 13),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Text(
                          _items[i].$1,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 13.5,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          _items[i].$2,
                          style: TextStyle(
                            color: Colors.white.withValues(alpha: 0.48),
                            fontSize: 11.5,
                            height: 1.45,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 10),
                  Icon(_items[i].$3, size: 18, color: Colors.white24),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// Which server the app talks to, and a way to change it.
///
/// The compiled-in default is the public HTTPS tunnel, so this section lets
/// the reader point the app at a new tunnel URL when the ngrok domain
/// changes, or at the PC's LAN address as a same-Wi-Fi fallback - and test
/// it, no rebuild needed. The choice is persisted
/// by [EndpointStore] and takes effect on every request immediately,
/// because [ApiConfig.baseUrl] is read per call rather than cached.
///
/// Pointing at a different server does not move the sign-in: a token belongs
/// to the server that issued it, so after a switch the reader may have to
/// sign in again. That is said on the dialog, not discovered as a mystery
/// 401.
class _ServerConnection extends StatefulWidget {
  const _ServerConnection();

  @override
  State<_ServerConnection> createState() => _ServerConnectionState();
}

class _ServerConnectionState extends State<_ServerConnection> {
  final ConnectivityService _connectivity = ConnectivityService.instance;

  bool _testing = false;
  String? _notice;

  @override
  void initState() {
    super.initState();
    _connectivity.addListener(_onConnectivityChanged);
  }

  @override
  void dispose() {
    _connectivity.removeListener(_onConnectivityChanged);
    super.dispose();
  }

  void _onConnectivityChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  String get _statusLabel {
    switch (_connectivity.state) {
      case AppConnectionState.online:
        return 'Online';
      case AppConnectionState.checking:
        return 'Checking…';
      case AppConnectionState.offline:
        return 'Offline';
      case AppConnectionState.serverUnavailable:
        return 'Server Offline';
    }
  }

  Future<void> _test() async {
    if (_testing) {
      return;
    }

    setState(() {
      _testing = true;
      _notice = null;
    });

    try {
      final AppConnectionState settled = await _connectivity.refresh();

      if (!mounted) {
        return;
      }

      setState(() {
        _notice = settled == AppConnectionState.online
            ? 'Connected to ${ApiConfig.displayHost}.'
            : _connectivity.message.body;
      });
    } finally {
      if (mounted) {
        setState(() => _testing = false);
      }
    }
  }

  Future<void> _change() async {
    final String? entered = await showDialog<String>(
      context: context,
      builder: (BuildContext context) => const _EndpointDialog(),
    );

    // Dismissed, not saved.
    if (entered == null) {
      return;
    }

    await EndpointStore.save(entered);

    if (!mounted) {
      return;
    }

    setState(() {
      _notice = 'Server address updated. If this is a different server, '
          'sign out and sign in again.';
    });

    await _test();
  }

  Future<void> _reset() async {
    await EndpointStore.clear();

    if (!mounted) {
      return;
    }

    setState(() {
      _notice = 'Back to the default server (${ApiConfig.displayHost}).';
    });

    await _test();
  }

  @override
  Widget build(BuildContext context) {
    final String? notice = _notice;

    return GlassCard(
      radius: 22,
      child: Column(
        children: <Widget>[
          QfInfoRow(
            label: 'Server',
            value: ApiConfig.displayHost,
            icon: Icons.cloud_outlined,
          ),
          QfInfoRow(
            label: 'Status',
            value: _statusLabel,
            icon: Icons.signal_cellular_alt_rounded,
          ),
          QfInfoRow(
            label: 'Address',
            value: ApiConfig.hasEndpointOverride ? 'Custom' : 'Default',
            icon: Icons.tune_rounded,
          ),
          if (ApiConfig.isPlaceholder && !ApiConfig.hasEndpointOverride) ...<Widget>[
            const SizedBox(height: 10),
            const QfBanner(
              message:
                  'No server address configured yet. Enter your server address via '
                  'Change (the public https tunnel URL ending in /api, or the '
                  'same-Wi-Fi http://<lan-ip>:8000/api), or rebuild with '
                  '--dart-define=API_BASE_URL.',
              icon: Icons.info_outline_rounded,
              tone: QfBannerTone.info,
            ),
          ],
          if (notice != null) ...<Widget>[
            const SizedBox(height: 10),
            QfBanner(
              message: notice,
              icon: Icons.info_outline_rounded,
              tone: QfBannerTone.info,
            ),
          ],
          const SizedBox(height: 12),
          Row(
            children: <Widget>[
              Expanded(
                child: PrimaryButton(
                  label: 'Test',
                  icon: Icons.sync_rounded,
                  height: 48,
                  busy: _testing,
                  onPressed: _testing ? null : _test,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: PrimaryButton(
                  label: 'Change',
                  icon: Icons.edit_outlined,
                  height: 48,
                  onPressed: _testing ? null : _change,
                ),
              ),
              if (ApiConfig.hasEndpointOverride) ...<Widget>[
                const SizedBox(width: 10),
                Expanded(
                  child: PrimaryButton(
                    label: 'Reset',
                    icon: Icons.restart_alt_rounded,
                    height: 48,
                    onPressed: _testing ? null : _reset,
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

/// The dialog that takes a new server address.
///
/// Validation runs through [ApiConfig.validateEndpoint], so the rules about
/// `https://` for public hosts and `http://` for LAN hosts live in exactly
/// one place. Nothing is saved here: the dialog returns the entered text
/// and the caller persists it, so a dismissed dialog cannot leave a
/// half-applied address behind.
class _EndpointDialog extends StatefulWidget {
  const _EndpointDialog();

  @override
  State<_EndpointDialog> createState() => _EndpointDialogState();
}

class _EndpointDialogState extends State<_EndpointDialog> {
  late final TextEditingController _controller;
  String? _error;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: ApiConfig.baseUrl);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _save() {
    final String? problem = ApiConfig.validateEndpoint(_controller.text);

    if (problem != null) {
      setState(() => _error = problem);
      return;
    }

    Navigator.of(context).pop(_controller.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    final String? error = _error;

    return Dialog(
      backgroundColor: Colors.transparent,
      insetPadding: const EdgeInsets.all(26),
      child: GlassCard(
        radius: 28,
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Text(
              'Server address',
              style: TextStyle(
                color: Colors.white,
                fontSize: 16,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 12),
            const Text(
              'The address of the QueueFlow server, ending with /api. '
              'Normally the public https tunnel URL; a same-Wi-Fi address '
              'uses http:// with the PC LAN IP.',
              textAlign: TextAlign.center,
              style: TextStyle(
                color: Colors.white70,
                fontSize: 12.5,
                height: 1.5,
              ),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _controller,
              keyboardType: TextInputType.url,
              autocorrect: false,
              enableSuggestions: false,
              style: const TextStyle(color: Colors.white, fontSize: 13),
              decoration: InputDecoration(
                hintText: 'https://<your-tunnel-domain>/api',
                hintStyle: TextStyle(
                  color: Colors.white.withValues(alpha: 0.35),
                ),
                errorText: error,
                filled: true,
                fillColor: Colors.white.withValues(alpha: 0.06),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: BorderSide(
                    color: Colors.white.withValues(alpha: 0.12),
                  ),
                ),
              ),
              onSubmitted: (_) => _save(),
            ),
            const SizedBox(height: 18),
            PrimaryButton(
              label: 'Save',
              icon: Icons.check_rounded,
              height: 52,
              onPressed: _save,
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text(
                'Cancel',
                style: TextStyle(color: Colors.white70),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
