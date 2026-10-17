import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/app_colors.dart';
import '../core/glass.dart';
import '../core/motion.dart';
import '../repositories/password_reset_controller.dart';
import '../widgets/qf_page.dart';
import 'new_password_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — CONFIRM CODE (STEP 2: VERIFICATION CODE)
///
/// Reached only after the server accepts a code request. Shows the
/// masked address the code went to, collects the 6 digits, and has the
/// server verify them: only a correct, unexpired code unlocks the
/// Enter New Password screen. A Resend option requests a fresh code,
/// which voids the previous one server-side.
/// ============================================================

class ConfirmCodeScreen extends StatefulWidget {
  const ConfirmCodeScreen({super.key});

  @override
  State<ConfirmCodeScreen> createState() => _ConfirmCodeScreenState();
}

class _ConfirmCodeScreenState extends State<ConfirmCodeScreen> {
  final PasswordResetController _reset = PasswordResetController.instance;

  final TextEditingController _code = TextEditingController();

  String? _error;

  @override
  void initState() {
    super.initState();
    _reset.addListener(_onResetChanged);
  }

  @override
  void dispose() {
    _reset.removeListener(_onResetChanged);
    _code.dispose();
    super.dispose();
  }

  void _onResetChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _verify() async {
    FocusScope.of(context).unfocus();

    setState(() => _error = null);

    final String? failure = await _reset.verifyCode(_code.text);

    if (!mounted) {
      return;
    }

    if (failure != null) {
      setState(() => _error = failure);
      return;
    }

    _code.clear();

    await Navigator.of(context).push<void>(
      QfPageRoute<void>(page: const NewPasswordScreen()),
    );
  }

  Future<void> _resend() async {
    FocusScope.of(context).unfocus();

    setState(() => _error = null);

    final String address = _reset.identifier ?? '';

    final String? failure = await _reset.requestCode(address);

    if (!mounted) {
      return;
    }

    if (failure != null) {
      setState(() => _error = failure);
      return;
    }

    _code.clear();
  }

  @override
  Widget build(BuildContext context) {
    final bool busy = _reset.isBusy;

    // Null when the code was mailed rather than handed back, which is what
    // keeps this screen from showing a number the server never issued.
    final String? issued = _reset.hasIssuedCode ? _reset.issuedCode : null;

    return QfPage(
      title: 'Confirm code',
      subtitle: 'Step 2 of 3 — verification code',
      onBack: () => Navigator.of(context).maybePop(),
      children: <Widget>[
        if (_reset.notice != null) ...<Widget>[
          QfBanner(message: _reset.notice!, tone: QfBannerTone.info),
          const SizedBox(height: 16),
        ],
        if (_error != null) ...<Widget>[
          QfBanner(message: _error!),
          const SizedBox(height: 16),
        ],
        Row(
          children: <Widget>[
            const Icon(
              Icons.mark_email_read_outlined,
              size: 15,
              color: AppColors.success,
            ),
            const SizedBox(width: 7),
            Expanded(
              child: Text(
                'Code sent to ${maskIdentifier(_reset.identifier)}',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        if (issued != null) ...<Widget>[
          _IssuedCodeCard(
            code: issued,
            onTap: () {
              _code.text = issued;
              _code.selection = TextSelection.collapsed(offset: issued.length);
              Clipboard.setData(ClipboardData(text: issued));
              FocusScope.of(context).unfocus();
              setState(() {});
            },
          ),
          const SizedBox(height: 10),
          Row(
            children: <Widget>[
              Icon(
                Icons.schedule_rounded,
                size: 13,
                color: Colors.white.withValues(alpha: 0.45),
              ),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  'Valid for 10 minutes, then it is discarded. One use only.',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.45),
                    fontSize: 11.5,
                    height: 1.4,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 22),
        ] else ...<Widget>[
          const QfBanner(
            message:
                'Check the inbox for the address above — the code was '
                'emailed to it, so it cannot be shown here.',
            icon: Icons.inbox_rounded,
            tone: QfBannerTone.info,
          ),
          const SizedBox(height: 22),
        ],
        const SectionTitle(text: '6-digit code'),
        QfField(
          controller: _code,
          label: 'Verification code',
          hint: '000000',
          icon: Icons.pin_rounded,
          keyboardType: TextInputType.number,
          inputFormatters: <TextInputFormatter>[
            FilteringTextInputFormatter.digitsOnly,
            LengthLimitingTextInputFormatter(6),
          ],
          maxLength: 6,
          centered: true,
          letterSpacing: 10,
          fontSize: 20,
          enabled: !busy,
        ),
        const SizedBox(height: 24),
        PrimaryButton(
          label: 'Verify code',
          icon: Icons.verified_user_rounded,
          busy: busy,
          onPressed: busy ? null : _verify,
        ),
        const SizedBox(height: 12),
        Center(
          child: TextButton(
            onPressed: busy ? null : _resend,
            child: Text(
              'Resend code',
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.60),
                fontSize: 12.5,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Shows an identifier the way the confirmation does: enough to recognise
/// which inbox to open, never enough to harvest.
///
/// An email address is masked to its first letter and domain. A Student ID
/// carries no inbox name — the code went to the registered address the admin
/// saved, which the server never returns — so a generic label is shown
/// instead of echoing the ID as if it were the destination.
String maskEmail(String? address) {
  final String value = (address ?? '').trim();

  if (!value.contains('@')) {
    return value.isEmpty ? 'your email address' : value;
  }

  final int at = value.indexOf('@');
  final String local = value.substring(0, at);
  final String domain = value.substring(at + 1);

  final String head = local.isEmpty ? '' : local[0];

  return '$head********@$domain';
}

/// Masks whatever the student typed on the previous step for the
/// confirmation line.
///
/// Email addresses are masked with [maskEmail]. Anything else (a Student ID)
/// becomes a generic label: the code was mailed to the registered address on
/// file, which this device was never told, so naming the ID would imply the
/// code went somewhere it did not.
String maskIdentifier(String? value) {
  final String trimmed = (value ?? '').trim();

  if (!trimmed.contains('@')) {
    return trimmed.isEmpty
        ? 'your registered email address'
        : 'your registered email address';
  }

  return maskEmail(trimmed);
}

/// The issued code, shown large enough to read at a glance.
///
/// Tapping it is the whole interaction: the parent fills the field from the
/// same string, so there is no way for the displayed code and the submitted
/// one to drift apart.
class _IssuedCodeCard extends StatelessWidget {
  const _IssuedCodeCard({required this.code, required this.onTap});

  final String code;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      pressedScale: 0.98,
      onTap: onTap,
      child: GlassCard(
        radius: 22,
        padding: const EdgeInsets.symmetric(vertical: 20, horizontal: 18),
        child: Row(
          children: <Widget>[
            const Icon(Icons.pin_rounded, size: 22, color: AppColors.blue),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    code,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 32,
                      fontWeight: FontWeight.w900,
                      letterSpacing: 10,
                      height: 1.1,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    'Tap to fill it in',
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.55),
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
