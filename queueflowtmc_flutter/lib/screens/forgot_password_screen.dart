import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/glass.dart';
import '../core/motion.dart';
import '../repositories/auth_repository.dart';
import '../repositories/password_reset_controller.dart';
import '../widgets/qf_page.dart';
import 'confirm_code_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — FORGOT PASSWORD (STEP 1: STUDENT ID OR EMAIL)
///
/// Collects the Student ID or email address on the student's account and
/// asks the server for a reset code. The server resolves the account from
/// it and mails the 6-digit code to the registered email address the admin
/// saved on that account — the typed value is never used as the destination.
/// On success the flow continues on the Confirm Code screen — this screen
/// never asks for, and never sees, the code itself.
///
/// The server answers generically either way, so this screen cannot be used
/// to probe which IDs hold accounts. An account with no (or an invalid)
/// registered email receives the same generic answer; the static help text
/// below tells every student to contact the administrator in that case.
/// ============================================================

class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final PasswordResetController _reset = PasswordResetController.instance;

  final TextEditingController _identifier = TextEditingController();

  String? _error;

  @override
  void initState() {
    super.initState();
    _reset.addListener(_onResetChanged);
  }

  @override
  void dispose() {
    _reset.removeListener(_onResetChanged);
    _identifier.dispose();
    super.dispose();
  }

  void _onResetChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _continue() async {
    FocusScope.of(context).unfocus();

    setState(() => _error = null);

    final String? failure = await _reset.requestCode(_identifier.text);

    if (!mounted) {
      return;
    }

    if (failure != null) {
      setState(() => _error = failure);
      return;
    }

    await Navigator.of(context).push<void>(
      QfPageRoute<void>(page: const ConfirmCodeScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    final bool busy = _reset.isBusy;
    final bool mailed = AuthRepository.instance.isRemote;

    return QfPage(
      title: 'Forgot password',
      subtitle: 'Step 1 of 3 — your Student ID',
      onBack: () => Navigator.of(context).maybePop(),
      children: <Widget>[
        if (_error != null) ...<Widget>[
          QfBanner(message: _error!),
          const SizedBox(height: 16),
        ],
        const SectionTitle(text: 'Student ID or email address'),
        QfField(
          controller: _identifier,
          label: 'Student ID or email address',
          hint: '24-019083 or you@example.com',
          icon: Icons.badge_outlined,
          keyboardType: TextInputType.text,
          inputFormatters: <TextInputFormatter>[
            FilteringTextInputFormatter.allow(RegExp(r'[A-Za-z0-9@._\-+ ]')),
          ],
          textCapitalization: TextCapitalization.none,
          enabled: !busy,
          autocorrect: false,
        ),
        const SizedBox(height: 14),
        QfBanner(
          message: mailed
              ? 'Enter the Student ID or email address on your student account. We will '
                    'email a 6-digit code to the registered email address your admin saved '
                    'for you. The code expires in '
                    '10 minutes and can only be used once. If no email arrives, ask your '
                    'administrator to check the email saved on your account.'
              : 'This build has no server, so nothing is emailed. It writes a '
                    '6-digit code on this device instead, and shows it on the '
                    'next step.',
          icon: mailed
              ? Icons.mark_email_read_outlined
              : Icons.phonelink_lock_outlined,
          tone: QfBannerTone.info,
        ),
        const SizedBox(height: 24),
        PrimaryButton(
          label: 'Continue',
          icon: Icons.arrow_forward_rounded,
          busy: busy,
          onPressed: busy ? null : _continue,
        ),
      ],
    );
  }
}
