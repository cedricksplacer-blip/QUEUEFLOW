import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/glass.dart';
import '../core/motion.dart';
import '../repositories/password_reset_controller.dart';
import '../widgets/qf_page.dart';

/// ============================================================
/// QUEUEFLOW TMC — ENTER NEW PASSWORD (STEP 3: NEW PASSWORD)
///
/// Opens only after the server verifies the confirmation code: without
/// the verification token this screen refuses to submit, so it cannot be
/// deep-linked past the code step. On success the token is spent, every
/// device session is revoked server-side, and the flow pops back to login.
/// ============================================================

class NewPasswordScreen extends StatefulWidget {
  const NewPasswordScreen({super.key});

  @override
  State<NewPasswordScreen> createState() => _NewPasswordScreenState();
}

class _NewPasswordScreenState extends State<NewPasswordScreen> {
  final PasswordResetController _reset = PasswordResetController.instance;

  final TextEditingController _password = TextEditingController();
  final TextEditingController _confirm = TextEditingController();

  bool _showPassword = false;
  bool _showConfirm = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _reset.addListener(_onResetChanged);
  }

  @override
  void dispose() {
    _reset.removeListener(_onResetChanged);
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  void _onResetChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();

    setState(() => _error = null);

    final String? failure = await _reset.submitNewPassword(
      password: _password.text,
      passwordConfirmation: _confirm.text,
    );

    if (!mounted) {
      return;
    }

    if (failure != null) {
      setState(() => _error = failure);
      return;
    }

    await _done();
  }

  Future<void> _done() async {
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext context) => AlertDialog(
        backgroundColor: AppColors.primary,
        title: const Text(
          'Password updated',
          style: TextStyle(color: Colors.white, fontSize: 17),
        ),
        content: Text(
          'Use your new password to sign in. Any other device signed in with '
          'the old password has been signed out.',
          style: TextStyle(
            color: Colors.white.withValues(alpha: 0.70),
            fontSize: 13,
            height: 1.5,
          ),
        ),
        actions: <Widget>[
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Back to sign in'),
          ),
        ],
      ),
    );

    if (mounted) {
      Navigator.of(context).popUntil((Route<dynamic> route) => route.isFirst);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bool busy = _reset.isBusy;
    final bool locked = !_reset.isVerified;

    return QfPage(
      title: 'New password',
      subtitle: 'Step 3 of 3 — choose a password',
      onBack: () => Navigator.of(context).maybePop(),
      children: <Widget>[
        if (locked) ...<Widget>[
          const QfBanner(
            message:
                'Verify your code first. This screen cannot set a password '
                'without it.',
          ),
          const SizedBox(height: 16),
        ],
        if (_error != null) ...<Widget>[
          QfBanner(message: _error!),
          const SizedBox(height: 16),
        ],
        const SectionTitle(text: 'New password'),
        QfField(
          controller: _password,
          label: 'New password',
          hint: 'At least 8 characters',
          icon: Icons.lock_outline_rounded,
          obscure: !_showPassword,
          enabled: !busy && !locked,
          suffix: _eye(
            visible: _showPassword,
            onTap: () => setState(() => _showPassword = !_showPassword),
          ),
        ),
        const SizedBox(height: 12),
        QfField(
          controller: _confirm,
          label: 'Confirm new password',
          hint: 'Type it again',
          icon: Icons.lock_reset_rounded,
          obscure: !_showConfirm,
          enabled: !busy && !locked,
          suffix: _eye(
            visible: _showConfirm,
            onTap: () => setState(() => _showConfirm = !_showConfirm),
          ),
        ),
        const SizedBox(height: 24),
        PrimaryButton(
          label: 'Reset password',
          icon: Icons.verified_user_rounded,
          busy: busy,
          onPressed: (busy || locked) ? null : _submit,
        ),
      ],
    );
  }

  Widget _eye({required bool visible, required VoidCallback onTap}) {
    return PressScale(
      pressedScale: 0.85,
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Icon(
          visible ? Icons.visibility_off_outlined : Icons.visibility_outlined,
          size: 19,
          color: Colors.white.withValues(alpha: 0.55),
        ),
      ),
    );
  }
}
